<?php

namespace robuust\heroku;

use Craft;
use craft\helpers\App;
use craft\mail\transportadapters\Smtp;
use craft\queue\Queue;
use craft\web\Request;
use craft\web\Response;
use HerokuClient\Client;
use RuntimeException;
use yii\base\Event;
use yii\queue\PushEvent;
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

        // Require mailtrap on dev
        $dsn = getenv('MAILTRAP_DSN');
        if (Craft::$app->env === 'dev' && !$dsn) {
            throw new RuntimeException('MAILTRAP_DSN environment variable is not set.');
        }

        // Configure Mailtrap mailer
        if ($dsn) {
            $config = App::mailerConfig();
            $config['transport'] = [
                'type' => Smtp::class,
                'dsn' => $dsn,
            ];

            Craft::$app->set('mailer', Craft::createObject($config));
        }

        // If the request is a Turbo request and the method is POST
        // And the response is a redirect
        // Change status code to a 303 for Turbo
        // See https://turbo.hotwired.dev/handbook/drive#redirecting-after-a-form-submission
        Event::on(Response::class, Response::EVENT_BEFORE_SEND, function (Event $event) {
            /** @var Response $response */
            $response = $event->sender;
            /** @var Request $request */
            $request = Craft::$app->getRequest();
            $headers = $request->getHeaders();

            if ($headers->has('X-Turbo-Request-Id') && $request->getMethod() === 'POST' && $response->getIsRedirection()) {
                $response->setStatusCode(303);
            }
        });

        // Toggle workers
        $appName = App::env('HEROKU_APP_NAME');
        $apiKey = App::env('HEROKU_API_KEY');
        if ($appName && $apiKey && !Craft::$app->getConfig()->getGeneral()->runQueueAutomatically) {
            $client = new Client(['apiKey' => $apiKey]);

            // Start worker(s) after new jobs are pushed
            Event::on(BaseQueue::class, BaseQueue::EVENT_AFTER_PUSH, function (PushEvent $event) use ($client, $appName) {
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
     * Normalize cloudcube env to generic AWS env variables.
     */
    private static function cloudcube(): void
    {
        // Mirror Cloudcube-provided variables to AWS-style keys.
        $env = getenv();
        foreach ($env as $key => $value) {
            if (!str_starts_with($key, 'CLOUDCUBE_') || $key === 'CLOUDCUBE_URL') {
                continue;
            }

            $suffix = substr($key, strlen('CLOUDCUBE_'));
            $value = (string) $value;

            // Keep naming close to AWS SDK + common Laravel filesystem env names.
            if ($suffix === 'HOST') {
                static::setEnv('AWS_ENDPOINT', $value);
                continue;
            }

            if ($suffix === 'SUBFOLDER') {
                $prefix = trim($value, '/');
                static::setEnv('AWS_ROOT', $prefix);
                static::setEnv('AWS_PREFIX', $prefix);
                continue;
            }

            if ($suffix === 'REGION') {
                static::setEnv('AWS_REGION', $value);
                static::setEnv('AWS_DEFAULT_REGION', $value);
                continue;
            }

            $awsKey = 'AWS_'.$suffix;
            static::setEnv($awsKey, $value);
        }

        if (!($cloudcube = App::env('CLOUDCUBE_URL'))) {
            return;
        }

        // Dissect cloudcube url
        $components = parse_url($cloudcube);

        // Get bucket, prefix and endpoint
        list($bucket) = explode('.', $components['host']);
        $prefix = isset($components['path']) ? trim($components['path'], '/') : '';
        $endpoint = $components['scheme'].'://'.$components['host'];

        // Set normalized AWS env values from the Cloudcube URL.
        static::setEnv('AWS_BUCKET', $bucket);
        static::setEnv('AWS_ENDPOINT', $endpoint);

        // Laravel uses "root" while SDK clients/options often use "prefix".
        static::setEnv('AWS_ROOT', $prefix);
        static::setEnv('AWS_PREFIX', $prefix);
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
