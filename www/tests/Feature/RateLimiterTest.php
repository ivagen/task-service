<?php declare(strict_types=1);

namespace tests\Feature;

use Yii;
use tests\TestCase;
use app\components\RateLimiter;

class RateLimiterTest extends TestCase
{
    private function limiter(int $limit = 5, int $window = 60): RateLimiter
    {
        return new RateLimiter(['limit' => $limit, 'window' => $window]);
    }

    /** Points the limiter at a port nothing listens on. */
    private function useUnreachableRedis(): void
    {
        Yii::$app->set('redis', [
            'class' => 'yii\redis\Connection',
            'hostname' => '127.0.0.1',
            'port' => 63999,
            'connectionTimeout' => 0.2,
        ]);
    }

    // =========================================================================
    // Redis unavailable
    // =========================================================================

    public function test_requests_still_succeed_when_redis_is_unreachable(): void
    {
        $this->useUnreachableRedis();

        $res = $this->get('/api/v1/tasks');

        $this->assertSame(200, $res['status'], 'Redis being down must not break the API');
        $this->assertSame('60', $res['headers']['x-ratelimit-limit']);
        $this->assertSame('59', $res['headers']['x-ratelimit-remaining']);
    }

    public function test_limiter_falls_back_to_cache_when_redis_is_unreachable(): void
    {
        $this->useUnreachableRedis();

        $limiter = $this->limiter(limit: 3);

        // The fallback must still count correctly, not reset on every call.
        $this->assertSame(2, $limiter->hit('user-1')->remaining);
        $this->assertSame(1, $limiter->hit('user-1')->remaining);
        $this->assertSame(0, $limiter->hit('user-1')->remaining);
        $this->assertFalse($limiter->hit('user-1')->allowed);
    }

    public function test_limit_is_still_enforced_when_redis_is_unreachable(): void
    {
        $this->useUnreachableRedis();

        Yii::$app->set('rateLimiter', ['class' => RateLimiter::class, 'limit' => 2, 'window' => 60]);

        $this->assertSame(200, $this->get('/api/v1/tasks')['status']);
        $this->assertSame(200, $this->get('/api/v1/tasks')['status']);
        $this->assertSame(429, $this->get('/api/v1/tasks')['status']);
    }

    // =========================================================================
    // Shared-counter correctness
    // =========================================================================

    public function test_separate_limiter_instances_share_one_counter(): void
    {
        // Two instances over one backend stand in for two php-fpm workers:
        // interleaved, not truly parallel, but it proves the counter is shared
        // rather than per-instance. Real atomicity is covered against Redis in
        // tests/Integration/RedisRateLimiterTest.php.
        $workerA = $this->limiter(limit: 6);
        $workerB = $this->limiter(limit: 6);

        $allowed = 0;
        for ($i = 0; $i < 10; $i++) {
            $worker = $i % 2 === 0 ? $workerA : $workerB;
            $allowed += $worker->hit('shared-user')->allowed ? 1 : 0;
        }

        $this->assertSame(6, $allowed, 'the limit must hold across instances');
    }

    public function test_counters_are_isolated_per_user(): void
    {
        $limiter = $this->limiter(limit: 2);

        $limiter->hit('user-a');
        $limiter->hit('user-a');

        $this->assertFalse($limiter->hit('user-a')->allowed);
        $this->assertTrue($limiter->hit('user-b')->allowed, 'one user must not exhaust another');
    }

    public function test_remaining_never_goes_negative_past_the_limit(): void
    {
        $limiter = $this->limiter(limit: 2);

        for ($i = 0; $i < 8; $i++) {
            $result = $limiter->hit('flooder');
            $this->assertGreaterThanOrEqual(0, $result->remaining);
        }

        $this->assertSame(0, $limiter->hit('flooder')->remaining);
    }

    public function test_blocked_request_reports_a_positive_retry_after(): void
    {
        $limiter = $this->limiter(limit: 1, window: 30);

        $limiter->hit('user-1');
        $blocked = $limiter->hit('user-1');

        $this->assertFalse($blocked->allowed);
        $this->assertGreaterThan(0, $blocked->retryAfter);
        $this->assertLessThanOrEqual(30, $blocked->retryAfter);
    }

    public function test_allowed_request_reports_no_retry_after(): void
    {
        $this->assertSame(0, $this->limiter()->hit('user-1')->retryAfter);
    }
}
