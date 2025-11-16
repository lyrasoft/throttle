<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle;

use Lyrasoft\Throttle\Factory\LockServiceFactory;
use Lyrasoft\Throttle\Factory\RateLimiterServiceFactory;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Windwalker\Core\DI\TaggingFactory;
use Windwalker\Core\Package\AbstractPackage;
use Windwalker\Core\Package\PackageInstaller;
use Windwalker\DI\Container;
use Windwalker\DI\ServiceProviderInterface;

class ThrottlePackage extends AbstractPackage implements ServiceProviderInterface
{
    public function install(PackageInstaller $installer): void
    {
        //
    }

    public function register(Container $container): void
    {
        // Lock
        $container->prepareSharedObject(LockServiceFactory::class);
        $container->share(
            LockFactory::class,
            fn (Container $container, ?string $tag = null)
                => $container->get(LockServiceFactory::class)->get($tag)
        );
        $container->share(
            PersistingStoreInterface::class,
            fn (TaggingFactory $factory, ?string $tag = null) => $factory->useConfig('throttle')
                ->id(PersistingStoreInterface::class)
                ->get($tag ?? 'default')
        );

        // RateLimiter
        $container->prepareSharedObject(RateLimiterServiceFactory::class);
        $container->share(
            RateLimiterFactory::class,
            fn (Container $container, ?string $tag = null)
                => $container->get(RateLimiterServiceFactory::class)->get($tag)
        );
        $container->share(
            RateLimiterFactoryInterface::class,
            fn (Container $container, ?string $tag = null)
                => $container->get(RateLimiterServiceFactory::class)->get($tag)
        );

        $container->share(
            StorageInterface::class,
            fn (TaggingFactory $factory, ?string $tag = null) => $factory->useConfig('throttle')
                    ->id(StorageInterface::class)
                    ->get($tag ?? 'default')
        );
    }
}
