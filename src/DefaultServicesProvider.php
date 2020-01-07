<?php
namespace Azura;

use Azura\Http\RouterInterface;
use Azura\Middleware;
use Cache\Adapter\PHPArray\ArrayCachePool;
use Cache\Adapter\Redis\RedisCachePool;
use Cache\Bridge\Doctrine\DoctrineCacheBridge;
use Cache\Prefixed\PrefixedCachePool;
use Composer\CaBundle\CaBundle;
use DI;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\Reader as AnnotationReader;
use Doctrine\Common\Cache\Cache as DoctrineCache;
use Doctrine\Common\EventManager;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\EchoSQLLogger;
use Doctrine\DBAL\Platforms\MariaDb1027Platform;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\RequestOptions;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\SimpleCache\CacheInterface;
use Redis;
use Slim\Interfaces\ErrorHandlerInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader;
use Symfony\Component\Serializer\Normalizer\JsonSerializableNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ConstraintValidatorFactoryInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ValidatorBuilder;
use Zend\Expressive\Session\Cache\CacheSessionPersistence;
use Zend\Expressive\Session\SessionPersistenceInterface;
use function DI\autowire;

/**
 * Slim's default Service Provider.
 */
class DefaultServicesProvider
{
    public static function getDefaultServices(): array
    {
        return [
            // URL Router helper
            Http\Router::class => function (\Slim\App $app, Settings $settings) {
                $route_parser = $app->getRouteCollector()->getRouteParser();
                return new Http\Router($settings, $route_parser);
            },
            Http\RouterInterface::class => DI\Get(Http\Router::class),

            // Error handler
            Http\ErrorHandler::class => autowire(),
            ErrorHandlerInterface::class => DI\Get(Http\ErrorHandler::class),

            // HTTP client
            Client::class => function (LoggerInterface $logger) {
                $stack = HandlerStack::create();

                $stack->unshift(function (callable $handler) {
                    return function (RequestInterface $request, array $options) use ($handler) {
                        $options[RequestOptions::VERIFY] = CaBundle::getSystemCaRootBundlePath();
                        return $handler($request, $options);
                    };
                }, 'ssl_verify');

                $stack->push(GuzzleMiddleware::log(
                    $logger,
                    new MessageFormatter('HTTP client {method} call to {uri} produced response {code}'),
                    Logger::DEBUG
                ));

                return new Client([
                    'handler' => $stack,
                    RequestOptions::HTTP_ERRORS => false,
                    RequestOptions::TIMEOUT => 3.0,
                ]);
            },

            // Cache
            CacheItemPoolInterface::class => function (Settings $settings, ContainerInterface $di) {
                // Never use the Redis cache for CLI commands, as the CLI commands are where
                // the Redis cache gets flushed, so this will lead to a race condition that can't
                // be solved within the application.
                return $settings->enableRedis() && !$settings->isCli()
                    ? new RedisCachePool($di->get(Redis::class))
                    : new ArrayCachePool;
            },
            CacheInterface::class => DI\get(CacheItemPoolInterface::class),

            // Configuration management
            Config::class => function (Settings $settings) {
                return new Config($settings[Settings::CONFIG_DIR]);
            },

            // DBAL
            Connection::class => function (EntityManager $em) {
                return $em->getConnection();
            },
            'db' => DI\Get(Connection::class),

            // Console
            Console\Application::class => function (DI\Container $di, EventDispatcher $dispatcher) {
                $console = new Console\Application('Command Line Interface', '1.0.0', $di);

                // Trigger an event for the core app and all plugins to build their CLI commands.
                $event = new Event\BuildConsoleCommands($console);
                $dispatcher->dispatch($event);

                return $console;
            },

            // Doctrine cache
            DoctrineCache::class => function (CacheItemPoolInterface $cachePool) {
                return new DoctrineCacheBridge(new PrefixedCachePool($cachePool, 'doctrine|'));
            },

            // Doctrine Entity Manager
            EntityManager::class => function (
                DoctrineCache $doctrine_cache,
                AnnotationReader $annotationReader,
                Settings $settings
            ) {
                $defaults = [
                    'cache' => $doctrine_cache,
                    'autoGenerateProxies' => !$settings->isProduction(),
                    'proxyNamespace' => 'AppProxy',
                    'proxyPath' => $settings->getTempDirectory() . '/proxies',
                    'modelPath' => $settings->getBaseDirectory() . '/src/Entity',
                    'useSimpleAnnotations' => false,
                    'conn' => [
                        'host' => $_ENV['MYSQL_HOST'] ?? 'mariadb',
                        'port' => $_ENV['MYSQL_PORT'] ?? 3306,
                        'dbname' => $_ENV['MYSQL_DATABASE'],
                        'user' => $_ENV['MYSQL_USER'],
                        'password' => $_ENV['MYSQL_PASSWORD'],
                        'driver' => 'pdo_mysql',
                        'charset' => 'utf8mb4',
                        'defaultTableOptions' => [
                            'charset' => 'utf8mb4',
                            'collate' => 'utf8mb4_general_ci',
                        ],
                        'driverOptions' => [
                            // PDO::MYSQL_ATTR_INIT_COMMAND = 1002;
                            1002 => 'SET NAMES utf8mb4 COLLATE utf8mb4_general_ci',
                        ],
                        'platform' => new MariaDb1027Platform(),
                    ],
                ];

                if (!$settings[Settings::IS_DOCKER]) {
                    $defaults['conn']['host'] = $_ENV['db_host'] ?? 'localhost';
                    $defaults['conn']['port'] = $_ENV['db_port'] ?? '3306';
                    $defaults['conn']['dbname'] = $_ENV['db_name'] ?? 'azuracast';
                    $defaults['conn']['user'] = $_ENV['db_username'] ?? 'azuracast';
                    $defaults['conn']['password'] = $_ENV['db_password'];
                }

                $app_options = $settings[Settings::DOCTRINE_OPTIONS] ?? [];
                $options = array_merge($defaults, $app_options);

                try {
                    // Fetch and store entity manager.
                    $config = new Configuration;

                    if ($options['useSimpleAnnotations']) {
                        $metadata_driver = $config->newDefaultAnnotationDriver((array)$options['modelPath'],
                            $options['useSimpleAnnotations']);
                    } else {
                        $metadata_driver = new AnnotationDriver(
                            $annotationReader,
                            (array)$options['modelPath']
                        );
                    }
                    $config->setMetadataDriverImpl($metadata_driver);

                    $config->setMetadataCacheImpl($options['cache']);
                    $config->setQueryCacheImpl($options['cache']);
                    $config->setResultCacheImpl($options['cache']);

                    $config->setProxyDir($options['proxyPath']);
                    $config->setProxyNamespace($options['proxyNamespace']);
                    $config->setAutoGenerateProxyClasses(AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS);

                    if (isset($options['conn']['debug']) && $options['conn']['debug']) {
                        $config->setSQLLogger(new EchoSQLLogger);
                    }

                    $config->addCustomNumericFunction('RAND', Doctrine\Functions\Rand::class);

                    return EntityManager::create($options['conn'], $config, new EventManager);
                } catch (\Exception $e) {
                    throw new Exception\BootstrapException($e->getMessage());
                }
            },
            'em' => DI\Get(EntityManager::class),

            // Event Dispatcher
            EventDispatcher::class => function (\Slim\App $app, Settings $settings) {
                $dispatcher = new EventDispatcher($app->getCallableResolver());
                $dispatcher->addSubscriber(new DefaultEventHandler);

                // Register application default events.
                if (file_exists($settings[Settings::CONFIG_DIR] . '/events.php')) {
                    call_user_func(include($settings[Settings::CONFIG_DIR] . '/events.php'), $dispatcher);
                }

                return $dispatcher;
            },

            // Monolog Logger
            Logger::class => function (Settings $settings) {
                $logger = new Logger($settings[Settings::APP_NAME] ?? 'app');
                $logging_level = $settings->isProduction() ? LogLevel::INFO : LogLevel::DEBUG;

                if ($settings[Settings::IS_DOCKER] || $settings[Settings::IS_CLI]) {
                    $log_stderr = new StreamHandler('php://stderr', $logging_level, true);
                    $logger->pushHandler($log_stderr);
                }

                $log_file = new StreamHandler($settings[Settings::TEMP_DIR] . '/app.log',
                    $logging_level, true);
                $logger->pushHandler($log_file);

                return $logger;
            },
            LoggerInterface::class => DI\get(Logger::class),

            // Middleware
            Middleware\InjectRateLimit::class => autowire(),
            Middleware\InjectRouter::class => autowire(),
            Middleware\InjectSession::class => autowire(),
            Middleware\EnableView::class => autowire(),

            // Session save handler middleware
            SessionPersistenceInterface::class => function (RedisCachePool $redisPool) {
                return new CacheSessionPersistence(
                    new PrefixedCachePool($redisPool, 'session|'),
                    'azura_session',
                    '/',
                    'nocache',
                    43200,
                    time()
                );
            },

            // Rate limiter
            RateLimit::class => autowire(),

            // Redis cache
            Redis::class => function (Settings $settings) {
                $redis_host = $settings[Settings::IS_DOCKER] ? 'redis' : 'localhost';

                $redis = new Redis();
                $redis->connect($redis_host, 6379, 15);
                $redis->select(1);

                return $redis;
            },

            // View (Plates Templates)
            View::class => function (
                ContainerInterface $di,
                Settings $settings,
                RouterInterface $router,
                EventDispatcher $dispatcher
            ) {
                $view = new View($settings[Settings::VIEWS_DIR], 'phtml');

                $view->registerFunction('service', function ($service) use ($di) {
                    return $di->get($service);
                });

                $view->registerFunction('escapeJs', function ($string) {
                    return json_encode($string);
                });

                $view->addData([
                    'settings' => $settings,
                    'router' => $router,
                ]);

                $dispatcher->dispatch(new Event\BuildView($view));

                return $view;
            },

            // Doctrine annotations reader
            AnnotationReader::class => function (DoctrineCache $doctrine_cache, Settings $settings) {
                return new CachedReader(
                    new \Doctrine\Common\Annotations\AnnotationReader,
                    $doctrine_cache,
                    !$settings->isProduction()
                );
            },

            // Symfony Serializer
            Serializer::class => function (
                AnnotationReader $annotation_reader,
                EntityManager $em
            ) {
                $meta_factory = new ClassMetadataFactory(
                    new AnnotationLoader($annotation_reader)
                );

                $normalizers = [
                    new JsonSerializableNormalizer(),
                    new Normalizer\DoctrineEntityNormalizer($em, $annotation_reader, $meta_factory),
                    new ObjectNormalizer($meta_factory),
                ];
                return new Serializer($normalizers);
            },

            // Symfony Validator
            ConstraintValidatorFactoryInterface::class => autowire(Validator\ConstraintValidatorFactory::class),

            ValidatorInterface::class => function (
                AnnotationReader $annotation_reader,
                ConstraintValidatorFactoryInterface $cvf
            ) {
                $builder = new ValidatorBuilder();
                $builder->setConstraintValidatorFactory($cvf);
                $builder->enableAnnotationMapping($annotation_reader);
                return $builder->getValidator();
            },
        ];
    }
}