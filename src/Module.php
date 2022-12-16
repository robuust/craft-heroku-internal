<?php

namespace robuust\heroku;

use Craft;
use craft\awss3\Volume;
use craft\helpers\App;
use craft\volumes\Local;
use HerokuClient\Client;
use yii\base\Event;
use yii\queue\Queue;

/**
 * Heroku module.
 */
class Module extends \yii\base\Module
{
    /**
     * Initializes the module.
     */
    public function init()
    {
        parent::init();

        // Register this as an alias
        Craft::setAlias('@robuust/heroku', dirname(__DIR__));

        // Process environment variables
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
        $apiKey = App::env('HEROKU_API_KEY');
        if ($appName && $apiKey && !Craft::$app->getConfig()->getGeneral()->runQueueAutomatically) {
            $client = new Client(['apiKey' => $apiKey]);

            Event::on(Queue::class, 'after*', function (Event $event) use ($client, $appName) {
                switch ($event->name) {
                    case Queue::EVENT_AFTER_PUSH:
                        $currentDynos = Craft::$app->getCache()->getOrSet('currentDynos', fn () => $client->get('apps/'.$appName.'/formation/worker')->quantity);
                        if ($currentDynos > 0) {
                            return;
                        }
                        $quantity = 1;
                        break;
                    default:
                        $jobs = Craft::$app->queue->getTotalJobs() - Craft::$app->queue->getTotalFailed();
                        if ($jobs > 1) {
                            return;
                        }
                        $quantity = 0;
                        break;
                }

                try {
                    $client->patch('apps/'.$appName.'/formation/worker', ['quantity' => $quantity]);
                    Craft::$app->getCache()->set('currentDynos', $quantity);
                } catch (\Exception $e) {
                    Craft::error($e->getMessage());
                }
            });
        }
    }

    /**
     * Set cloudcube env.
     */
    private function cloudcube()
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
    private static function setEnv(string $key, string $value, $override = false)
    {
        if ($override || !App::env($key)) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }
}
