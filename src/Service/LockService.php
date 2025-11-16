<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Service;

use Lyrasoft\Throttle\Lock\LockDbStore;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Windwalker\Core\Application\ApplicationInterface;
use Windwalker\Core\Database\ORMAwareTrait;
use Windwalker\DI\Attributes\Service;

#[Service]
class LockService
{
    use ORMAwareTrait;

    public function __construct(protected ApplicationInterface $app)
    {
    }

    public function lock(string|Key $id, ?float $ttl = 300.0, bool $autoRelease = true): ?SharedLockInterface
    {
        $lock = $id instanceof Key
            ? $this->createLockFromKey($id, $ttl, $autoRelease)
            : $this->createLock($id, $ttl, $autoRelease);

        if ($lock->acquire()) {
            return $lock;
        }

        return null;
    }

    /**
     * @param  string      $id
     * @param  int         $concurrent
     * @param  float|null  $ttl
     * @param  bool        $autoRelease
     *
     * @return  array{ SharedLockInterface, Key }|null
     */
    public function concurrent(
        string $id,
        int $concurrent = 1,
        ?float $ttl = 300.0,
        bool $autoRelease = true
    ): ?array {
        foreach (range(1, $concurrent) as $i) {
            $key = new Key($id . '@' . $i);
            $lock = $this->createLockFromKey($key, $ttl, $autoRelease);

            if ($lock->acquire()) {
                return [$lock, $key];
            }
        }

        return null;
    }

    public function createLock(string $id, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        return $this->createFactory()->createLock($id, $ttl, $autoRelease);
    }

    public function createLockFromKey(Key $key, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        return $this->createFactory()->createLockFromKey($key, $ttl, $autoRelease);
    }

    public function createFactory(): LockFactory
    {
        return new LockFactory($this->createStore());
    }

    public function createStore()
    {
        // $pdo = null;
        //
        // $this->orm->getDb()->getDriver()->useConnection(
        //     function ($connection) use (&$pdo) {
        //         $pdo = $connection->get();
        //     }
        // );
        //
        // return new PdoStore($pdo, options: ['db_table' => 'locks']);

        return $this->app->make(LockDbStore::class);
    }
}
