<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Factory;

use Lyrasoft\Throttle\Enum\RateLimitPolicy;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\RateLimiter\CompoundRateLimiterFactory;
use Symfony\Component\RateLimiter\Policy\Rate;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Windwalker\Cache\CachePool;
use Windwalker\Core\DI\ServiceFactoryInterface;
use Windwalker\Core\DI\ServiceFactoryTrait;
use Windwalker\DI\Container;
use Windwalker\DI\Definition\ObjectBuilderDefinition;

use function Windwalker\DI\create;

class RateLimiterServiceFactory implements ServiceFactoryInterface
{
    use ServiceFactoryTrait;

    public function getConfigPrefix(): string
    {
        return 'throttle';
    }

    public function getClassName(): ?string
    {
        return RateLimiterFactoryInterface::class;
    }

    public function getDefaultName(): ?string
    {
        return $this->config->getDeep('default_rate_limiter');
    }

    public static function factory(
        string $id,
        RateLimitPolicy $policy,
        int $limit,
        string|Rate $interval,
        string|bool|null $locker = null,
        ?string $storage = null,
    ): ObjectBuilderDefinition {
        return create(
            function (Container $container) use ($id, $policy, $limit, $interval, $storage, $locker) {
                $lockFactory = null;

                if (is_string($locker)) {
                    if ($locker === true) {
                        $locker = null;
                    }

                    $lockFactory = $container->get(PersistingStoreInterface::class, tag: $locker);
                }

                return new RateLimiterFactory(
                    [
                        'id' => $id,
                        'policy' => $policy->value,
                        'limit' => $limit,
                        'interval' => $interval,
                    ],
                    $container->get(StorageInterface::class, tag: $storage),
                    $lockFactory,
                );
            }
        );
    }

    public static function compoundFactory(array $limiters): ObjectBuilderDefinition
    {
        return create(
            function (Container $container) use ($limiters) {
                $limiters = array_map(
                    fn ($tag) => $container->get(RateLimiterFactoryInterface::class, tag: $tag),
                    $limiters
                );

                return new CompoundRateLimiterFactory($limiters);
            }
        );
    }

    public static function cacheStorage(?string $cacheTag = null): ObjectBuilderDefinition
    {
        return create(
            fn(Container $container) => new CacheStorage(
                $container->get(CachePool::class, tag: $cacheTag)
            )
        );
    }
}
