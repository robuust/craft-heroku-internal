<?php

namespace robuust\heroku;

use Craft;
use craft\contactform\models\Submission;
use craft\helpers\App;
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

        // Require an SMTP DSN on dev
        $dsn = getenv('MAILER_DSN');
        if (Craft::$app->env === 'dev' && !$dsn) {
            throw new RuntimeException('MAILER_DSN environment variable is not set.');
        }

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
                    if (Craft::$app->has('session', true)) {
                        Craft::$app->getSession()->close();
                    }
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
                $jobs = Craft::$app->queue->getTotalJobs() - Craft::$app->queue->getTotalFailed();

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
        $jobs = Craft::$app->queue->getTotalJobs() - Craft::$app->queue->getTotalFailed();
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
}
