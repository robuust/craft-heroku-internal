<?php

namespace robuust\heroku;

use Craft;
use craft\awss3\Fs;
use craft\fs\Local;
use craft\helpers\App;
use craft\queue\Queue;
use HerokuClient\Client;
use yii\base\Event;
use yii\queue\Queue as BaseQueue;

/**
 * Heroku module.
 */
class Module extends \yii\base\Module
{
    /**
     * {@inheritdoc}
     */
    public $controllerNamespace = 'robuust\heroku\console\controllers';

    /**
     * Initializes the module.
     */
    public function init()
    {
        parent::init();

        // Register this as an alias
        Craft::setAlias('@robuust/heroku', dirname(__DIR__));

        // Process environment variables
        static::heroku();
        static::cloudcube();

        // If this is the dev environment, use Local filesystem instead of S3
        if (Craft::$app->env === 'dev' || Craft::$app->env === 'test') {
            Craft::$container->set(Fs::class, function ($container, $params, $config) {
                if (empty($config)) {
                    return new Fs($config);
                }

                return new Local([
                    'name' => $config['name'],
                    'handle' => $config['handle'],
                    'hasUrls' => $config['hasUrls'],
                    'url' => "@web/uploads/{$config['handle']}",
                    'path' => "@webroot/uploads/{$config['handle']}",
                ]);
            });
        }

        // Toggle workers
        $appName = App::env('HEROKU_APP_NAME');
        $apiKey = App::env('HEROKU_API_KEY');
        if ($appName && $apiKey && !Craft::$app->getConfig()->getGeneral()->runQueueAutomatically) {
            $client = new Client(['apiKey' => $apiKey]);

            // Start worker(s) after new jobs are pushed
            Event::on(BaseQueue::class, BaseQueue::EVENT_AFTER_PUSH, function (Event $event) use ($client, $appName) {
                $currentDynos = Craft::$app->getCache()->getOrSet('currentDynos', fn () => $client->get('apps/'.$appName.'/formation/worker')->quantity);
                $jobs = Craft::$app->queue->getTotalJobs() - Craft::$app->queue->getTotalFailed();
                $quantity = min(ceil($jobs / 100), 10);

                if ($quantity > $currentDynos) {
                    static::setWorkers($client, $quantity);
                }
            });

            // Shutdown worker(s) after all jobs are executed and released
            Event::on(Queue::class, Queue::EVENT_AFTER_EXEC_AND_RELEASE, function (Event $event) use ($client) {
                $jobs = Craft::$app->queue->getTotalJobs() - Craft::$app->queue->getTotalFailed();

                if ($jobs == 0) {
                    static::setWorkers($client, 0);
                }
            });
        }
    }

    /**
     * Set heroku env.
     */
    public static function heroku(): void
    {
        $reviewApp = App::env('HEROKU_BRANCH');

        if (!$reviewApp || !($herokuAppName = App::env('HEROKU_APP_NAME'))) {
            return;
        }

        $siteUrl = 'https://'.$herokuAppName.'.herokuapp.com';

        // Adjust siteurl(s) for Heroku review apps
        $env = getenv();
        foreach ($env as $key => $value) {
            if (str_starts_with($key, 'CRAFT_SITEURL')) {
                $components = parse_url($value);
                static::setEnv($key, $siteUrl.@$components['path'], true);
            }
        }
    }

    /**
     * Set cloudcube env.
     */
    private static function cloudcube(): void
    {
        if (!($cloudcube = App::env('CLOUDCUBE_URL'))) {
            return;
        }

        // Dissect cloudcube url
        $components = parse_url($cloudcube);

        // Get bucket, subfolder and host
        list($bucket) = explode('.', $components['host']);
        $subfolder = isset($components['path']) ? substr($components['path'], 1) : '';
        $host = $components['scheme'].'://'.$components['host'];

        // Set bucket to env
        static::setEnv('CLOUDCUBE_BUCKET', $bucket);

        // Set subfolder to env
        static::setEnv('CLOUDCUBE_SUBFOLDER', $subfolder);

        // Set host to env
        static::setEnv('CLOUDCUBE_HOST', $host);
    }

    /**
     * Set environment variables.
     *
     * @param string $key
     * @param string $value
     * @param bool   $override
     */
    private static function setEnv(string $key, string $value, $override = false): void
    {
        if ($override || !App::env($key)) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    /**
     * Set worker quantity.
     *
     * @param Client $client
     * @param int    $quantity
     */
    private static function setWorkers(Client $client, int $quantity): void
    {
        $appName = App::env('HEROKU_APP_NAME');

        try {
            $client->patch('apps/'.$appName.'/formation/worker', ['quantity' => $quantity]);
            Craft::$app->getCache()->set('currentDynos', $quantity);
        } catch (\Exception $e) {
            Craft::error($e->getMessage());
        }
    }
}
