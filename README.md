# Lyrasoft Throttle Package

This package implement throttling for Windwalker, using symfony
[RateLimiter](https://symfony.com/doc/current/rate_limiter.html) and 
[Lock](https://symfony.com/doc/current/components/lock.html) packages.

<!-- TOC -->
* [Lyrasoft Throttle Package](#lyrasoft-throttle-package)
  * [Installation](#installation)
  * [Lock](#lock)
    * [Manually Create](#manually-create)
    * [Concurrent Locking](#concurrent-locking)
    * [Pre-lock and serialize key](#pre-lock-and-serialize-key)
  * [RateLimiter](#ratelimiter)
    * [Compound Limiters](#compound-limiters)
    * [Manually Create](#manually-create-1)
    * [Token Bucket Limiter](#token-bucket-limiter)
  * [RateLimitMiddleware](#ratelimitmiddleware)
    * [Custom Factory](#custom-factory)
    * [Custom Limiter](#custom-limiter)
    * [Custom Consume Count](#custom-consume-count)
    * [Custom Exceeded Handler and Response](#custom-exceeded-handler-and-response)
<!-- TOC -->

## Installation

Install from composer

```shell
composer require lyrasoft/throttle
```

Then copy files to project

```shell
php windwalker pkg:install lyrasoft/throttle -t migrations
```


## Lock

Get Lock Factory and create lock, you can modify `etc/packages/throttle.config.php` to configure your lock services.

```php
$lockFactory = $container->get(\Symfony\Component\Lock\LockFactory::class);
$lockFactory = $container->get(\Symfony\Component\Lock\LockFactory::class, tag: '...');

$lock = $lockFactory->createLock('user.' . $user->id . '.process', 30); // 30 seconds Timeout

$lock->acquire(true); // Wait until acquired
```

Please see Symfony [Lock documentation](https://symfony.com/doc/current/components/lock.html) to learn basic usage.

### Manually Create

If you want to manually create Lock, you can use `ThrottleService`.

```php
$throttleService = $app->retrieve(\Lyrasoft\Throttle\Factory\ThrottleService::class);

$throttleService->createLockFactory();

// Create Lock and acquire
$lock = $throttleService->createLock('user.' . $user->id . '.process', 30); // 30 seconds Timeout
$acquired = $lock->acquire(true); // Wait until acquired

if ($acquired) {
    // Acquired lock, do your process here...

    $lock->release(); // Release lock after process
} else {
    // Failed to acquire lock
}
```

```php
// Instant lock acquire, if acquire success, return Lock object, or null
$lock = $throttleService->lock('user.' . $user->id . '.process', 30);

if ($lock) {
    // Acquired lock, do your process here...

    $lock->release(); // Release lock after process
} else {
    // All locks are acquired, wait or skip process
}
```

### Concurrent Locking

If you want to limit concurrent processes, you can use `concurrent()` method.

Service will auto append 1 to 5 to your ID to get available locks and auto acquire.

```php
$throttleService = $app->retrieve(\Lyrasoft\Throttle\Factory\ThrottleService::class);

$acquired = $throttleService->concurrent('user.concurrent.' . $user->id, 5);

if ($locked) {
    [$lock, $key, $serial] = $acquired;
    // Acquired lock, do your process here...

    $lock->release(); // Release lock after process
} else {
    // All locks are acquired, wait or skip process
}
```

### Pre-lock and serialize key

If you want to acquire a lock and store it then stop current process, pass this lock to queue or other process,
so that we can run a long task later, you can acquire lock first and serialize the key. 
(Note: Lock object itself is not serializable)

```php
use Symfony\Component\Lock\Key;

$throttleService = $app->retrieve(\Lyrasoft\Throttle\Factory\ThrottleService::class);

$key = new Key('user.lock.' . $user->id);
$lock = $throttleService->createLockFromKey($key, 30, autoRelease: false);

// Start to acquire lock but do not process long task here...
$lock->acquire();


// Then we try to run task in another process, let's store the key.

// Push to queue
$queue->push(new FooJob($key));

// Or serialize then store to DB
$item->lockKey = serialize($key);
$orm->updateOne($item);
```

When you want to run the long task, you can unserialize the key and re-create a lock.

```php
$key = unserialize($item->lockKey);

$lock = $throttleService->createLockFromKey($key, 30);

// Run task....

$lock->release(); // Release lock after process
```

If you are running concurrent locks, the key will return with the lock in an array.

```php
$locked = $throttleService->concurrent('user.concurrent.' . $user->id, 5);

if ($locked) {
    [$lock, $key, $serial] = $locked;
    
    serialize($key);
    
    // Store the key...
} else {
    // All locks are acquired, wait or skip process
}
```

## RateLimiter

Get RateLimiter Factory and create limiter, you can modify `etc/packages/throttle.config.php` to configure your 
RateKLimiter services.

```php
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

$factory = $container->get(RateLimiterFactoryInterface::class);
$factory = $container->get(RateLimiterFactoryInterface::class, tag: '...');

// Symfony RateLimiter Object
$limiter = $factory->create('call.limit.' . $user->id);

$limit = $limiter->consume(1); // Consume 1 token

$limit->isAccepted(); // BOOL: Check if allowed
$limit->getRemainingTokens(); // Get remaining tokens
$limit->getRetryAfter(); // Get retry after DateTime
$limit->ensureAccepted(); // Throw exception if not accepted

```

Please see Symfony [RateLimiter documentation](https://symfony.com/doc/current/rate_limiter.html) to learn basic usage.

You can configure limiters in `etc/packages/throttle.config.php` file, for example, this config 
set `10` requests per minute limit for `default` limiter.

```php
    'factories' => [
        // ...
        RateLimiterFactoryInterface::class => [
            'default' => fn () => RateLimiterServiceFactory::factory(
                id: 'default',
                policy: RateLimitPolicy::FIXED_WINDOW,
                limit: 10,
                interval: '1 minute',
                storage: 'default',
                locker: true,
            ),
            
            // Create new limiter ID if you need....
        ],
    // ...
```

Support Policy:
- `RateLimitPolicy::FIXED_WINDOW`
- `RateLimitPolicy::SLIDING_WINDOW`
- `RateLimitPolicy::TOKEN_BUCKET`
- `RateLimitPolicy::NO_LIMIT`

See https://symfony.com/doc/current/rate_limiter.html#fixed-window-rate-limiter

### Compound Limiters

If you want ot use compound limiters, you can define multiple limiters in the config.

```php
    'factories' => [
        // ...
        RateLimiterFactoryInterface::class => [
            'compound' => fn () => RateLimiterServiceFactory::compoundFactrory(
                [
                    'limiter1',
                    'limiter2',
                    'limiter3',
                ],
            ),
            'limiter1' => ...,
            'limiter2' => ...,
            'limiter3' => ...,
        ],
    // ...
```

### Manually Create

If you want to manually create RateLimiter, you can use `ThrottleService`.

```php
$throttleService = $app->retrieve(\Lyrasoft\Throttle\Factory\ThrottleService::class);

$factory = $throttleService->createRateLimiterFactory(
    'user.' . $user->id,
    \Lyrasoft\Throttle\Enum\RateLimitPolicy::FIXED_WINDOW,
    5,
    '10 minutes',
    true,
);
$limiter = $factory->create('search.action');

$limiter->consume(1); // Consume 1 token
```

Or instant create limiter

```php
$throttleService = $app->retrieve(\Lyrasoft\Throttle\Factory\ThrottleService::class);

$limiter = $throttleService->createRateLimiter(
    'user.' . $user->id . '::search.action', // Use :: to separate factory ID and limiter ID
    \Lyrasoft\Throttle\Enum\RateLimitPolicy::FIXED_WINDOW,
    5,
    '10 minutes',
    true,
);

$limiter = $throttleService->createRateLimiter(
    'search.action', // Only factory ID, use default limiter ID
    \Lyrasoft\Throttle\Enum\RateLimitPolicy::FIXED_WINDOW,
    5,
    '10 minutes',
    true,
);
```

### Token Bucket Limiter

TokenBucket Limiter requires interval parameter to be `Rate` object. You can use helper 
function `\Lyrasoft\Throttle\rate()` to create Rate object or directly use `new Rate(...)`.

```php
$throttleService = $app->retrieve(\Lyrasoft\Throttle\Factory\ThrottleService::class);

// Using helper function
$rate = \Lyrasoft\Throttle\rate(interval: '1minutes', amount: 5); // 5 tokens per 1 minute

// Or directly create Rate object
$rate = new \Symfony\Component\RateLimiter\Rate(
    refillTime: \DateInterval::createFromDateString('1 minutes'),
    refillAmount: 5,
);

$limiter = $throttleService->createRateLimiter(
    id: 'user.' . $user->id . '::video.stream',
    policy: \Lyrasoft\Throttle\Enum\RateLimitPolicy::TOKEN_BUCKET,
    limit: 5,
    interval: $rate,
);
```

## RateLimitMiddleware

Add `RateLimitMiddleware` to route that can be throttled, by default, this middleware use IP as limiter key.

```php
use Lyrasoft\Throttle\Middleware\RateLimitMiddleware;

$router->middleware(
    RateLimitMiddleware::class,
    factory: 'default', // Limiter Factory ID
)
     // ...
```

If reach limit, middleware will throw 429 Too Many Requests Exception.

### Custom Factory

```php
$router->middleware(
    RateLimitMiddleware::class,
    factory: fn (Container $container) => new RateLimiterFactory(...),
)
```

### Custom Limiter

Use static Key:

```php
$router->middleware(
    RateLimitMiddleware::class,
    factory: 'default',
    limiterKey: 'custom.limiter.key', // Limiter ID in the factory
)
```

Use Callback:

```php
$router->middleware(
    RateLimitMiddleware::class,
    factory: 'default',
    limiterKey: function (\Lyrasoft\Luna\User\UserService $userService, \Windwalker\Core\Http\AppRequest $appRequest) {
        $user = $userService->getUser();
        
        if ($user->isLogin()) {
            return 'user.limiter:' . $user->id;
        }
        
        return 'guest.limiter:' . $appRequest->getClientIP();
    }
)
```

### Custom Consume Count

Static number:

```php
$router->middleware(
    RateLimitMiddleware::class,
    factory: 'default',
    consume: 5, // Consume 5 tokens per request
)
```

Use callback:

```php
$router->middleware(
    RateLimitMiddleware::class,
    factory: 'default',
    consume: function (LimiterInterface, $rateLimiter, string $key, /* Inject */) {
        if ($key ==== 'vip') {
            return $rateLimiter->consume(1);
        }
    
        return $rateLimiter->consume(10);
    },
)
```

### Custom Exceeded Handler and Response

By default, middleware will throw 429 Exception when limit exceeded.

If you want to override this, you can use `exceededHandler` argument.

```php
$router->middleware(
    RateLimitMiddleware::class,
    factory: 'default',
    exceededHandler: function (/* Inject */) {
        // You can throw Exception
        throw new \RuntimeException('Too many requests, please try again later.', 429);
        
        // Or return custom Response
        return new \Windwalker\Http\Response\JsonResponse([
            'message' => 'Too many requests, please try again later.',
        ], 429);
    },
)
```

If `exceededHandler` returns a Response object, you can configure the headers by `infoHeaders` argument.

Set `infoHeaders` to TRUE, `RateLimitMiddleware` will auto inject headers:

```
X-RateLimit-Limit: ...
X-RateLimit-Remaining: ...
X-RateLimit-Reset: ...
```

Set `infoHeaders` to callback, you can customize response:

```php
$router->middleware(
    RateLimitMiddleware::class,
    factory: 'default',
    exceededHandler: function (/* Inject */) {
        return new \Windwalker\Http\Response\JsonResponse([
            'message' => 'Too many requests, please try again later.',
        ], 429);
    },
    infoHeaders: function (ResponseInterface $response, \Symfony\Component\RateLimiter\RateLimit $limit, /* Inject */) {
        $response = $response->withAddedHeader('X-Custom-RateLimit-Limit', $limiter->getLimit());
        $response = $response->withAddedHeader('X-Custom-RateLimit-Remaining', $limiter->getRemainingTokens());
        $response = $response->withAddedHeader('X-Custom-RateLimit-Reset', $limiter->getRetryAfter()->getTimestamp());
        
        return $response;
    },
)
```
