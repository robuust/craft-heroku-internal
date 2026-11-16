<?php

namespace robuust\heroku;

use Craft;
use craft\awss3\Volume;
use craft\helpers\App;
use craft\queue\Queue;
use craft\volumes\Local;
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
        $this->heroku();
        $this->cloudcube();

        // If this is the dev environment, use Local volumes instead of S3
        if (Craft::$app->env === 'dev' || Craft::$app->env === 'test') {
            Craft::$container->set(Volume::class, function ($container, $params, $config) {
                if (empty($config['id'])) {
                    return new Volume($config);
                }

                return new Local([
                    'id' => $config['id'],
                    'uid' => $config['uid'],
                    'name' => $config['name'],
                    'handle' => $config['handle'],
                    'hasUrls' => $config['hasUrls'],
                    'url' => "@web/uploads/{$config['handle']}",
                    'path' => "@webroot/uploads/{$config['handle']}",
                    'sortOrder' => $config['sortOrder'],
                    'dateCreated' => $config['dateCreated'],
                    'dateUpdated' => $config['dateUpdated'],
                    'fieldLayoutId' => $config['fieldLayoutId'],
                ]);
            });
        }

        // Toggle workers
        $appName = App::env('HEROKU_APP_NAME');
        if ($appName && !Craft::$app->getConfig()->getGeneral()->runQueueAutomatically) {
            $client = ClientFactory::create();
            if ($client === null) {
                return;
            }
            $autoscaler = new WorkerAutoscaler($client, Craft::$app->getCache(), Craft::$app->getMutex(), $appName);

            Event::on(BaseQueue::class, BaseQueue::EVENT_AFTER_PUSH, /**
             * Starts workers after new jobs are pushed.
             *
             * @param Event $event
             */ function (Event $event) use ($autoscaler) {
                $jobs = Craft::$app->queue->getTotalJobs() - Craft::$app->queue->getTotalFailed();
                $quantity = (int) min(ceil($jobs / 100), 10);

                $autoscaler->scale($quantity, true);
            });

            Event::on(Queue::class, 'afterE*', /**
             * Stops workers when the existing Craft 3 event observes an empty queue.
             *
             * @param Event $event
             */ function (Event $event) use ($autoscaler) {
                $jobs = Craft::$app->queue->getTotalJobs() - Craft::$app->queue->getTotalFailed();

                if ($jobs == 0) {
                    $autoscaler->scale(0);
                }
            });
        }
    }

    /**
     * Set heroku env.
     */
    private function heroku(): void
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
    private function cloudcube(): void
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
}
