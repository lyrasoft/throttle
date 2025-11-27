<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Entity;

use Windwalker\ORM\Attributes\AutoIncrement;
use Windwalker\ORM\Attributes\Column;
use Windwalker\ORM\Attributes\EntitySetup;
use Windwalker\ORM\Attributes\JsonSerializer;
use Windwalker\ORM\Attributes\PK;
use Windwalker\ORM\Attributes\Table;
use Windwalker\ORM\EntityInterface;
use Windwalker\ORM\EntityTrait;
use Windwalker\ORM\Metadata\EntityMetadata;

#[Table('lock_keys', 'lock_key')]
#[\AllowDynamicProperties]
class LockKey implements EntityInterface
{
    use EntityTrait;

    #[Column('id'), AutoIncrement, PK]
    public ?string $id = null;

    #[Column('key')]
    public string $key = '';

    #[Column('token')]
    public string $token = '';

    #[Column('expiration')]
    public int $expiration = 0;

    #[EntitySetup]
    public static function setup(EntityMetadata $metadata): void
    {
        //
    }
}
