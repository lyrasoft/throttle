<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\RateLimiter;

use Lyrasoft\Throttle\Entity\RateLimit;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Windwalker\Core\DateTime\Chronos;
use Windwalker\Database\DatabaseAdapter;
use Windwalker\Database\Platform\AbstractPlatform;
use Windwalker\ORM\ORM;
use Windwalker\Query\Bounded\ParamType;
use Windwalker\Query\Query;

class RateLimitDbStorage implements StorageInterface
{
    protected ORM $orm {
        get => $this->db->orm();
    }

    public function __construct(#[\SensitiveParameter] protected DatabaseAdapter $db)
    {
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->clearExpired();

        $key = $limiterState->getId();
        $payload = [
            'class' => $limiterState::class,
            'body' => mb_convert_encoding(\serialize($limiterState), 'UTF-8', 'auto'),
        ];
        $expiredAt = null;

        if ($limiterState->getExpirationTime()) {
            $microtime = microtime(true) + $limiterState->getExpirationTime();

            $expiredAt = Chronos::createFromFormat('U.u', (string) $microtime);
        }

        // Upsert
        $upsertSql = $this->upsertSql($key, $payload, $expiredAt);

        if ($upsertSql) {
             $upsertSql->execute();

             return;
        }

        $this->orm->transaction(
            function () use ($expiredAt, $payload, $key) {
                /** @var ?RateLimit $item */
                $item = $this->orm->from(RateLimit::class)
                    ->where('key', $key)
                    ->forUpdate()
                    ->get(RateLimit::class);

                $isNew = !$item;

                if ($isNew) {
                    $item = new RateLimit();
                    $item->key = $key;
                }

                $item->payload = $payload;
                $item->expiredAt = $expiredAt;

                if ($isNew) {
                    $this->orm->createOne($item);
                } else {
                    $this->orm->updateOne($item);
                }

                return $item;
            }
        );
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        /** @var ?RateLimit $item */
        $item = $this->orm->from(RateLimit::class)
            ->where('key', $limiterStateId)
            ->get(RateLimit::class);

        if (!$item) {
            return null;
        }

        $limiterState = \unserialize($item->payload['body']);

        if ($item->expiredAt && $item->expiredAt->isPast()) {
            return null;
        }

        return $limiterState;
    }

    public function delete(string $limiterStateId): void
    {
        $this->orm->delete(RateLimit::class)
            ->where('key', $limiterStateId)
            ->execute();
    }

    protected function clearExpired(): void
    {
        $this->orm->delete(RateLimit::class)
            ->where('expired_at', '<', \Windwalker\chronos())
            ->execute();
    }

    private function upsertSql(string $key, array $payload, ?\DateTimeInterface $expiredAt): ?Query
    {
        $platformName = $this->db->getPlatform()->getName();

        $query = $this->db->createQuery();
        $table = $this->orm->getEntityMetadata(RateLimit::class)->getTableName();
        $payload = json_encode($payload);
        $expiredAt = $expiredAt?->format($this->db->getDateFormat());
        $expiredAtType = $expiredAt === null ? ParamType::NULL : ParamType::STRING;

        switch ($platformName) {
            case AbstractPlatform::MYSQL:
                $query->bind('key', $key, ParamType::STRING)
                    ->bind('payload', $payload, ParamType::STRING)
                    ->bind('expired_at', $expiredAt, $expiredAtType);

                return $query->sql(
                    $query->format(
                        "INSERT INTO %n (`key`, `payload`, `expired_at`) VALUES (:key, :payload, :expired_at)
ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `expired_at` = VALUES(`expired_at`)",
                        $table,
                    )
                );

            case AbstractPlatform::POSTGRESQL:
                $query->bind('key', $key, ParamType::STRING)
                    ->bind('payload', $payload, ParamType::STRING)
                    ->bind('expired_at', $expiredAt, $expiredAtType);

                return $query->sql(
                    $query->format(
                        'INSERT INTO lock_keys ("key", "payload", "expired_at") VALUES (:key, :payload, :expired_at)
ON CONFLICT ("key") DO UPDATE',
                        $table,
                    )
                );

            case AbstractPlatform::SQLSERVER === $platformName
                && version_compare(
                    $this->db->getDriver()->getVersion(),
                    '10',
                    '>='
                ):
                $query->bind('key1', $key, ParamType::STRING)
                    ->bind('key2', $key, ParamType::STRING)
                    ->bind('payload1', $payload, ParamType::STRING)
                    ->bind('expired_at1', $expiredAt, $expiredAtType)
                    ->bind('payload2', $payload, ParamType::STRING)
                    ->bind('expired_at2', $expiredAt, $expiredAtType);

                // phpcs:disable
                // MERGE is only available since SQL Server 2008 and must be terminated by semicolon
                // It also requires HOLDLOCK according to http://weblogs.sqlteam.com/dang/archive/2009/01/31/UPSERT-Race-Condition-With-MERGE.aspx
                return $query->sql(
                    $query->format(
                        "MERGE INTO %n WITH (HOLDLOCK) USING (SELECT 1 AS dummy) AS src ON (%n = :key1)
                    WHEN NOT MATCHED THEN INSERT (%n, %n, %n) VALUES (:key2, :payload1, :expired_at1)
                    WHEN MATCHED THEN UPDATE SET %n = :payload2, %n = :expired_at2;",
                        $table,
                        'key',
                        'key',
                        'payload',
                        'expired_at',
                        'payload',
                        'expired_at2'
                    )
                );

            case AbstractPlatform::SQLITE:
                $query->bind('key', $key, ParamType::STRING)
                    ->bind('payload', $payload, ParamType::STRING)
                    ->bind('expired_at', $expiredAt, $expiredAtType);

                return $query->sql(
                    $query->format(
                        "INSERT OR REPLACE INTO %n (%n, %n, %n) VALUES (:key, :payload, :expired_at)",
                        $table,
                        'key',
                        'payload',
                        'expired_at'
                    )
                );
        }

        return null;
    }
}
