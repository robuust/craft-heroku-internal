<?php

namespace robuust\heroku;

use Craft;
use DateTimeImmutable;
use DateTimeZone;
use HerokuClient\Client;
use yii\caching\CacheInterface;
use yii\mutex\Mutex;

/**
 * Scales workers with shared, non-blocking backoff between queue events.
 * The configured cache must be shared between dynos for a common cooldown.
 */
class WorkerAutoscaler
{
    /**
     * Creates an autoscaler using the application's shared cache and mutex.
     *
     * @param Client         $client
     * @param CacheInterface $cache
     * @param Mutex          $mutex
     * @param string         $appName
     */
    public function __construct(
        private Client $client,
        private CacheInterface $cache,
        private Mutex $mutex,
        private string $appName,
    ) {
    }

    /**
     * Reconciles a fresh worker target within the dyno tier's scaling limit.
     *
     * @param int  $quantity
     * @param bool $onlyIncrease
     *
     * @return bool Whether the formation was reconciled rather than deferred
     */
    public function scale(int $quantity, bool $onlyIncrease = false): bool
    {
        $lock = 'heroku-workers:'.$this->appName;
        if (!$this->mutex->acquire($lock, 0)) {
            return false;
        }

        try {
            $retry = $this->cache->get($this->key('retry'));
            if ($retry !== false && $retry['after'] > time()) {
                return false;
            }

            $endpoint = 'apps/'.rawurlencode($this->appName).'/formation/worker';
            $current = $this->cache->get($this->key('quantity'));
            $size = $this->cache->get($this->key('size'));

            try {
                if ($current === false || $size === false) {
                    $formation = $this->client->get($endpoint);
                    $current = (int) $formation->quantity;
                    $size = $formation->size;
                    $this->cache->set($this->key('quantity'), $current, 3600);
                    $this->cache->set($this->key('size'), $size, 3600);
                }

                if (in_array(strtolower($size), ['basic', 'eco'], true)) {
                    $quantity = min($quantity, 1);
                }

                if ($current !== $quantity && (!$onlyIncrease || $quantity > $current)) {
                    $this->client->patch($endpoint, ['quantity' => $quantity]);
                    $this->cache->set($this->key('quantity'), $quantity, 3600);
                }

                $this->cache->delete($this->key('retry'));

                return true;
            } catch (\Exception $exception) {
                // A timed-out PATCH may have succeeded: read the formation on the next attempt.
                $this->cache->delete($this->key('quantity'));
                $this->cache->delete($this->key('size'));
                $response = $this->client->getLastHttpResponse();
                $status = $response?->getStatusCode();
                $failures = min(($retry === false ? 0 : $retry['failures']) + 1, 6);
                $delay = min(30 * (2 ** ($failures - 1)), 900);

                // Permanent HTTP errors should not be retried at the transient-error cadence.
                if ($status !== null && $status >= 400 && $status < 500 && $status !== 429) {
                    $delay = 900;
                }

                $now = time();
                $delay += random_int(0, 5);
                $retryAfter = trim($response?->getHeaderLine('Retry-After') ?? '');
                if (ctype_digit($retryAfter)) {
                    $delay = max($delay, min((int) $retryAfter, PHP_INT_MAX - $now));
                } else {
                    foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
                        $date = DateTimeImmutable::createFromFormat($format, $retryAfter, new DateTimeZone('UTC'));
                        $errors = DateTimeImmutable::getLastErrors();
                        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                            $delay = max($delay, $date->getTimestamp() - $now);
                            break;
                        }
                    }
                }

                $this->cache->set($this->key('retry'), ['failures' => $failures, 'after' => $now + $delay], 0);

                Craft::warning([
                    'message' => 'Heroku worker scaling deferred',
                    'app' => $this->appName,
                    'cause' => $status === 429 ? 'rate_limit' : ($status === null ? 'transport' : 'http'),
                    'status' => $status,
                    'exception' => get_class($exception),
                    'rateLimitRemaining' => $response?->getHeaderLine('RateLimit-Remaining'),
                    'requestId' => $response?->getHeaderLine('Request-Id'),
                    'retryAfter' => $retryAfter,
                    'retryInSeconds' => $delay,
                ], __METHOD__);

                return false;
            }
        } finally {
            $this->mutex->release($lock);
        }
    }

    /**
     * Returns an application-specific cache key.
     *
     * @param string $suffix
     *
     * @return array
     */
    private function key(string $suffix): array
    {
        return ['heroku-workers', $this->appName, $suffix];
    }
}
