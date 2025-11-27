<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Lock;

use Lyrasoft\Throttle\Entity\LockKey;
use Random\RandomException;
use Symfony\Component\Lock\Exception\InvalidArgumentException;
use Symfony\Component\Lock\Exception\InvalidTtlException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\ExpiringStoreTrait;
use Windwalker\Database\DatabaseAdapter;
use Windwalker\Database\Exception\DatabaseQueryException;
use Windwalker\ORM\ORM;
use Windwalker\Query\Query;

class LockDbStore implements PersistingStoreInterface
{
    use ExpiringStoreTrait;

    protected ORM $orm {
        get => $this->db->orm();
    }

    public function __construct(
        #[\SensitiveParameter]
        protected DatabaseAdapter $db,
        protected float $gcProbability = 0.01,
        protected int $initialTtl = 300
    ) {
        if ($gcProbability < 0 || $gcProbability > 1) {
            throw new InvalidArgumentException(
                \sprintf(
                    '"%s" requires gcProbability between 0 and 1, "%f" given.',
                    __METHOD__,
                    $gcProbability
                )
            );
        }

        if ($initialTtl < 1) {
            throw new InvalidTtlException(
                \sprintf(
                    '"%s()" expects a strictly positive TTL, "%d" given.',
                    __METHOD__,
                    $initialTtl
                )
            );
        }
    }

    public function save(Key $key): void
    {
        $key->reduceLifetime($this->initialTtl);

        $item = new LockKey();
        $item->key = $this->getHashedKey($key);
        $item->token = $this->getUniqueToken($key);
        $item->expiration = time();

        $this->orm->transaction(
            function () use ($key, $item) {
                $exists = $this->orm->from(LockKey::class)
                    ->where('key', $item->key)
                    ->forUpdate()
                    ->get();

                if (!$exists) {
                    $this->orm->createOne($item);
                } else {
                    $this->putOffExpiration($key, $this->initialTtl);
                }
            }
        );

        $this->randomlyPrune();
        $this->checkNotExpired($key);
    }

    public function delete(Key $key): void
    {
        $this->orm->delete(LockKey::class)
            ->where('key', $this->getHashedKey($key))
            ->where('token', $this->getUniqueToken($key))
            ->execute();
    }

    public function exists(Key $key): bool
    {
        $exists = $this->orm->from(LockKey::class)
            ->where('key', $this->getHashedKey($key))
            ->where('token', $this->getUniqueToken($key))
            ->where('expiration', '>', time())
            ->get();

        return (bool) $exists;
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        if ($ttl < 1) {
            throw new InvalidTtlException(
                \sprintf(
                    '"%s()" expects a TTL greater or equals to 1 second. Got "%s".',
                    __METHOD__,
                    $ttl
                )
            );
        }

        $key->reduceLifetime($ttl);

        $now = time();
        $token = $this->getUniqueToken($key);

        $exists = $this->orm->update(LockKey::class)
            ->set('expiration', $now + $ttl)
            ->set('token', $token)
            ->where('key', $this->getHashedKey($key))
            ->orWhere(
                function (Query $query) use ($now, $token) {
                    $query->where('token', $token)
                        ->where('expiration', '<=', $now);
                }
            )
            ->get();

        if (!$exists && !$this->exists($key)) {
            throw new LockConflictedException();
        }

        $this->checkNotExpired($key);
    }

    private function getHashedKey(Key $key): string
    {
        return (string) hex2bin(hash('sha256', (string) $key));
    }

    /**
     * @throws RandomException
     */
    private function getUniqueToken(Key $key): string
    {
        if (!$key->hasState(__CLASS__)) {
            $token = base64_encode(random_bytes(32));
            $key->setState(__CLASS__, $token);
        }

        return $key->getState(__CLASS__);
    }

    private function prune(): void
    {
        $this->orm->delete(LockKey::class)
            ->where('expiration', '<=', time())
            ->execute();
    }

    private function randomlyPrune(): void
    {
        if (
            $this->gcProbability > 0
            && (1.0 === $this->gcProbability || (random_int(0, \PHP_INT_MAX) / \PHP_INT_MAX) <= $this->gcProbability)
        ) {
            $this->prune();
        }
    }
}
