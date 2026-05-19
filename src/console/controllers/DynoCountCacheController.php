<?php

namespace robuust\heroku\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use HerokuClient\Client;
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

        $client = new Client(['apiKey' => $apiKey]);
        $currentDynos = $client->get('apps/'.$appName.'/formation/worker')->quantity;
        $quantity = min(ceil(Craft::$app->queue->getTotalWaiting() / 100), 10);

        if ($quantity <= $currentDynos && Craft::$app->queue->getTotalWaiting() + Craft::$app->queue->getTotalReserved() == 0) {
            $quantity = 0;
        } elseif ($quantity <= $currentDynos) {
            $quantity = $currentDynos;
        }

        if ($quantity != $currentDynos) {
            $client->patch('apps/'.$appName.'/formation/worker', ['quantity' => $quantity]);
        }

        Craft::$app->getCache()->set('currentDynos', $quantity);

        $this->stdout("Worker dynos reconciled to {$quantity}.\n");

        return ExitCode::OK;
    }
}
