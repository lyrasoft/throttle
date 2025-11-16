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
use Windwalker\Core\Application\ApplicationInterface;
use Windwalker\Core\Application\Context\AppRequestInterface;

class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected ApplicationInterface $app,
        protected string|RateLimiterFactoryInterface|null $factory = null,
        protected \Closure|string|null $limiterKey,
        protected \Closure|int $consume = 1,
        protected ?\Closure $exceededHandler = null,
        protected \Closure|bool|null $infoHeaders = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
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
}
