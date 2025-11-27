<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\RateLimiter;

use Lyrasoft\Throttle\Entity\RateLimit;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Windwalker\Core\DateTime\Chronos;
use Windwalker\Database\DatabaseAdapter;
use Windwalker\ORM\ORM;

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

        $this->orm->transaction(
            function () use ($limiterState) {
                $id = $limiterState->getId();

                /** @var ?RateLimit $item */
                $item = $this->orm->from(RateLimit::class)
                    ->where('key', $id)
                    ->forUpdate()
                    ->get(RateLimit::class);

                $isNew = !$item;

                if ($isNew) {
                    $item = new RateLimit();
                    $item->key = $id;
                }

                $item->payload = [
                    'class' => $limiterState::class,
                    'body' => mb_convert_encoding(\serialize($limiterState), 'UTF-8', 'auto'),
                ];

                if ($limiterState->getExpirationTime()) {
                    $microtime = microtime(true) + $limiterState->getExpirationTime();

                    $item->expiredAt = Chronos::createFromFormat('U.u', (string) $microtime);
                }

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
}
