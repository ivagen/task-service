<?php declare(strict_types=1);

namespace app\components;

use Yii;
use yii\base\Component;
use yii\redis\Connection;

/**
 * Fixed-window rate limiter.
 *
 * With Redis the counter is incremented atomically (INCR) and the TTL is set
 * only when the window opens, so the window never slides while a client keeps
 * sending requests. Without Redis it falls back to the cache component, which
 * is not atomic — acceptable for local/file-cache setups only.
 */
class RateLimiter extends Component
{
    public int $limit = 60;
    public int $window = 60;
    public string $keyPrefix = 'rate_limit:';
    public string $redisComponent = 'redis';

    public function hit(string $id): RateLimitResult
    {
        $key = $this->keyPrefix . $id;

        $redis = $this->getRedis();
        if ($redis !== null) {
            try {
                return $this->hitRedis($redis, $key);
            } catch (\Throwable $e) {
                Yii::error('Rate limiter Redis failure, falling back to cache: ' . $e->getMessage(), 'rateLimiter');
            }
        }

        return $this->hitCache($key);
    }

    private function hitRedis(Connection $redis, string $key): RateLimitResult
    {
        $count = (int)$redis->executeCommand('INCR', [$key]);

        // Only the request that opened the window sets the expiry.
        if ($count === 1) {
            $redis->executeCommand('EXPIRE', [$key, $this->window]);
            $ttl = $this->window;
        } else {
            $ttl = (int)$redis->executeCommand('TTL', [$key]);

            // -1 = key without expiry (lost EXPIRE), -2 = key vanished mid-flight.
            if ($ttl < 0) {
                $redis->executeCommand('EXPIRE', [$key, $this->window]);
                $ttl = $this->window;
            }
        }

        return $this->result($count, $ttl);
    }

    private function hitCache(string $key): RateLimitResult
    {
        $now = time();
        $entry = Yii::$app->cache->get($key);

        if (!is_array($entry) || !isset($entry['count'], $entry['reset']) || $entry['reset'] <= $now) {
            $entry = ['count' => 0, 'reset' => $now + $this->window];
        }

        $entry['count']++;
        $ttl = max(1, $entry['reset'] - $now);
        Yii::$app->cache->set($key, $entry, $ttl);

        return $this->result($entry['count'], $ttl);
    }

    private function result(int $count, int $ttl): RateLimitResult
    {
        $allowed = $count <= $this->limit;

        return new RateLimitResult(
            allowed: $allowed,
            limit: $this->limit,
            remaining: max(0, $this->limit - $count),
            retryAfter: $allowed ? 0 : max(1, $ttl),
        );
    }

    private function getRedis(): ?Connection
    {
        if (!Yii::$app->has($this->redisComponent)) {
            return null;
        }

        try {
            $redis = Yii::$app->get($this->redisComponent);
        } catch (\Throwable $e) {
            Yii::error('Rate limiter cannot resolve Redis component: ' . $e->getMessage(), 'rateLimiter');

            return null;
        }

        return $redis instanceof Connection ? $redis : null;
    }
}
