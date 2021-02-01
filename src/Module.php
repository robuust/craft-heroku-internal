<?php

namespace robuust\heroku;

use Craft;
use craft\awss3\Volume;
use craft\helpers\App;
use craft\volumes\Local;

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
        $this->heroku();
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

        // Get bucket, subfolder and host
        $bucket = explode('.', $components['host'])[0];
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
     */
    private static function setEnv(string $key, string $value)
    {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}
