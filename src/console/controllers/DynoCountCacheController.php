<?php

namespace robuust\heroku\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
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
}
