<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Lock;

class LockTableDefine
{
    public function __construct(
        public string $table = 'lock_keys',
        public string $keyField = 'key',
        public string $tokenField = 'token',
        public string $expirationField = 'expiration',
    ) {
    }
}
