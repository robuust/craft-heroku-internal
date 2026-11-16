<?php

namespace robuust\heroku;

use craft\helpers\App;
use HerokuClient\Client;
use yii\base\InvalidConfigException;

/**
 * Creates the worker client from paired gateway or legacy configuration.
 */
class ClientFactory
{
    /**
     * Creates a client without falling back from incomplete gateway configuration.
     *
     * @return Client|null
     */
    public static function create(): ?Client
    {
        $controlUrl = (string) App::env('HEROKU_CONTROL_URL');
        $controlToken = (string) App::env('HEROKU_CONTROL_TOKEN');

        if ($controlUrl !== '' || $controlToken !== '') {
            if ($controlUrl === '' || !preg_match('/^hc_[A-Za-z0-9_-]{43}$/D', $controlToken)) {
                throw new InvalidConfigException('HEROKU_CONTROL_URL and a valid HEROKU_CONTROL_TOKEN must be configured together.');
            }

            $parts = parse_url($controlUrl);
            if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))) {
                throw new InvalidConfigException('HEROKU_CONTROL_URL must be an HTTPS base URL without credentials, query, or fragment.');
            }

            return new Client([
                'baseUrl' => rtrim($controlUrl, '/').'/',
                'apiKey' => $controlToken,
                'curlOptions' => [
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_TIMEOUT => 35,
                    CURLOPT_FOLLOWLOCATION => false,
                ],
            ]);
        }

        $apiKey = (string) App::env('HEROKU_API_KEY');
        if ($apiKey === '') {
            return null;
        }

        if (str_starts_with($apiKey, 'hc_')) {
            throw new InvalidConfigException('Gateway credentials require HEROKU_CONTROL_URL and HEROKU_CONTROL_TOKEN.');
        }

        return new Client([
            'apiKey' => $apiKey,
            'curlOptions' => [CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5],
        ]);
    }
}
