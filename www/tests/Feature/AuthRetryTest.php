<?php declare(strict_types=1);

namespace tests\Feature;

use Yii;
use tests\TestCase;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use tests\CapturingLogTarget;
use app\components\PassportAuth;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use app\components\AuthServiceUnavailableException;

/**
 * Retrying the auth service is only safe for failures that provably had no
 * effect upstream, and only while the latency budget allows it.
 */
class AuthRetryTest extends TestCase
{
    private function connectFailure(): ConnectException
    {
        return new ConnectException('Connection refused', new Request('GET', 'api/user'));
    }

    private function user(): Response
    {
        return new Response(200, [], (string)json_encode(['id' => 1, 'name' => 'T', 'email' => 't@e.com']));
    }

    private function configureAuth(array $config = []): void
    {
        Yii::$app->set('passportAuth', ['class' => PassportAuth::class] + $config);
    }

    /** @return array<string, mixed>|null the auth_service_call log entry */
    private function callLog(): ?array
    {
        $entries = CapturingLogTarget::entriesOfCategory('passport');

        foreach ($entries as $entry) {
            if (($entry['event'] ?? null) === 'auth_service_call') {
                return $entry;
            }
        }

        return null;
    }

    // =========================================================================
    // What gets retried
    // =========================================================================

    public function test_connection_failure_is_retried_and_can_succeed(): void
    {
        $this->configureAuth(['retryDelayMs' => 0]);
        $this->mockAuthService([$this->connectFailure(), $this->user()]);

        $data = Yii::$app->passportAuth->validate('token-a');

        $this->assertIsArray($data);
        $this->assertSame(1, $data['id']);
        $this->assertSame(2, $this->callLog()['attempts']);
    }

    /** @return list<array{int}> */
    public static function retryableStatuses(): array
    {
        return [[502], [503], [504]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retryableStatuses')]
    public function test_gateway_errors_are_retried(int $status): void
    {
        $this->configureAuth(['retryDelayMs' => 0]);
        $this->mockAuthService([new Response($status), $this->user()]);

        $this->assertIsArray(Yii::$app->passportAuth->validate('token-b'));
        $this->assertSame(2, $this->callLog()['attempts']);
    }

    // =========================================================================
    // What must NOT be retried
    // =========================================================================

    public function test_invalid_token_is_not_retried(): void
    {
        // 401 is a definitive answer; repeating it only wastes the budget.
        $this->configureAuth(['retryDelayMs' => 0]);
        $this->mockAuthService([new Response(401), $this->user()]);

        $this->assertNull(Yii::$app->passportAuth->validate('bad-token'));
        $this->assertSame(1, $this->callLog()['attempts']);
    }

    public function test_plain_server_error_is_not_retried(): void
    {
        $this->configureAuth(['retryDelayMs' => 0]);
        $this->mockAuthService([new Response(500), $this->user()]);

        $this->expectException(AuthServiceUnavailableException::class);

        try {
            Yii::$app->passportAuth->validate('token-c');
        } finally {
            $this->assertSame(1, $this->callLog()['attempts']);
        }
    }

    public function test_read_timeout_is_not_retried(): void
    {
        // The attempt already consumed most of the budget, and a transfer error
        // gives no guarantee the request had no effect.
        $this->configureAuth(['retryDelayMs' => 0]);
        $this->mockAuthService([
            new RequestException('cURL error 28: Operation timed out', new Request('GET', 'api/user')),
            $this->user(),
        ]);

        $this->expectException(AuthServiceUnavailableException::class);

        Yii::$app->passportAuth->validate('token-d');
    }

    // =========================================================================
    // Budget
    // =========================================================================

    public function test_retries_can_be_disabled(): void
    {
        $this->configureAuth(['retries' => 0]);
        $this->mockAuthService([$this->connectFailure(), $this->user()]);

        $this->expectException(AuthServiceUnavailableException::class);

        Yii::$app->passportAuth->validate('token-e');
    }

    public function test_extra_attempts_are_capped_by_the_retry_count(): void
    {
        $this->configureAuth(['retries' => 2, 'retryDelayMs' => 0]);
        $this->mockAuthService([
            $this->connectFailure(),
            $this->connectFailure(),
            $this->connectFailure(),
            $this->user(), // must never be reached
        ]);

        try {
            Yii::$app->passportAuth->validate('token-f');
            $this->fail('expected the auth service to be reported unavailable');
        } catch (AuthServiceUnavailableException) {
            $this->assertSame(3, $this->callLog()['attempts'], 'initial attempt plus two retries');
        }
    }

    public function test_no_retry_once_the_total_budget_is_spent(): void
    {
        // A budget smaller than one connect timeout leaves no room to retry.
        $this->configureAuth([
            'retries' => 5,
            'retryDelayMs' => 0,
            'connectTimeout' => 2.0,
            'totalTimeout' => 0.5,
        ]);
        $this->mockAuthService([$this->connectFailure(), $this->user()]);

        try {
            Yii::$app->passportAuth->validate('token-g');
            $this->fail('expected the auth service to be reported unavailable');
        } catch (AuthServiceUnavailableException) {
            $this->assertSame(1, $this->callLog()['attempts'], 'budget must veto the retry');
        }
    }

    public function test_retry_delay_is_honoured(): void
    {
        $this->configureAuth(['retryDelayMs' => 120]);
        $this->mockAuthService([$this->connectFailure(), $this->user()]);

        $startedAt = microtime(true);
        Yii::$app->passportAuth->validate('token-h');
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $this->assertGreaterThanOrEqual(100, $elapsedMs);
    }

    // =========================================================================
    // End to end
    // =========================================================================

    public function test_api_recovers_transparently_when_a_retry_succeeds(): void
    {
        $this->configureAuth(['retryDelayMs' => 0]);
        $this->mockAuthService([$this->connectFailure(), $this->user()]);

        $res = $this->get('/api/v1/tasks', auth: 'flaky-token');

        $this->assertSame(200, $res['status'], 'a recovered blip must not reach the client');
    }

    public function test_failed_call_is_logged_with_reason_and_attempts(): void
    {
        $this->configureAuth(['retryDelayMs' => 0]);
        $this->mockAuthService([$this->connectFailure(), $this->connectFailure()]);

        $this->get('/api/v1/tasks', auth: 'dead-token');

        $entry = $this->callLog();

        $this->assertSame('unavailable', $entry['outcome']);
        $this->assertSame(2, $entry['attempts']);
        $this->assertStringContainsString('Connection refused', $entry['reason']);
        $this->assertSame('error', $entry['level']);
    }
}
