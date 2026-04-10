<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Lock;

use Random\RandomException;
use Symfony\Component\Lock\Exception\InvalidArgumentException;
use Symfony\Component\Lock\Exception\InvalidTtlException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\ExpiringStoreTrait;
use Windwalker\Database\DatabaseAdapter;
use Windwalker\Database\Exception\DatabaseQueryException;
use Windwalker\Database\Platform\AbstractPlatform;
use Windwalker\ORM\ORM;
use Windwalker\Query\Query;

use function Windwalker\raw;

class LockDbStore implements PersistingStoreInterface
{
    use ExpiringStoreTrait;

    protected ORM $orm {
        get => $this->db->orm();
    }

    public string $table {
        get => $this->tableDefine->table;
    }

    public string $keyField {
        get => $this->tableDefine->keyField;
    }

    public string $tokenField {
        get => $this->tableDefine->tokenField;
    }

    public string $expirationField {
        get => $this->tableDefine->expirationField;
    }

    public function __construct(
        #[\SensitiveParameter]
        protected DatabaseAdapter $db,
        protected float $gcProbability = 0.01,
        protected int $initialTtl = 300,
        protected LockTableDefine $tableDefine = new LockTableDefine()
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

        try {
            $this->orm->insert($this->table)
                ->columns(
                    $this->keyField,
                    $this->tokenField,
                    $this->expirationField
                )
                ->values(
                    [
                        $this->getHashedKey($key),
                        $this->getUniqueToken($key),
                        raw($this->getCurrentTimestampStatement() . ' + ' . $this->initialTtl)
                    ]
                )
                ->execute();
        } catch (DatabaseQueryException) {
            $this->putOffExpiration($key, $this->initialTtl);
        }

        $this->randomlyPrune();
        $this->checkNotExpired($key);
    }

    public function delete(Key $key): void
    {
        $this->orm->delete($this->table)
            ->where($this->keyField, $this->getHashedKey($key))
            ->where($this->tokenField, $this->getUniqueToken($key))
            ->execute();
    }

    public function exists(Key $key): bool
    {
        $exists = $this->orm->from($this->table)
            ->where($this->keyField, $this->getHashedKey($key))
            ->where($this->tokenField, $this->getUniqueToken($key))
            ->where($this->expirationField, '>', raw($this->getCurrentTimestampStatement()))
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

        $stmt = $this->orm->update($this->table)
            ->set($this->expirationField, $now + $ttl)
            ->set($this->tokenField, $token)
            ->where($this->keyField, $this->getHashedKey($key))
            ->orWhere(
                function (Query $query) use ($now, $token) {
                    $query->where($this->tokenField, $token)
                        ->where($this->expirationField, '<=', $now);
                }
            )
            ->execute();

        if (!$stmt->countAffected() && !$this->exists($key)) {
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
        $this->orm->delete($this->table)
            ->where($this->expirationField, '<=', raw($this->getCurrentTimestampStatement()))
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

    private function getCurrentTimestampStatement(): string
    {
        return match ($this->db->getPlatform()->getName()) {
            AbstractPlatform::MYSQL => 'UNIX_TIMESTAMP(NOW(6))',
            AbstractPlatform::SQLITE => "(julianday('now') - 2440587.5) * 86400.0",
            AbstractPlatform::POSTGRESQL => 'CAST(EXTRACT(epoch FROM NOW()) AS DOUBLE PRECISION)',
            'oci' => "(CAST(systimestamp AT TIME ZONE 'UTC' AS DATE) - DATE '1970-01-01') * 86400 + TO_NUMBER(TO_CHAR(systimestamp AT TIME ZONE 'UTC', 'SSSSS.FF'))",
            AbstractPlatform::SQLSERVER => "CAST(DATEDIFF_BIG(ms, '1970-01-01', SYSUTCDATETIME()) AS FLOAT) / 1000.0",
            default => (new \DateTimeImmutable())->format('U.u'),
        };
    }
}
