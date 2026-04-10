<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\RateLimiter;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Windwalker\Database\DatabaseAdapter;
use Windwalker\Database\Platform\AbstractPlatform;
use Windwalker\ORM\ORM;
use Windwalker\Query\Query;

use function Windwalker\raw;

class RateLimitDbStorage implements StorageInterface
{
    protected ORM $orm {
        get => $this->db->orm();
    }

    public string $table {
        get => $this->tableDefine->table;
    }

    public string $keyField {
        get => $this->tableDefine->keyField;
    }

    public string $payloadField {
        get => $this->tableDefine->payloadField;
    }

    public string $expiredAtField {
        get => $this->tableDefine->expiredAtField;
    }

    public function __construct(
        #[\SensitiveParameter] protected DatabaseAdapter $db,
        protected float $gcProbability = 0.01,
        protected RateLimitTableDefine $tableDefine = new RateLimitTableDefine()
    ) {
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->clearExpired();

        $key = $limiterState->getId();
        $payload = [
            'class' => $limiterState::class,
            'body' => mb_convert_encoding(\serialize($limiterState), 'UTF-8', 'auto'),
        ];

        $payload = json_encode($payload);
        $expiredAt = 'NULL';

        if ($limiterState->getExpirationTime()) {
            $expiredAt = $this->getCurrentTimestampStatement() . ' + ' . $limiterState->getExpirationTime();

            $expiredAt = $this->convertToUnix($expiredAt);
        }

        // Upsert
        $upsertSql = $this->upsertSql($key, $payload, $expiredAt);

        if ($upsertSql) {
            $upsertSql->execute();

            return;
        }

        // Fallback to traditional php control
        $this->orm->transaction(
            function () use ($expiredAt, $payload, $key) {
                $item = $this->orm->from($this->table)
                    ->where($this->keyField, $key)
                    ->forUpdate()
                    ->get();

                $isNew = !$item;

                if ($isNew) {
                    $this->orm->insert($this->table)
                        ->columns(
                            $this->keyField,
                            $this->payloadField,
                            $this->expiredAtField,
                        )
                        ->values(
                            [
                                $key,
                                $payload,
                                raw($expiredAt)
                            ]
                        )
                        ->execute();
                } else {
                    $this->orm->update($this->table)
                        ->where($this->keyField, $key)
                        ->set($this->payloadField, $payload)
                        ->set($this->expiredAtField, raw($expiredAt))
                        ->execute();
                }
            }
        );
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $payload = $this->orm->select($this->payloadField)
            ->from($this->table)
            ->where($this->keyField, $limiterStateId)
            // AND (A OR B)
            ->orWhere(
                function (Query $query) {
                    $query->where($this->expiredAtField, null);
                    $query->where(
                        $this->expiredAtField,
                        '>',
                        raw($this->getCurrentTimestampStatement())
                    );
                }
            )
            ->result();

        if (!$payload) {
            return null;
        }

        $payload = json_decode($payload, true);

        return \unserialize($payload['body'], ['allowed_classes' => true]);
    }

    public function delete(string $limiterStateId): void
    {
        $this->orm->delete($this->table)
            ->where($this->keyField, $limiterStateId)
            ->execute();
    }

    protected function clearExpired(): void
    {
        if (mt_rand() / mt_getrandmax() >= $this->gcProbability) {
            return;
        }

        $this->orm->delete($this->table)
            ->where($this->expiredAtField, '<', raw($this->getCurrentTimestampStatement()))
            ->execute();
    }

    private function upsertSql(string $key, string $payload, string $expiredAt): ?Query
    {
        $platformName = $this->db->getPlatform()->getName();

        $query = $this->db->createQuery();

        $table = $query->quoteName($this->table);
        $keyField = $query->quoteName($this->tableDefine->keyField);
        $payloadField = $query->quoteName($this->tableDefine->payloadField);
        $expiredAtField = $query->quoteName($this->tableDefine->expiredAtField);

        switch ($platformName) {
            case AbstractPlatform::MYSQL:
                $query->bind('key', $key);
                $query->bind('payload', $payload);

                return $query->sql(
                    "INSERT INTO $table ($keyField, $payloadField, $expiredAtField) VALUES (:key, :payload, $expiredAt)
ON DUPLICATE KEY UPDATE $payloadField = VALUES($payloadField), $expiredAtField = VALUES($expiredAtField)"
                );

            case AbstractPlatform::POSTGRESQL:
                $query->bind('key', $key);
                $query->bind('payload', $payload);

                return $query->sql(
                    "INSERT INTO $table ($keyField, $payloadField, $expiredAtField) 
VALUES (:key, :payload, $expiredAt) ON CONFLICT ($keyField) DO UPDATE SET
$payloadField = EXCLUDED.$payloadField, 
$expiredAtField = EXCLUDED.$expiredAtField
"
                );

            case AbstractPlatform::SQLSERVER:
                if (
                    version_compare(
                        $this->db->getDriver()->getVersion(),
                        '10',
                        '<'
                    )
                ) {
                    return null;
                }

                $query->bind('key1', $key);
                $query->bind('key2', $key);
                $query->bind('payload1', $payload);
                $query->bind('payload2', $payload);

                // phpcs:disable
                // MERGE is only available since SQL Server 2008 and must be terminated by semicolon
                // It also requires HOLDLOCK according to http://weblogs.sqlteam.com/dang/archive/2009/01/31/UPSERT-Race-Condition-With-MERGE.aspx
                return $query->sql(
                    "MERGE INTO $table WITH (HOLDLOCK) USING (SELECT 1 AS dummy) AS src ON ($keyField = :key1)
                    WHEN NOT MATCHED THEN INSERT ($keyField, $payloadField, $expiredAtField) 
                    VALUES (:key2, :payload1, $expiredAt)
                    WHEN MATCHED THEN UPDATE SET $payloadField = :payload2, $expiredAtField = $expiredAt;"
                );

            case AbstractPlatform::SQLITE:
                $query->bind('key', $key);
                $query->bind('payload', $payload);

                return $query->sql(
                    "INSERT OR REPLACE INTO $table ($keyField, $payloadField, $expiredAtField) 
                VALUES (:key, :payload, $expiredAt)"
                );
        }

        return null;
    }

    private function getCurrentTimestampStatement(): string
    {
        return match ($this->db->getPlatform()->getName()) {
            AbstractPlatform::MYSQL => 'UNIX_TIMESTAMP(NOW(6))',
            AbstractPlatform::SQLITE => "(julianday('now') - 2440587.5) * 86400.0",
            AbstractPlatform::POSTGRESQL => 'CAST(EXTRACT(epoch FROM NOW()) AS DOUBLE PRECISION)',
            // 'oci' => "(CAST(systimestamp AT TIME ZONE 'UTC' AS DATE) - DATE '1970-01-01') * 86400 + TO_NUMBER(TO_CHAR(systimestamp AT TIME ZONE 'UTC', 'SSSSS.FF'))",
            AbstractPlatform::SQLSERVER => "CAST(DATEDIFF_BIG(ms, '1970-01-01', SYSUTCDATETIME()) AS FLOAT) / 1000.0",
            default => (new \DateTimeImmutable())->format('U.u'),
        };
    }

    private function convertToUnix(string $expiredAt): string
    {
        return match ($this->db->getPlatform()->getName()) {
            AbstractPlatform::MYSQL => "FROM_UNIXTIME($expiredAt)",
            AbstractPlatform::POSTGRESQL => "TO_TIMESTAMP($expiredAt)",
            AbstractPlatform::SQLSERVER => "DATEADD(SECOND, $expiredAt, '1970-01-01')",
            AbstractPlatform::SQLITE => "datetime($expiredAt, 'unixepoch')",
        };
    }
}
