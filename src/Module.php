<?php

namespace robuust\heroku;

use Craft;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\App;
use craft\services\Volumes;
use robuust\heroku\volumes\Local;
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

        // Register volume
        Event::on(Volumes::class, Volumes::EVENT_REGISTER_VOLUME_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = Local::class;
        });

        // Process environment
        $this->heroku();
        $this->cloudcube();
    }

    /**
     * Set heroku env.
     */
    private function heroku()
    {
        if (!($herokuAppName = App::env('HEROKU_APP_NAME'))) {
            return;
        }

        $siteUrl = 'https://'.$herokuAppName.'.herokuapp.com';

        // Adjust siteurl for Heroku PR apps
        static::setEnv('CRAFT_SITEURL', $siteUrl);
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

        // Get subfolder and host
        $subfolder = substr($components['path'], 1);
        $host = $components['scheme'].'://'.$components['host'];

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
     */
    private static function setEnv(string $key, string $value)
    {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}
