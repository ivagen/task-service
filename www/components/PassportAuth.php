<?php declare(strict_types=1);

namespace app\components;

use Yii;
use GuzzleHttp\Client;
use yii\base\Component;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ConnectException;

/**
 * Validates Laravel Passport tokens against the auth service.
 *
 * A successful lookup is cached for `cacheDuration` seconds. That means a token
 * revoked in the auth service stays usable here for up to that long — an
 * intentional latency/consistency trade-off. Lower `AUTH_CACHE_TTL` (or set it
 * to 0 to disable caching) if revocation must take effect immediately.
 */
class PassportAuth extends Component
{
    public string $baseUrl = '';

    /**
     * Path of the token lookup, relative to `baseUrl`. The auth service exposes
     * its whole API under a version prefix, so the endpoint is /api/v1/user.
     */
    public string $userPath = 'api/v1/user';

    public float $timeout = 3.0;
    public float $connectTimeout = 2.0;
    public int $cacheDuration = 60;
    public string $cacheKeyPrefix = 'passport_token_';

    /** Extra attempts after the first one; 0 disables retrying. */
    public int $retries = 1;
    public int $retryDelayMs = 100;

    /**
     * Hard ceiling for the whole validation, retries included. Token checks sit
     * in the critical path of every request, so the budget is what bounds the
     * worst case — not the per-attempt timeouts.
     */
    public float $totalTimeout = 5.0;

    private ?ClientInterface $client = null;

    public function init(): void
    {
        parent::init();

        if ($this->baseUrl === '') {
            $this->baseUrl = rtrim((string)(Yii::$app->params['authServiceUrl'] ?? ''), '/');
        }
    }

    public function setClient(ClientInterface $client): void
    {
        $this->client = $client;
    }

    public function getClient(): ClientInterface
    {
        return $this->client ??= new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'http_errors' => false,
        ]);
    }

    public function cacheKey(string $token): string
    {
        return $this->cacheKeyPrefix . md5($token);
    }

    /**
     * @return array|null user payload, or null when the token is invalid
     *
     * @throws AuthServiceUnavailableException when the auth service cannot answer
     */
    public function validate(string $token): ?array
    {
        $cacheKey = $this->cacheKey($token);

        $cached = Yii::$app->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->requestUser($token);
        $status = $response->getStatusCode();

        if ($status >= 500) {
            Yii::error("Auth service returned {$status}", 'passport');

            throw new AuthServiceUnavailableException('Auth service is unavailable.');
        }

        if ($status !== 200) {
            return null;
        }

        $data = $this->extractUser(json_decode((string)$response->getBody(), true));

        if ($data === null) {
            Yii::warning('Auth service returned an unexpected payload', 'passport');

            return null;
        }

        if ($this->cacheDuration > 0) {
            Yii::$app->cache->set($cacheKey, $data, $this->cacheDuration);
        }

        return $data;
    }

    /**
     * The auth service wraps its resources in a `data` envelope, so the user
     * sits one level down. A flat body is accepted too, so neither shape of
     * response breaks validation.
     *
     * @return array<string, mixed>|null the user payload, or null when absent
     */
    private function extractUser(mixed $body): ?array
    {
        if (!is_array($body)) {
            return null;
        }

        if (isset($body['data']) && is_array($body['data'])) {
            $body = $body['data'];
        }

        return isset($body['id']) ? $body : null;
    }

    /** Upstream failures worth a second attempt — the request had no effect. */
    private const RETRYABLE_STATUSES = [502, 503, 504];

    private function requestUser(string $token): ResponseInterface
    {
        $startedAt = microtime(true);
        $deadline = $startedAt + $this->totalTimeout;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->send($token);
            } catch (ConnectException $e) {
                // The connection was never established, so nothing happened
                // upstream and repeating is safe.
                if (!$this->canRetry($attempt, $deadline)) {
                    $this->logFailure($e->getMessage(), $attempt, $startedAt);

                    throw new AuthServiceUnavailableException('Auth service is unavailable.', 0, $e);
                }

                $this->waitBeforeRetry();
                continue;
            } catch (GuzzleException $e) {
                // Read timeouts and other transfer errors are deliberately not
                // repeated: the attempt already consumed the latency budget.
                $this->logFailure($e->getMessage(), $attempt, $startedAt);

                throw new AuthServiceUnavailableException('Auth service is unavailable.', 0, $e);
            }

            $status = $response->getStatusCode();

            if (!in_array($status, self::RETRYABLE_STATUSES, true) || !$this->canRetry($attempt, $deadline)) {
                $this->logOutcome($status, $attempt, $startedAt);

                return $response;
            }

            $this->waitBeforeRetry();
        }
    }

    private function send(string $token): ResponseInterface
    {
        return $this->getClient()->request('GET', $this->userPath, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * A retry must fit inside the remaining budget, otherwise the caller waits
     * longer than the auth check is allowed to take.
     */
    private function canRetry(int $attempt, float $deadline): bool
    {
        if ($attempt > $this->retries) {
            return false;
        }

        $remaining = $deadline - microtime(true);

        return $remaining > ($this->retryDelayMs / 1000) + $this->connectTimeout;
    }

    private function waitBeforeRetry(): void
    {
        if ($this->retryDelayMs > 0) {
            usleep($this->retryDelayMs * 1000);
        }
    }

    private function logOutcome(int $status, int $attempts, float $startedAt): void
    {
        Yii::info([
            'event' => 'auth_service_call',
            'method' => 'GET',
            'path' => $this->logPath(),
            'status' => $status,
            'attempts' => $attempts,
            'duration_ms' => $this->elapsedMs($startedAt),
        ], 'passport');
    }

    private function logFailure(string $reason, int $attempts, float $startedAt): void
    {
        Yii::error([
            'event' => 'auth_service_call',
            'method' => 'GET',
            'path' => $this->logPath(),
            'outcome' => 'unavailable',
            'reason' => $reason,
            'attempts' => $attempts,
            'duration_ms' => $this->elapsedMs($startedAt),
        ], 'passport');
    }

    /** Keeps the logged path honest: it is the one that was actually requested. */
    private function logPath(): string
    {
        return '/' . ltrim($this->userPath, '/');
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int)round((microtime(true) - $startedAt) * 1000);
    }
}
