<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\Exception\RateLimitExceededException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Windwalker\Core\Application\AppContext;
use Windwalker\Core\Application\Context\AppRequestInterface;
use Windwalker\Core\Middleware\RoutingExcludesTrait;

class RateLimitMiddleware implements MiddlewareInterface
{
    use RoutingExcludesTrait;

    public function __construct(
        protected AppContext $app,
        protected \Closure|string|null $limiterKey,
        protected string|RateLimiterFactoryInterface|null $factory = null,
        protected \Closure|int $consume = 1,
        protected ?\Closure $exceededHandler = null,
        protected \Closure|bool|null $infoHeaders = null,
        protected array|string|null $methods = null,
        protected \Closure|array|null $excludes = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isExclude()) {
            return $handler->handle($request);
        }

        $methods = $this->methods;

        if ($methods !== null) {
            $methods = array_map('strtoupper', (array) $methods);

            if (!in_array(strtoupper($this->app->getAppRequest()->getMethod()), $methods, true)) {
                return $handler->handle($request);
            }
        }

        $factory = $this->getRateLimiterFactory();
        $rateLimiter = $factory->create($key = $this->getRateLimiterKey());

        $limit = $this->consume($rateLimiter, $key);

        if (!$limit->isAccepted()) {
            if ($this->exceededHandler) {
                $res = $this->app->call($this->exceededHandler);

                if ($res instanceof ResponseInterface) {
                    return $this->handleResponse($res, $limit);
                }
            }

            throw new RateLimitExceededException($limit, 429);
        }

        return $handler->handle($request);
    }

    public function handleResponse(ResponseInterface $res, RateLimit $limit): mixed
    {
        if ($this->infoHeaders instanceof \Closure) {
            return $this->app->call(
                $this->infoHeaders,
                [
                    'response' => $res,
                    ResponseInterface::class => $res,
                    'limit' => $limit,
                    RateLimit::class => $limit,
                ]
            );
        }

        if ($this->infoHeaders) {
            $res = $res->withAddedHeader('X-RateLimit-Limit', (string) $limit->getLimit())
                ->withAddedHeader('X-RateLimit-Remaining', (string) max(0, $limit->getRemainingTokens()))
                ->withAddedHeader('X-RateLimit-Reset', (string) $limit->getRetryAfter()->getTimestamp());
        }

        return $res;
    }

    protected function getConsume(string $key): int
    {
        if ($this->consume instanceof \Closure) {
            return (int) $this->app->call($this->consume);
        }

        return $this->consume;
    }

    protected function consume(LimiterInterface $rateLimiter, string $key): RateLimit
    {
        if (is_int($this->consume)) {
            return $rateLimiter->consume($this->consume);
        }

        return $this->app->call(
            $this->consume,
            [
                'key' => $key,
                'rateLimiter' => $rateLimiter,
                LimiterInterface::class => $rateLimiter,
            ]
        );
    }

    protected function getRateLimiterKey(): string
    {
        if (is_string($this->limiterKey)) {
            return $this->limiterKey;
        }

        if ($this->limiterKey instanceof \Closure) {
            return $this->app->call($this->limiterKey);
        }

        return $this->app->retrieve(AppRequestInterface::class)->getClientIP();
    }

    protected function getRateLimiterFactory(): RateLimiterFactoryInterface
    {
        if ($this->factory instanceof RateLimiterFactoryInterface) {
            return $this->factory;
        }

        return $this->app->retrieve(
            RateLimiterFactoryInterface::class,
            tag: $this->factory
        );
    }

    public function getExcludes(): mixed
    {
        return $this->excludes;
    }
}
