<?php

declare(strict_types=1);

namespace HerokuClient {
    class Client
    {
        public array $calls = [];
        public bool $failPatch = false;

        public function __construct(public int $quantity, public string $size)
        {
        }

        public function get(string $endpoint): object
        {
            $this->calls[] = ['GET', $endpoint];

            return (object) ['quantity' => $this->quantity, 'size' => $this->size];
        }

        public function patch(string $endpoint, array $payload): object
        {
            $this->calls[] = ['PATCH', $endpoint, $payload];
            if ($this->failPatch) {
                throw new \RuntimeException('Authenticated request must stay private');
            }
            $this->quantity = $payload['quantity'];

            return (object) ['quantity' => $this->quantity, 'size' => $this->size];
        }

        public function getLastHttpResponse(): object
        {
            return new class() {
                public function getStatusCode(): int
                {
                    return 503;
                }

                public function getHeaderLine(string $name): string
                {
                    return '';
                }
            };
        }
    }
}

namespace yii\caching {
    interface CacheInterface
    {
        public function get(mixed $key): mixed;

        public function set(mixed $key, mixed $value, int $duration = 0): bool;

        public function delete(mixed $key): bool;
    }
}

namespace yii\mutex {
    class Mutex
    {
        public bool $locked = false;

        public function acquire(string $name, int $timeout = 0): bool
        {
            if ($this->locked) {
                return false;
            }
            $this->locked = true;

            return true;
        }

        public function release(string $name): bool
        {
            $this->locked = false;

            return true;
        }
    }
}

namespace craft\console {
    class Controller
    {
        public array $messages = [];

        public function stdout(string $message): void
        {
            $this->messages[] = $message;
        }

        public function stderr(string $message): void
        {
            $this->messages[] = $message;
        }
    }
}

namespace craft\helpers {
    class App
    {
        public static function env(string $name): ?string
        {
            return $name === 'HEROKU_APP_NAME' ? 'example-review' : null;
        }
    }
}

namespace yii\console {
    class ExitCode
    {
        public const OK = 0;
        public const UNSPECIFIED_ERROR = 1;
    }
}

namespace robuust\heroku {
    class ClientFactory
    {
        public static ?\HerokuClient\Client $client = null;

        public static function create(): ?\HerokuClient\Client
        {
            return self::$client;
        }
    }
}

namespace {
    use HerokuClient\Client;
    use robuust\heroku\ClientFactory;
    use robuust\heroku\console\controllers\DynoCountCacheController;
    use robuust\heroku\WorkerAutoscaler;
    use yii\caching\CacheInterface;
    use yii\mutex\Mutex;

    class Craft
    {
        public static object $app;
        public static array $warnings = [];

        public static function warning(array $message, string $category): void
        {
            self::$warnings[] = $message;
        }
    }

    class MemoryCache implements CacheInterface
    {
        public array $items = [];
        public array $durations = [];

        public function get(mixed $key): mixed
        {
            return $this->items[serialize($key)] ?? false;
        }

        public function set(mixed $key, mixed $value, int $duration = 0): bool
        {
            $this->items[serialize($key)] = $value;
            $this->durations[serialize($key)] = $duration;

            return true;
        }

        public function delete(mixed $key): bool
        {
            unset($this->items[serialize($key)]);

            return true;
        }
    }

    class Application
    {
        public object $queue;

        public function __construct(private MemoryCache $cache, private Mutex $mutex)
        {
            $this->queue = new class() {
                public function getTotalWaiting(): int
                {
                    return 301;
                }

                public function getTotalReserved(): int
                {
                    return 0;
                }
            };
        }

        public function getCache(): MemoryCache
        {
            return $this->cache;
        }

        public function getMutex(): Mutex
        {
            return $this->mutex;
        }

        public function getConfig(): object
        {
            return new class() {
                public function getGeneral(): object
                {
                    return (object) ['runQueueAutomatically' => false];
                }
            };
        }
    }

    require __DIR__.'/../src/WorkerAutoscaler.php';
    require __DIR__.'/../src/console/controllers/DynoCountCacheController.php';

    function same(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException('Expected '.var_export($expected, true).', received '.var_export($actual, true));
        }
    }

    function cacheKey(string $suffix): array
    {
        return ['heroku-workers', 'example-review', $suffix];
    }

    function scenario(string $size, int $quantity = 0): array
    {
        $client = new Client($quantity, $size);
        $cache = new MemoryCache();
        $mutex = new Mutex();

        return [new WorkerAutoscaler($client, $cache, $mutex, 'example-review'), $client, $cache, $mutex];
    }

    $tests = [
        'Basic and Eco cap 201 and 301 waiting jobs before PATCH' => function (): void {
            foreach (['Basic', 'basic', 'Eco', 'eCo'] as $size) {
                foreach ([201, 301] as $waiting) {
                    [$autoscaler, $client, $cache] = scenario($size);
                    same(true, $autoscaler->scale((int) min(ceil($waiting / 100), 10), true));
                    same(1, $client->quantity);
                    same(['PATCH', 'apps/example-review/formation/worker', ['quantity' => 1]], $client->calls[1]);
                    same($size, $cache->get(cacheKey('size')));
                    same(3600, $cache->durations[serialize(cacheKey('size'))]);
                }
            }
        },
        'Scalable tiers retain the requested quantity' => function (): void {
            [$autoscaler, $client] = scenario('Standard-1X', 1);
            same(true, $autoscaler->scale(3, true));
            same(3, $client->quantity);
            same(['quantity' => 3], $client->calls[1][2]);
        },
        'Basic workers still stop when the queue is idle' => function (): void {
            [$autoscaler, $client] = scenario('Basic', 1);
            same(true, $autoscaler->scale(0));
            same(0, $client->quantity);
            same(['quantity' => 0], $client->calls[1][2]);
        },
        'Warm formation metadata avoids repeated reads and impossible PATCHes' => function (): void {
            [$autoscaler, $client, $cache] = scenario('Basic');
            $cache->set(cacheKey('quantity'), 0, 3600);
            $cache->set(cacheKey('size'), 'Basic', 3600);
            same(true, $autoscaler->scale(3, true));
            same(true, $autoscaler->scale(4, true));
            same([['PATCH', 'apps/example-review/formation/worker', ['quantity' => 1]]], $client->calls);
            same(1, $cache->get(cacheKey('quantity')));
        },
        'Old quantity-only cache is refreshed before selecting a target' => function (): void {
            [$autoscaler, $client, $cache] = scenario('Basic', 1);
            $cache->set(cacheKey('quantity'), 0, 3600);
            same(true, $autoscaler->scale(3, true));
            same([['GET', 'apps/example-review/formation/worker']], $client->calls);
            same(1, $cache->get(cacheKey('quantity')));
            same('Basic', $cache->get(cacheKey('size')));
        },
        'Missing quantity refreshes both fields instead of using stale size' => function (): void {
            [$autoscaler, $client, $cache] = scenario('Standard-1X');
            $cache->set(cacheKey('size'), 'Basic', 3600);
            same(true, $autoscaler->scale(3, true));
            same(3, $client->quantity);
            same('Standard-1X', $cache->get(cacheKey('size')));
        },
        'Failed attempts invalidate quantity and size while retaining backoff' => function (): void {
            [$autoscaler, $client, $cache, $mutex] = scenario('Basic');
            $client->failPatch = true;
            same(false, $autoscaler->scale(3, true));
            same(false, $cache->get(cacheKey('quantity')));
            same(false, $cache->get(cacheKey('size')));
            same(false, $mutex->locked);
            same(true, $cache->get(cacheKey('retry'))['after'] > time());
            same(false, $autoscaler->scale(3, true));
            same(2, count($client->calls));
            same(false, str_contains(json_encode(Craft::$warnings), 'Authenticated request must stay private'));
        },
        'Only-increase calls preserve an already running worker' => function (): void {
            [$autoscaler, $client] = scenario('Basic', 1);
            same(true, $autoscaler->scale(0, true));
            same([['GET', 'apps/example-review/formation/worker']], $client->calls);
            same(1, $client->quantity);
        },
        'Manual reconciliation uses the same Basic limit' => function (): void {
            [, $client, $cache, $mutex] = scenario('Basic');
            Craft::$app = new Application($cache, $mutex);
            ClientFactory::$client = $client;
            same(0, (new DynoCountCacheController())->actionReconcile());
            same(1, $client->quantity);
            same(['quantity' => 1], $client->calls[1][2]);
        },
        'Cache clear removes both metadata fields and preserves cooldown' => function (): void {
            [, $client, $cache, $mutex] = scenario('Basic');
            Craft::$app = new Application($cache, $mutex);
            $cache->set('currentDynos', 1);
            $cache->set(cacheKey('quantity'), 1, 3600);
            $cache->set(cacheKey('size'), 'Basic', 3600);
            $cache->set(cacheKey('retry'), ['after' => time() + 30, 'failures' => 1]);
            same(0, (new DynoCountCacheController())->actionClear());
            same(false, $cache->get('currentDynos'));
            same(false, $cache->get(cacheKey('quantity')));
            same(false, $cache->get(cacheKey('size')));
            same(true, is_array($cache->get(cacheKey('retry'))));
            same([], $client->calls);
        },
    ];

    foreach ($tests as $name => $test) {
        $test();
        echo 'PASS: '.$name.PHP_EOL;
    }

    echo count($tests).' autoscaler regression scenarios passed.'.PHP_EOL;
}
