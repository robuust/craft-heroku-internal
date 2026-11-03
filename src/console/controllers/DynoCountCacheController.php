<?php

namespace robuust\heroku\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use HerokuClient\Client;
use robuust\heroku\WorkerAutoscaler;
use yii\console\ExitCode;

/**
 * Dyno Count Cache controller.
 */
class DynoCountCacheController extends Controller
{
    /**
     * Clear dyno cache.
     *
     * @return int
     */
    public function actionClear(): int
    {
        Craft::$app->getCache()->delete('currentDynos');
        Craft::$app->getCache()->delete(['heroku-workers', App::env('HEROKU_APP_NAME'), 'quantity']);

        $this->stdout("Cleared dyno count cache\n");

        return ExitCode::OK;
    }

    /**
     * Reconcile worker dynos with runnable queue jobs.
     *
     * @return int
     */
    public function actionReconcile(): int
    {
        $appName = App::env('HEROKU_APP_NAME');
        $apiKey = App::env('HEROKU_API_KEY');

        if (!$appName || !$apiKey) {
            $this->stderr("HEROKU_APP_NAME and HEROKU_API_KEY must be configured.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (Craft::$app->getConfig()->getGeneral()->runQueueAutomatically) {
            $this->stdout("Queue runs automatically; worker dynos were not reconciled.\n");

            return ExitCode::OK;
        }

        $client = new Client([
            'apiKey' => $apiKey,
            'curlOptions' => [CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5],
        ]);
        $autoscaler = new WorkerAutoscaler($client, Craft::$app->getCache(), Craft::$app->getMutex(), $appName);
        $waiting = Craft::$app->queue->getTotalWaiting();
        $hasJobs = $waiting + Craft::$app->queue->getTotalReserved() > 0;
        $quantity = (int) min(ceil($waiting / 100), 10);
        if (!$autoscaler->scale($quantity, $hasJobs)) {
            $this->stderr("Worker reconciliation deferred; check the application logs for API failures.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Worker dynos reconciled.\n");

        return ExitCode::OK;
    }
}
