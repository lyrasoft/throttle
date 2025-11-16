<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Enum;

enum RateLimitPolicy: string
{
    case FIXED_WINDOW = 'fixed_window';
    case TOKEN_BUCKET = 'token_bucket';
    case SLIDING_WINDOW = 'sliding_window';
    case NO_LIMIT = 'no_limit';
}
