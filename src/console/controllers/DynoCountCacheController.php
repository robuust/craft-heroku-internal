<?php

namespace robuust\heroku\console\controllers;

use Craft;
use craft\console\Controller;
use robuust\heroku\Module;
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
        Module::createWorkerAutoscaler()?->clearQuantity();

        $this->stdout("Cleared dyno count cache\n");

        return ExitCode::OK;
    }

    /**
     * Reconciles workers periodically so recovery does not depend on another queue event.
     * Schedule this action once per minute when Heroku workers execute the queue.
     *
     * @return int
     */
    public function actionReconcile(): int
    {
        $autoscaler = Module::createWorkerAutoscaler();
        if ($autoscaler !== null) {
            $queue = Craft::$app->getQueue();
            $jobs = $queue->getTotalJobs() - $queue->getTotalFailed();
            $autoscaler->scale((int) min(ceil($jobs / 100), 10), $jobs > 0);
        }

        return ExitCode::OK;
    }
}
