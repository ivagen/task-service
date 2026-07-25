<?php declare(strict_types=1);

namespace tests\Integration;

use Yii;
use tests\TestCase;
use app\components\RateLimiter;

/**
 * Exercises the atomic Redis path of the rate limiter — the branch the cache
 * fallback can never cover.
 *
 * Skipped unless TEST_REDIS_HOST is set.
 */
class RedisRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->hasRedis()) {
            $this->markTestSkipped('Requires Redis: set TEST_REDIS_HOST.');
        }
    }

    private function limiter(int $limit = 5, int $window = 60): RateLimiter
    {
        return new RateLimiter(['limit' => $limit, 'window' => $window]);
    }

    public function test_counter_is_stored_in_redis_not_in_the_cache(): void
    {
        $this->limiter()->hit('user-42');

        $this->assertSame('1', (string)Yii::$app->redis->executeCommand('GET', ['rate_limit:user-42']));
    }

    public function test_incr_is_atomic_across_separate_limiter_instances(): void
    {
        // Distinct instances, one Redis key: exactly `limit` requests may pass,
        // no matter how the hits interleave.
        $workers = [$this->limiter(limit: 20), $this->limiter(limit: 20), $this->limiter(limit: 20)];

        $allowed = 0;
        for ($i = 0; $i < 60; $i++) {
            $allowed += $workers[$i % 3]->hit('busy-user')->allowed ? 1 : 0;
        }

        $this->assertSame(20, $allowed);
        $this->assertSame('60', (string)Yii::$app->redis->executeCommand('GET', ['rate_limit:busy-user']));
    }

    public function test_remaining_decreases_by_exactly_one_per_hit(): void
    {
        $limiter = $this->limiter(limit: 10);

        for ($expected = 9; $expected >= 0; $expected--) {
            $this->assertSame($expected, $limiter->hit('stepper')->remaining);
        }
    }

    public function test_expiry_is_set_once_and_does_not_slide(): void
    {
        $limiter = $this->limiter(limit: 100, window: 50);
        $key = 'rate_limit:ttl-user';

        $limiter->hit('ttl-user');
        $ttlAfterFirst = (int)Yii::$app->redis->executeCommand('TTL', [$key]);

        // Shrink the window artificially; further hits must not restore it.
        Yii::$app->redis->executeCommand('EXPIRE', [$key, 5]);

        $limiter->hit('ttl-user');
        $limiter->hit('ttl-user');

        $ttlNow = (int)Yii::$app->redis->executeCommand('TTL', [$key]);

        $this->assertLessThanOrEqual(50, $ttlAfterFirst);
        $this->assertLessThanOrEqual(5, $ttlNow, 'allowed requests must not extend the window');
    }

    public function test_missing_expiry_is_repaired(): void
    {
        $key = 'rate_limit:no-ttl';

        // Simulate a counter that lost its TTL (e.g. a crash between INCR and EXPIRE).
        Yii::$app->redis->executeCommand('SET', [$key, 3]);

        $this->limiter(limit: 100, window: 30)->hit('no-ttl');

        $this->assertGreaterThan(0, (int)Yii::$app->redis->executeCommand('TTL', [$key]));
    }

    public function test_window_reset_lets_a_blocked_client_back_in(): void
    {
        $limiter = $this->limiter(limit: 1, window: 60);

        $this->assertTrue($limiter->hit('blocked')->allowed);
        $this->assertFalse($limiter->hit('blocked')->allowed);

        // Expiring the key is what the window elapsing does.
        Yii::$app->redis->executeCommand('DEL', ['rate_limit:blocked']);

        $this->assertTrue($limiter->hit('blocked')->allowed);
    }

    public function test_api_returns_429_backed_by_redis(): void
    {
        Yii::$app->set('rateLimiter', ['class' => RateLimiter::class, 'limit' => 2, 'window' => 60]);

        $this->assertSame(200, $this->get('/api/v1/tasks')['status']);
        $this->assertSame(200, $this->get('/api/v1/tasks')['status']);

        $res = $this->get('/api/v1/tasks');

        $this->assertSame(429, $res['status']);
        $this->assertSame('0', $res['headers']['x-ratelimit-remaining']);
        $this->assertGreaterThan(0, (int)$res['headers']['retry-after']);
    }

    public function test_counters_are_isolated_per_user_in_redis(): void
    {
        $limiter = $this->limiter(limit: 1);

        $this->assertTrue($limiter->hit('alice')->allowed);
        $this->assertFalse($limiter->hit('alice')->allowed);
        $this->assertTrue($limiter->hit('bob')->allowed);
    }
}
