<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Entity;

use Windwalker\Core\DateTime\Chronos;
use Windwalker\Core\DateTime\ServerTimeCast;
use Windwalker\ORM\Attributes\AutoIncrement;
use Windwalker\ORM\Attributes\CastNullable;
use Windwalker\ORM\Attributes\Column;
use Windwalker\ORM\Attributes\EntitySetup;
use Windwalker\ORM\Attributes\JsonObject;
use Windwalker\ORM\Attributes\PK;
use Windwalker\ORM\Attributes\Table;
use Windwalker\ORM\EntityInterface;
use Windwalker\ORM\EntityTrait;
use Windwalker\ORM\Metadata\EntityMetadata;

// phpcs:disable
// todo: remove this when phpcs supports 8.4
#[Table('rate_limits', 'rate_limit')]
#[\AllowDynamicProperties]
class RateLimit implements EntityInterface
{
    use EntityTrait;

    #[Column('id'), AutoIncrement, PK]
    public ?string $id = null;

    #[Column('key')]
    public string $key = '';

    #[Column('payload')]
    #[JsonObject]
    public array $payload = [];

    #[Column('expired_at')]
    #[CastNullable(ServerTimeCast::class)]
    public ?Chronos $expiredAt = null {
        set(\DateTimeInterface|string|null $value) => $this->expiredAt = Chronos::tryWrap($value);
    }

    #[Column('params')]
    #[JsonObject]
    public array $params = [];

    #[EntitySetup]
    public static function setup(EntityMetadata $metadata): void
    {
        //
    }
}
