<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Service;

use Lyrasoft\Throttle\Enum\RateLimitPolicy;
use Lyrasoft\Throttle\Factory\RateLimiterFactory;
use Lyrasoft\Throttle\Lock\LockDbStore;
use Lyrasoft\Throttle\RateLimiter\RateLimitDbStorage;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\RateLimiter\CompoundRateLimiterFactory;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Policy\Rate;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Windwalker\Core\Application\ApplicationInterface;
use Windwalker\DI\Attributes\Service;

#[Service]
class ThrottleService
{
    public ?\Closure $defaultRateLimiterStorage = null;

    public ?\Closure $defaultLockStore = null;

    public function __construct(protected ApplicationInterface $app)
    {
    }

    public static function createLockKey(string $resource): Key
    {
        return new Key($resource);
    }

    public function lock(
        string|Key $id,
        ?float $ttl = 300.0,
        bool $autoRelease = true,
        ?PersistingStoreInterface $store = null
    ): ?SharedLockInterface {
        $lock = $id instanceof Key
            ? $this->createLockFromKey($id, $ttl, $autoRelease, $store)
            : $this->createLock($id, $ttl, $autoRelease, $store);

        if ($lock->acquire()) {
            return $lock;
        }

        return null;
    }

    /**
     * @param  string                         $id
     * @param  int                            $concurrent
     * @param  float|null                     $ttl
     * @param  bool                           $autoRelease
     * @param  PersistingStoreInterface|null  $store
     *
     * @return  array{ SharedLockInterface, Key, int }|null
     */
    public function concurrent(
        string $id,
        int $concurrent = 1,
        ?float $ttl = 300.0,
        bool $autoRelease = true,
        ?PersistingStoreInterface $store = null
    ): ?array {
        foreach (range(1, $concurrent) as $i) {
            $key = new Key($id . '@' . $i);
            $lock = $this->createLockFromKey($key, $ttl, $autoRelease, $store);

            if ($lock->acquire()) {
                return [$lock, $key, $i];
            }
        }

        return null;
    }

    public function createLock(
        string $resource,
        ?float $ttl = 300.0,
        bool $autoRelease = true,
        ?PersistingStoreInterface $store = null
    ): SharedLockInterface {
        return $this->createLockFactory($store)->createLock($resource, $ttl, $autoRelease);
    }

    public function createLockFromKey(
        Key $key,
        ?float $ttl = 300.0,
        bool $autoRelease = true,
        ?PersistingStoreInterface $store = null
    ): SharedLockInterface {
        return $this->createLockFactory($store)->createLockFromKey($key, $ttl, $autoRelease);
    }

    public function createLockFactory(?PersistingStoreInterface $store = null): LockFactory
    {
        return new LockFactory($store ?? $this->getLockPersistingStore());
    }

    public function getLockPersistingStore(): PersistingStoreInterface
    {
        if ($this->defaultLockStore) {
            return ($this->defaultLockStore)();
        }

        return $this->createLockDbStore();
    }

    public function createLockDbStore(): LockDbStore
    {
        return $this->app->make(LockDbStore::class);
    }

    public function createRateLimiter(
        string $id,
        RateLimitPolicy $policy,
        int $limit,
        string|\DateInterval|Rate $interval,
        LockFactory|true|null $lockFactory = null,
        ?StorageInterface $storage = null,
    ): LimiterInterface {
        [$id, $key] = explode('::', $id, 2) + ['', ''];

        return $this->createRateLimiterFactory(
            $id,
            $policy,
            $limit,
            $interval,
            $storage,
            $lockFactory
        )->create($key);
    }

    public function createRateLimiterFactory(
        string $id,
        RateLimitPolicy $policy,
        int $limit,
        string|\DateInterval|Rate $interval,
        LockFactory|true|null $lockFactory = null,
        ?StorageInterface $storage = null,
    ): RateLimiterFactoryInterface {
        if ($lockFactory === true) {
            $lockFactory = $this->createLockFactory();
        }

        return new RateLimiterFactory(
            $id,
            $policy,
            $limit,
            $interval,
            $lockFactory,
            $storage ?? $this->getRateLimiterStorage(),
        );
    }

    /**
     * @param  iterable<RateLimiterFactoryInterface>  $limiterFactories
     *
     * @return  CompoundRateLimiterFactory
     */
    public function createCompoundRateLimiterFactory(iterable $limiterFactories): CompoundRateLimiterFactory
    {
        return new CompoundRateLimiterFactory($limiterFactories);
    }

    /**
     * @param  iterable<RateLimiterFactoryInterface>  $limiterFactories
     * @param  string|null                            $key
     *
     * @return  LimiterInterface
     */
    public function createCompoundRateLimiter(iterable $limiterFactories, ?string $key = null): LimiterInterface
    {
        return $this->createCompoundRateLimiterFactory($limiterFactories)->create($key);
    }

    protected function getRateLimiterStorage(): StorageInterface
    {
        if ($this->defaultRateLimiterStorage) {
            return ($this->defaultRateLimiterStorage)();
        }

        return $this->createRateLimiterDbStorage();
    }

    public function createRateLimiterDbStorage(): RateLimitDbStorage
    {
        return $this->app->make(RateLimitDbStorage::class);
    }

    public static function rate(string|int|\DateInterval $interval, int $amount = 1): Rate
    {
        return \Lyrasoft\Throttle\rate($interval, $amount);
    }
}
