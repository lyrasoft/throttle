<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\RateLimiter;

class RateLimitTableDefine
{
    public function __construct(
        public string $table = 'rate_limits',
        public string $keyField = 'key',
        public string $payloadField = 'payload',
        public string $expiredAtField = 'expired_at',
    ) {
    }
}
