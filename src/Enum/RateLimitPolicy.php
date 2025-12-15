<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle\Enum;

enum RateLimitPolicy
{
    case FIXED_WINDOW;
    case TOKEN_BUCKET;
    case SLIDING_WINDOW;
    case NO_LIMIT;
}
