<?php

namespace robuust\heroku;

use Craft;
use craft\awss3\Fs;
use craft\contactform\models\Submission;
use craft\fs\Local;
use craft\helpers\App;
use craft\helpers\Session;
use craft\mail\transportadapters\Smtp;
use craft\queue\Queue;
use craft\web\Request;
use craft\web\Response;
use RuntimeException;
use yii\base\Application;
use yii\base\Event;
use yii\queue\cli\Queue as CliQueue;
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
     * Whether pushed jobs still need worker reconciliation.
     */
    private bool $pendingScaleUp = false;

    /**
     * Autoscaler initialized before queue event handlers are registered.
     */
    private WorkerAutoscaler $autoscaler;

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

            // Render failed Contact Form submissions in Turbo Drive and Frames.
            $submission = Craft::$app->getUrlManager()->getRouteParams()['submission'] ?? null;
            if ($submission instanceof Submission && $submission->hasErrors()) {
                $response->setStatusCode(422);
            }
        });

        // Toggle workers
        $appName = App::env('HEROKU_APP_NAME');
        if ($appName && !Craft::$app->getConfig()->getGeneral()->runQueueAutomatically) {
            $client = ClientFactory::create();
            if ($client === null) {
                return;
            }
            $this->autoscaler = new WorkerAutoscaler($client, Craft::$app->getCache(), Craft::$app->getMutex(), $appName);

            // Defer proxy pushes, which can occur inside Craft's retry mutex.
            Event::on(BaseQueue::class, BaseQueue::EVENT_AFTER_PUSH, function (PushEvent $event) {
                $this->pendingScaleUp = true;
                if (Craft::$app->getRequest()->getIsConsoleRequest() && $event->sender === Craft::$app->queue) {
                    $this->flushPendingScaleUp();
                }
            });

            // Retried proxy jobs have no outer Craft afterPush event. Flush them
            // after the request or before the next iteration of a long-lived worker.
            if (Craft::$app->getRequest()->getIsConsoleRequest()) {
                Event::on(Application::class, Application::EVENT_AFTER_REQUEST, $this->flushPendingScaleUp(...));
            } else {
                Event::on(Response::class, Response::EVENT_AFTER_SEND, function () {
                    if (!$this->pendingScaleUp) {
                        return;
                    }

                    // Release the response and session before calling Heroku.
                    Session::close();
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    $this->flushPendingScaleUp();
                });
            }
            Event::on(CliQueue::class, CliQueue::EVENT_WORKER_LOOP, $this->flushPendingScaleUp(...));

            // Reconcile pending startup and stop workers only when all jobs finish.
            Event::on(Queue::class, Queue::EVENT_AFTER_EXEC_AND_RELEASE, function (Event $event) {
                $this->flushPendingScaleUp();
                $jobs = Craft::$app->queue->getTotalDelayed() + Craft::$app->queue->getTotalWaiting() + Craft::$app->queue->getTotalReserved();

                if ($jobs == 0) {
                    $this->autoscaler->scale(0);
                }
            });
        }
    }

    /**
     * Reconciles pending pushes after Craft has released its queue mutex.
     */
    private function flushPendingScaleUp(): void
    {
        if (!$this->pendingScaleUp) {
            return;
        }
        $this->pendingScaleUp = false;
        $jobs = Craft::$app->queue->getTotalDelayed() + Craft::$app->queue->getTotalWaiting();
        $quantity = (int) min(ceil($jobs / 100), 10);

        $this->autoscaler->scale($quantity, true);
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
        $subfolder = isset($components['path']) ? substr($components['path'], 1) : '/';
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
