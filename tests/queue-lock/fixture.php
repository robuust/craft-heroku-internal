<?php

namespace {
    $autoload = getenv('QUEUE_TEST_AUTOLOAD') ?: dirname(__DIR__, 2).'/vendor/autoload.php';
    require $autoload;
    require dirname($autoload).'/yiisoft/yii2/Yii.php';
    require dirname($autoload).'/craftcms/cms/src/Craft.php';
    require dirname(__DIR__, 2).'/src/WorkerAutoscaler.php';

    class TestApplication extends yii\console\Application
    {
        public $env = 'test';

        /**
         * Identifies the synthetic database as initialized.
         *
         * @return bool
         */
        public function getIsInstalled(): bool
        {
            return true;
        }

        /**
         * Returns the isolated application fixture component.
         *
         * @return object
         */
        public function getMutex()
        {
            return $this->get('mutex');
        }

        /**
         * Returns the isolated application fixture component.
         *
         * @return object
         */
        public function getConfig()
        {
            return new class {
                /**
                 * Reports that no project configuration file is being loaded.
                 */
                public function getLoadingConfigFile()
                {
                    return null;
                }

                /**
                 * @return craft\config\GeneralConfig
                 */
                public function getGeneral()
                {
                    return new craft\config\GeneralConfig(['runQueueAutomatically' => false]);
                }
            };
        }
    }

    class TestQueue extends craft\queue\Queue
    {
        /**
         * Reserves a synthetic payload without executing it.
         *
         * @return array|null
         */
        public function reserveFixture(): ?array
        {
            return $this->reserve();
        }
    }

    class NeverExecutedJob implements yii\queue\JobInterface
    {
        /**
         * {@inheritdoc}
         */
        public function execute($queue)
        {
            throw new RuntimeException('The reproduction must never execute a job.');
        }
    }

    class SlowClient extends HerokuClient\Client
    {
        public array $calls = [];

        /**
         * {@inheritdoc}
         */
        public function __construct()
        {
            // Do not construct an HTTP transport or read credentials.
        }

        /**
         * {@inheritdoc}
         */
        public function get($path, array $headers = [])
        {
            if ($path !== 'apps/queue-reproduction/formation/worker') {
                throw new RuntimeException('Unexpected formation endpoint.');
            }
            $this->calls[] = ['method' => 'GET'];
            file_put_contents(getenv('QUEUE_TEST_SIGNAL').'.tmp', json_encode([
                'callback_lock_held' => Craft::$app->getMutex()->isAcquired('craft\queue\Queue::queue'),
                'pid' => Craft::$app->getDb()->createCommand('SELECT pg_backend_pid()')->queryScalar(),
            ]));
            rename(getenv('QUEUE_TEST_SIGNAL').'.tmp', getenv('QUEUE_TEST_SIGNAL'));
            usleep((int) getenv('QUEUE_TEST_DELAY_US'));

            return (object) ['quantity' => 0, 'size' => 'standard-1x'];
        }

        /**
         * {@inheritdoc}
         */
        public function patch($path, $body = null, array $headers = [])
        {
            $this->calls[] = ['method' => 'PATCH', 'quantity' => $body['quantity']];

            return (object) $body;
        }
    }
}

namespace robuust\heroku {
    class ClientFactory
    {
        public static ?\SlowClient $client = null;

        /**
         * Supplies the mocked client without reading credentials.
         *
         * @return \SlowClient
         */
        public static function create()
        {
            return self::$client = new \SlowClient();
        }
    }
}

namespace {
    require getenv('QUEUE_TEST_MODULE') ?: dirname(__DIR__, 2).'/src/Module.php';
    $mode = $argv[1];
    $db = new craft\db\Connection([
        'dsn' => 'pgsql:host='.getenv('QUEUE_TEST_SOCKET').';port=55439;dbname=postgres',
        'username' => 'queue_test',
        'schemaMap' => ['pgsql' => craft\db\pgsql\Schema::class],
        'commandMap' => ['pgsql' => craft\db\Command::class],
    ]);
    $app = new TestApplication([
        'id' => 'queue-reproduction',
        'basePath' => __DIR__,
        'components' => [
            'db' => $db,
            'cache' => ['class' => yii\caching\ArrayCache::class],
            'mutex' => ['class' => yii\mutex\PgsqlMutex::class, 'db' => $db],
        ],
    ]);
    $queue = new TestQueue([
        'db' => $db,
        'mutex' => $app->getMutex(),
        'channel' => 'queue',
        'tableName' => 'queue',
        // Emit real Yii push events without Redis, networking, or execution.
        'proxyQueue' => new yii\queue\sync\Queue(),
    ]);
    $app->set('queue', $queue);
    $app->get('queue');

    if ($mode === 'seed') {
        $db->createCommand('DROP TABLE IF EXISTS queue')->execute();
        $db->createCommand('CREATE TABLE queue (id SERIAL PRIMARY KEY, channel VARCHAR(255), job BYTEA, description TEXT, "timePushed" INTEGER, ttr INTEGER, delay INTEGER DEFAULT 0, priority INTEGER DEFAULT 1024, "dateReserved" TIMESTAMP, "timeUpdated" INTEGER, progress INTEGER DEFAULT 0, "progressLabel" TEXT, attempt INTEGER DEFAULT 0, fail BOOLEAN DEFAULT FALSE, "dateFailed" TIMESTAMP, error TEXT)')->execute();
        foreach ([1, 2] as $id) {
            $db->createCommand()->insert('queue', [
                'channel' => 'queue', 'job' => 'synthetic-never-executed',
                'timePushed' => time(), 'ttr' => 300, 'fail' => true,
            ])->execute();
        }
        exit;
    }

    if ($mode === 'contender') {
        $start = microtime(true);
        try {
            $payload = $queue->reserveFixture();
            $result = $payload === null ? 'empty' : 'reserved';
        } catch (craft\errors\MutexException $e) {
            $result = get_class($e).': '.$e->getMessage();
        }
        echo json_encode([
            'contender_result' => $result, 'wait_seconds' => microtime(true) - $start,
            'pid' => $db->createCommand('SELECT pg_backend_pid()')->queryScalar(),
        ])."\n";
        exit;
    }

    // The factory above substitutes the client. No credentials or .env are loaded.
    $_ENV['HEROKU_APP_NAME'] = $_SERVER['HEROKU_APP_NAME'] = 'queue-reproduction';
    putenv('MAILTRAP_DSN');
    unset($_ENV['MAILTRAP_DSN'], $_SERVER['MAILTRAP_DSN']);
    new robuust\heroku\Module('heroku');
    $client = robuust\heroku\ClientFactory::$client;
    $start = microtime(true);
    if ($mode === 'push') {
        $queue->delay(300)->push(new NeverExecutedJob());
    } elseif ($mode === 'retry') {
        $queue->retry('1');
    } else {
        $queue->retryAll();
    }
    $operationSeconds = microtime(true) - $start;
    $callsBeforeBoundary = count($client->calls);
    if ($mode === 'worker-loop') {
        $queue->trigger(yii\queue\cli\Queue::EVENT_WORKER_LOOP);
    } elseif ($mode === 'after-release') {
        $queue->trigger(craft\queue\Queue::EVENT_AFTER_EXEC_AND_RELEASE);
    } else {
        $app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
    }
    $calls = $client->calls;
    // The same boundary without new pushes must not reconcile again.
    $app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
    $queue->trigger(yii\queue\cli\Queue::EVENT_WORKER_LOOP);
    if ($calls !== $client->calls) {
        throw new RuntimeException('Pending startup was not consumed.');
    }
    if ($mode === 'after-release') {
        $queue->reserveFixture();
        $queue->reserveFixture();
        $queue->trigger(craft\queue\Queue::EVENT_AFTER_EXEC_AND_RELEASE);
        if ($calls !== $client->calls) {
            throw new RuntimeException('Reserved jobs must retain workers.');
        }
        $db->createCommand()->delete('queue')->execute();
        $queue->trigger(craft\queue\Queue::EVENT_AFTER_EXEC_AND_RELEASE);
    }
    echo json_encode([
        'actor_seconds' => microtime(true) - $start, 'operation_seconds' => $operationSeconds,
        'calls_before_boundary' => $callsBeforeBoundary, 'calls' => $client->calls,
        'lock_after_return' => $app->getMutex()->isAcquired('craft\queue\Queue::queue'),
    ])."\n";
}
