<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Service;

use Lyrasoft\Throttle\Enum\RateLimitPolicy;
use Lyrasoft\Throttle\Lock\LockDbStore;
use Lyrasoft\Throttle\RateLimiter\RateLimitDbStorage;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Policy\Rate;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Windwalker\Core\Application\ApplicationInterface;
use Windwalker\DI\Attributes\Service;

#[Service]
class ThrottleService
{
    public function __construct(protected ApplicationInterface $app)
    {
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
     * @return  array{ SharedLockInterface, Key }
     */
    public function concurrentBlocking(
        string $id,
        int $concurrent = 1,
        ?float $ttl = 300.0,
        bool $autoRelease = true,
        ?PersistingStoreInterface $store = null
    ): ?array {
        $i = random_int(1, $concurrent);

        $key = new Key($id . '@' . $i);
        $lock = $this->createLockFromKey($key, $ttl, $autoRelease, $store);

        $lock->acquire(true);

        return [$lock, $key];
    }

    /**
     * @param  string                         $id
     * @param  int                            $concurrent
     * @param  float|null                     $ttl
     * @param  bool                           $autoRelease
     * @param  PersistingStoreInterface|null  $store
     *
     * @return  array{ SharedLockInterface, Key }|null
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
                return [$lock, $key];
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
        return new LockFactory($store ?? $this->createLockDbStore());
    }

    public function createLockDbStore(): LockDbStore
    {
        return $this->app->make(LockDbStore::class);
    }

    public function createRateLimiter(
        string $id,
        RateLimitPolicy $policy,
        int $limit,
        string|Rate $interval,
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
        string|Rate $interval,
        LockFactory|true|null $lockFactory = null,
        ?StorageInterface $storage = null,
    ): RateLimiterFactory {
        if ($lockFactory === true) {
            $lockFactory = $this->createLockFactory();
        }

        return new RateLimiterFactory(
            [
                'id' => $id,
                'policy' => $policy->value,
                'limit' => $limit,
                'interval' => $interval,
            ],
            $storage ?? $this->createRateLimiterDbStorage(),
            $lockFactory
        );
    }

    public function createRateLimiterDbStorage(): RateLimitDbStorage
    {
        return $this->app->make(RateLimitDbStorage::class);
    }
}
