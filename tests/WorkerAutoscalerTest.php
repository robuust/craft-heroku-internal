<?php

namespace robuust\heroku\tests;

use craft\config\GeneralConfig;
use craft\console\Application;
use craft\services\Config;
use GuzzleHttp\Psr7\Response;
use HerokuClient\Client;
use Http\Client\Exception\NetworkException;
use Http\Client\HttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use robuust\heroku\Module;
use robuust\heroku\WorkerAutoscaler;
use yii\caching\ArrayCache;
use yii\log\Logger;
use yii\mutex\Mutex;

/**
 * @internal
 */
#[CoversClass(WorkerAutoscaler::class)]
#[CoversClass(Module::class)]
final class WorkerAutoscalerTest extends TestCase
{
    private ArrayCache $cache;
    private TestMutex $mutex;
    private array $responses = [];
    private array $requests = [];

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
        $this->mutex = new TestMutex();
        \Yii::setLogger(new Logger(['flushInterval' => 0]));
    }

    public function testModuleBoundsTheActualCurlTransportTimeouts(): void
    {
        $app = \Craft::$app;
        $server = $_SERVER;
        try {
            $_SERVER['HEROKU_APP_NAME'] = 'example';
            $_SERVER['HEROKU_API_KEY'] = 'test-key';
            $_SERVER['CRAFT_SECRETS_PATH'] = '';
            $config = $this->createMock(Config::class);
            $config->method('getGeneral')->willReturn(new GeneralConfig(['runQueueAutomatically' => false]));
            $application = $this->createMock(Application::class);
            $application->method('getConfig')->willReturn($config);
            $application->method('getCache')->willReturn($this->cache);
            $application->method('getMutex')->willReturn($this->createMock(\craft\mutex\Mutex::class));
            \Craft::$app = $application;

            $autoscaler = Module::createWorkerAutoscaler();
            self::assertInstanceOf(WorkerAutoscaler::class, $autoscaler);
            $client = (new \ReflectionProperty(WorkerAutoscaler::class, 'client'))->getValue($autoscaler);
            $http = (new \ReflectionProperty(Client::class, 'httpClient'))->getValue($client);
            $options = (new \ReflectionProperty($http, 'curlOptions'))->getValue($http);
            self::assertSame(2, $options[CURLOPT_CONNECTTIMEOUT]);
            self::assertSame(5, $options[CURLOPT_TIMEOUT]);
        } finally {
            \Craft::$app = $app;
            $_SERVER = $server;
        }
    }

    public function testSuccessfulScalingIsSharedAndDoesNotRepeatThePatch(): void
    {
        $this->responses = [new Response(200, [], '{"quantity":0}'), new Response(200, [], '{"quantity":1}')];
        $this->autoscaler()->scale(1, true);
        $this->autoscaler()->scale(1, true);
        $this->autoscaler()->scale(0, true);

        self::assertSame(['GET', 'PATCH'], $this->methods());
        self::assertSame('apps/example/formation/worker', ltrim($this->requests[1]->getUri()->getPath(), '/'));
        self::assertSame(['quantity' => 1], json_decode((string) $this->requests[1]->getBody(), true));
    }

    public function testShutdownIsSentOnce(): void
    {
        $this->responses = [new Response(200, [], '{"quantity":1}'), new Response(200, [], '{"quantity":0}')];
        $autoscaler = $this->autoscaler();
        $autoscaler->scale(0);
        $autoscaler->scale(0);

        self::assertSame(['GET', 'PATCH'], $this->methods());
        self::assertSame(['quantity' => 0], json_decode((string) $this->requests[1]->getBody(), true));
    }

    public function testNetworkFailureDefersOtherInstancesWithoutRepeatingCalls(): void
    {
        $this->responses = [null];
        $this->autoscaler()->scale(1, true);
        for ($i = 0; $i < 100; ++$i) {
            $this->autoscaler()->scale(1, true);
        }

        self::assertSame(['GET'], $this->methods());
        self::assertFalse($this->mutex->locked);
        self::assertSame('transport', \Yii::getLogger()->messages[0][0]['cause']);
    }

    public function testBackoffDoublesAndIsCappedAcrossInstances(): void
    {
        $now = 1700000000;
        foreach ([30, 60, 120, 240, 480, 900, 900] as $index => $delay) {
            $this->responses = [new Response(503, [], '{}')];
            $this->autoscaler($now)->scale(1, true);
            self::assertCount($index + 1, $this->requests);
            $this->autoscaler($now + $delay - 1)->scale(1, true);
            self::assertCount($index + 1, $this->requests);
            $now += $delay;
        }
    }

    public function testRateLimitHonoursRetryAfterWithoutInlineRetry(): void
    {
        $this->responses = [new Response(429, [
            'Retry-After' => '1200',
            'RateLimit-Remaining' => '0',
            'Request-Id' => 'example-request',
        ], '{"id":"rate_limit"}')];
        $this->autoscaler()->scale(1, true);
        $this->autoscaler(1700001199)->scale(1, true);
        self::assertCount(1, $this->requests);

        $message = \Yii::getLogger()->messages[0][0];
        self::assertSame('rate_limit', $message['cause']);
        self::assertSame(429, $message['status']);
        self::assertSame('0', $message['rateLimitRemaining']);
        self::assertSame('example-request', $message['requestId']);

        $this->responses = [new Response(200, [], '{"quantity":1}')];
        $this->autoscaler(1700001200)->scale(1, true);
        self::assertSame(['GET', 'GET'], $this->methods());
    }

    public function testRetryAfterHttpDateIsHonoured(): void
    {
        $this->responses = [new Response(503, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', 1700000600)], '{}')];
        $this->autoscaler()->scale(1, true);
        $this->autoscaler(1700000599)->scale(1, true);
        self::assertCount(1, $this->requests);

        $this->responses = [new Response(200, [], '{"quantity":1}')];
        $this->autoscaler(1700000600)->scale(1, true);
        self::assertCount(2, $this->requests);
    }

    public function testMissingInvalidAndPastRetryAfterUseBackoff(): void
    {
        foreach (['', 'invalid', '0', gmdate('D, d M Y H:i:s \G\M\T', 1699999900)] as $header) {
            $this->cache->flush();
            $this->requests = [];
            $this->responses = [new Response(429, ['Retry-After' => $header], '{}')];
            $this->autoscaler()->scale(1, true);
            $this->autoscaler(1700000029)->scale(1, true);
            self::assertCount(1, $this->requests);

            $this->responses = [new Response(200, [], '{"quantity":1}')];
            $this->autoscaler(1700000030)->scale(1, true);
            self::assertCount(2, $this->requests);
        }
    }

    public function testForbiddenIsNotReportedAsRateLimiting(): void
    {
        $this->responses = [new Response(403, ['RateLimit-Remaining' => '0'], '{"id":"forbidden"}')];
        $this->autoscaler()->scale(1, true);
        $this->autoscaler(1700000899)->scale(1, true);
        self::assertCount(1, $this->requests);
        self::assertSame('http', \Yii::getLogger()->messages[0][0]['cause']);

        $this->responses = [new Response(200, [], '{"quantity":1}')];
        $this->autoscaler(1700000900)->scale(1, true);
        self::assertCount(2, $this->requests);
    }

    public function testUncertainPatchIsReadBackAndUsesTheLatestTarget(): void
    {
        $this->responses = [new Response(200, [], '{"quantity":0}'), null];
        $this->autoscaler()->scale(1, true);
        self::assertSame(['GET', 'PATCH'], $this->methods());
        self::assertSame('transport', \Yii::getLogger()->messages[0][0]['cause']);

        // The timed-out PATCH actually succeeded, but the queue is now empty.
        $this->responses = [new Response(200, [], '{"quantity":1}'), new Response(200, [], '{"quantity":0}')];
        $this->autoscaler(1700000030)->scale(0);
        self::assertSame(['GET', 'PATCH', 'GET', 'PATCH'], $this->methods());
        self::assertSame(['quantity' => 0], json_decode((string) $this->requests[3]->getBody(), true));
    }

    public function testSuccessfulRecoveryResetsBackoff(): void
    {
        $this->responses = [new Response(503, [], '{}')];
        $this->autoscaler()->scale(1, true);
        $this->responses = [new Response(200, [], '{"quantity":1}')];
        $this->autoscaler(1700000030)->scale(1, true);
        $this->responses = [new Response(503, [], '{}')];
        $this->autoscaler(1700000030)->scale(0);
        $this->autoscaler(1700000059)->scale(0);
        self::assertCount(3, $this->requests);

        $this->responses = [new Response(200, [], '{"quantity":0}')];
        $this->autoscaler(1700000060)->scale(0);
        self::assertCount(4, $this->requests);
    }

    public function testConcurrentReconciliationDoesNotWaitOrCallHeroku(): void
    {
        $this->mutex->locked = true;
        $this->autoscaler()->scale(1, true);
        self::assertSame([], $this->requests);
        self::assertTrue($this->mutex->locked);
        self::assertSame(0, $this->mutex->timeout);
    }

    public function testClearingFormationDoesNotBypassCooldown(): void
    {
        $this->responses = [new Response(429, [], '{}')];
        $autoscaler = $this->autoscaler();
        $autoscaler->scale(1, true);
        $autoscaler->clearQuantity();
        $autoscaler->scale(1, true);
        self::assertCount(1, $this->requests);
    }

    public function testDifferentApplicationsHaveSeparateFormationCaches(): void
    {
        $this->responses = [new Response(200, [], '{"quantity":1}'), new Response(200, [], '{"quantity":1}')];
        $this->autoscaler()->scale(1, true);
        $this->autoscaler(1700000000, 'other')->scale(1, true);
        self::assertSame(['GET', 'GET'], $this->methods());
    }

    public function testWorkerLoopChecksDoNotCallHerokuBeforeRetryIsDue(): void
    {
        self::assertFalse($this->autoscaler()->isRetryDue());
        $this->responses = [new Response(503, [], '{}')];
        $this->autoscaler()->scale(1, true);

        for ($elapsed = 0; $elapsed < 30; ++$elapsed) {
            self::assertFalse($this->autoscaler(1700000000 + $elapsed)->isRetryDue());
        }

        self::assertTrue($this->autoscaler(1700000030)->isRetryDue());
        self::assertCount(1, $this->requests);
    }

    public function testIdleWorkerRetriesFailedShutdownAfterCooldown(): void
    {
        $this->responses = [new Response(200, [], '{"quantity":1}'), new Response(503, [], '{}')];
        $this->autoscaler()->scale(0);
        self::assertSame(['GET', 'PATCH'], $this->methods());

        $this->responses = [new Response(200, [], '{"quantity":1}'), new Response(200, [], '{"quantity":0}')];
        $worker = $this->autoscaler(1700000030);
        self::assertTrue($worker->isRetryDue());
        $worker->scale(0);

        self::assertSame(['GET', 'PATCH', 'GET', 'PATCH'], $this->methods());
        self::assertSame(['quantity' => 0], json_decode((string) $this->requests[3]->getBody(), true));
        self::assertFalse($worker->isRetryDue());
    }

    private function autoscaler(int $now = 1700000000, string $appName = 'example'): TestAutoscaler
    {
        $http = $this->createMock(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(function (RequestInterface $request): ResponseInterface {
            $this->requests[] = $request;
            $response = array_shift($this->responses);
            if ($response === null) {
                throw new NetworkException('simulated transport timeout', $request);
            }

            return $response;
        });

        $autoscaler = new TestAutoscaler(new Client(['apiKey' => 'test-key', 'httpClient' => $http]), $this->cache, $this->mutex, $appName);
        $autoscaler->timestamp = $now;

        return $autoscaler;
    }

    private function methods(): array
    {
        return array_map(static fn (RequestInterface $request): string => $request->getMethod(), $this->requests);
    }
}

class TestAutoscaler extends WorkerAutoscaler
{
    public int $timestamp;

    protected function now(): int
    {
        return $this->timestamp;
    }

    protected function jitter(): int
    {
        return 0;
    }
}

class TestMutex extends Mutex
{
    public bool $locked = false;
    public ?int $timeout = null;

    protected function acquireLock($name, $timeout = 0): bool
    {
        $this->timeout = $timeout;
        if ($this->locked) {
            return false;
        }

        return $this->locked = true;
    }

    protected function releaseLock($name): bool
    {
        $this->locked = false;

        return true;
    }
}
