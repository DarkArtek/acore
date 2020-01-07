<?php
namespace Azura\Middleware;

use Azura\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Inject core services into the request object for use further down the stack.
 */
class InjectRateLimit implements MiddlewareInterface
{
    /** @var \Azura\RateLimit */
    protected $rateLimit;

    public function __construct(\Azura\RateLimit $rateLimit)
    {
        $this->rateLimit = $rateLimit;
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $request->withAttribute(ServerRequest::ATTR_RATE_LIMIT, $this->rateLimit);

        return $handler->handle($request);
    }
}