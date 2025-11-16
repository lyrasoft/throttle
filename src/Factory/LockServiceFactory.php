<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Factory;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Windwalker\Cache\CachePool;
use Windwalker\Core\DI\ServiceFactoryInterface;
use Windwalker\Core\DI\ServiceFactoryTrait;

use Windwalker\DI\Container;

use Windwalker\DI\Definition\ObjectBuilderDefinition;

use function Windwalker\DI\create;

class LockServiceFactory implements ServiceFactoryInterface
{
    use ServiceFactoryTrait;

    public function getConfigPrefix(): string
    {
        return 'throttle';
    }

    public function getClassName(): ?string
    {
        return LockFactory::class;
    }

    public function getDefaultName(): ?string
    {
        return $this->config->getDeep('default_lock');
    }

    public static function factory(?string $storage = null): ObjectBuilderDefinition
    {
        return create(
            function (Container $container) use ($storage) {
                return new LockFactory(
                    $container->get(PersistingStoreInterface::class, tag: $storage),
                );
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
