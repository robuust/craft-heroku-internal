<?php

namespace robuust\heroku;

use Craft;
use craft\awss3\Fs;
use craft\fs\Local;
use craft\helpers\App;
use craft\queue\Queue;
use HerokuClient\Client;
use yii\base\Event;

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
        if ($appName && !Craft::$app->getConfig()->getGeneral()->runQueueAutomatically) {
            $client = new Client(['apiKey' => App::env('HEROKU_API_KEY')]);

            Event::on(Queue::class, 'after*', function (Event $event) use ($client, $appName) {
                $quantity = 1;
                if ($event->name != Queue::EVENT_AFTER_PUSH && ($event->sender->getTotalJobs() - $event->sender->getTotalFailed()) == 1) {
                    $quantity = 0;
                }
                $client->patch('apps/'.$appName.'/formation/worker', ['quantity' => $quantity]);
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
