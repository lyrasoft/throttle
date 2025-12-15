<?php

declare(strict_types=1);

namespace Lyrasoft\Throttle {

    use Symfony\Component\RateLimiter\Policy\Rate;

    if (!function_exists('rate')) {
        function rate(string|int|\DateInterval $interval, int $amount = 1): Rate
        {
            if (is_int($interval)) {
                $interval = "PT{$interval}S";
            }

            if (is_string($interval)) {
                if (str_starts_with($interval, 'P')) {
                    $interval = new \DateInterval($interval);
                } else {
                    $interval = \DateInterval::createFromDateString($interval);
                }
            }

            return new Rate($interval, $amount);
        }
    }
}
