<?php
namespace Azura\Middleware;

use Azura\Http\RequestHelper;
use Azura\Http\RouterInterface;
use Azura\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Set the current route on the URL object, and inject the URL object into the router.
 */
class InjectRouter implements MiddlewareInterface
{
    /** @var RouterInterface */
    protected $router;

    public function __construct(RouterInterface $router)
    {
        $this->router = $router;
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->router->setCurrentRequest($request);

        $request = $request->withAttribute(ServerRequest::ATTR_ROUTER, $this->router);

        return $handler->handle($request);
    }
}