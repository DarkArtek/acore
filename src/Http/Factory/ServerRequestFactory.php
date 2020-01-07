<?php
namespace Azura\Http\Factory;

use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\ServerRequestCreatorInterface;

class ServerRequestFactory extends \Http\Factory\Guzzle\ServerRequestFactory implements ServerRequestCreatorInterface
{
    protected static $serverRequestClass = \Azura\Http\ServerRequest::class;

    public static function setServerRequestClass(string $class): void
    {
        self::$serverRequestClass = $class;
    }

    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        $serverRequest = parent::createServerRequest($method, $uri, $serverParams);
        return $this->decorateServerRequest($serverRequest);
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ServerRequestInterface
     */
    public function decorateServerRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        return new self::$serverRequestClass($request);
    }

    /**
     * @return ServerRequestInterface
     */
    public function createServerRequestFromGlobals(): ServerRequestInterface
    {
        return $this->decorateServerRequest(ServerRequest::fromGlobals());
    }
}