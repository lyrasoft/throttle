<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Factory;

use Lyrasoft\Throttle\Enum\RateLimitPolicy;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Policy\FixedWindowLimiter;
use Symfony\Component\RateLimiter\Policy\NoLimiter;
use Symfony\Component\RateLimiter\Policy\Rate;
use Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter;
use Symfony\Component\RateLimiter\Policy\TokenBucketLimiter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * @internal
 */
class RateLimiterFactory implements RateLimiterFactoryInterface
{
    public function __construct(
        protected string $id,
        protected RateLimitPolicy $policy,
        protected int $limit,
        protected string|Rate $interval,
        protected LockFactory|true|null $lockFactory = null,
        protected ?StorageInterface $storage = null,
    ) {
    }

    public function create(?string $key = null): LimiterInterface
    {
        $id = $this->id . '-' . $key;
        $lock = $this->lockFactory?->createLock($id);

        $interval = $this->interval;

        if (is_string($interval)) {
            $interval = \DateInterval::createFromDateString($interval);
        }

        return match ($this->policy) {
            RateLimitPolicy::TOKEN_BUCKET => new TokenBucketLimiter($id, $this->limit, $interval, $this->storage, $lock),
            RateLimitPolicy::FIXED_WINDOW => new FixedWindowLimiter($id, $this->limit, $interval, $this->storage, $lock),
            RateLimitPolicy::SLIDING_WINDOW => new SlidingWindowLimiter(
                $id,
                $this->limit,
                $interval,
                $this->storage,
                $lock
            ),
            RateLimitPolicy::NO_LIMIT => new NoLimiter(),
            default => throw new \LogicException(
                \sprintf(
                    'Limiter policy "%s" does not exists, it must be either "token_bucket", "sliding_window", "fixed_window" or "no_limit".',
                    $this->policy->name
                )
            ),
        };
    }
}
