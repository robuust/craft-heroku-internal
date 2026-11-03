<?php

namespace robuust\heroku;

use Craft;
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
     * Reconciles a fresh worker target without sleeping or retrying inline.
     *
     * @param int  $quantity
     * @param bool $onlyIncrease
     */
    public function scale(int $quantity, bool $onlyIncrease = false): void
    {
        $lock = 'heroku-workers:'.$this->appName;
        if (!$this->mutex->acquire($lock, 0)) {
            return;
        }

        try {
            $retry = $this->cache->get($this->key('retry'));
            if ($retry !== false && $retry['after'] > $this->now()) {
                return;
            }

            $endpoint = 'apps/'.$this->appName.'/formation/worker';
            $current = $this->cache->get($this->key('quantity'));

            try {
                if ($current === false) {
                    $current = (int) $this->client->get($endpoint)->quantity;
                    $this->cache->set($this->key('quantity'), $current, 3600);
                }

                if ($current !== $quantity && (!$onlyIncrease || $quantity > $current)) {
                    $this->client->patch($endpoint, ['quantity' => $quantity]);
                    $this->cache->set($this->key('quantity'), $quantity, 3600);
                }

                $this->cache->delete($this->key('retry'));
            } catch (\Exception $exception) {
                // A timed-out PATCH may have succeeded: read the formation on the next attempt.
                $this->cache->delete($this->key('quantity'));
                $response = $this->client->getLastHttpResponse();
                $status = $response?->getStatusCode();
                $failures = min(($retry === false ? 0 : $retry['failures']) + 1, 6);
                $delay = min(30 * (2 ** ($failures - 1)), 900);

                // Permanent HTTP errors should not be retried at the transient-error cadence.
                if ($status !== null && $status >= 400 && $status < 500 && $status !== 429) {
                    $delay = 900;
                }

                $retryAfter = $response?->getHeaderLine('Retry-After') ?? '';
                if ($retryAfter !== '') {
                    $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : max(0, (strtotime($retryAfter) ?: 0) - $this->now());
                    $delay = max($delay, $seconds);
                }

                $after = $this->now() + $delay + $this->jitter();
                $this->cache->set($this->key('retry'), ['failures' => $failures, 'after' => $after], 0);

                Craft::warning([
                    'message' => 'Heroku worker scaling deferred',
                    'app' => $this->appName,
                    'cause' => $status === 429 ? 'rate_limit' : ($status === null ? 'transport' : 'http'),
                    'status' => $status,
                    'exception' => get_class($exception),
                    'rateLimitRemaining' => $response?->getHeaderLine('RateLimit-Remaining'),
                    'requestId' => $response?->getHeaderLine('Request-Id'),
                    'retryAfter' => $after,
                ], __METHOD__);
            }
        } finally {
            $this->mutex->release($lock);
        }
    }

    /**
     * Clears the cached formation without bypassing an API cooldown.
     */
    public function clearQuantity(): void
    {
        $this->cache->delete($this->key('quantity'));
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

    /**
     * Returns the current Unix timestamp.
     *
     * @return int
     */
    protected function now(): int
    {
        return time();
    }

    /**
     * Spreads recovery attempts across applications sharing an API account.
     *
     * @return int
     */
    protected function jitter(): int
    {
        return random_int(0, 5);
    }
}
