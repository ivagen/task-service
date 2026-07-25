<?php declare(strict_types=1);

namespace tests\Feature;

use Yii;
use tests\TestCase;
use yii\log\Logger;
use app\components\RequestId;
use tests\CapturingLogTarget;
use app\components\JsonLogTarget;

class LoggingTest extends TestCase
{
    /** @return array<string, mixed> */
    private function format(mixed $text, int $level = Logger::LEVEL_INFO, string $category = 'application'): array
    {
        $target = new JsonLogTarget();
        $line = $target->formatMessage([$text, $level, $category, 1750000000.123, [], 0]);

        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded, "not valid JSON: {$line}");

        return $decoded;
    }

    // =========================================================================
    // Format
    // =========================================================================

    public function test_each_line_is_a_single_json_object(): void
    {
        $target = new JsonLogTarget();
        $line = $target->formatMessage(['hello', Logger::LEVEL_INFO, 'application', 1750000000.0, [], 0]);

        $this->assertStringNotContainsString("\n", $line);
        $this->assertIsArray(json_decode($line, true));
    }

    public function test_envelope_carries_level_category_and_timestamp(): void
    {
        $entry = $this->format('something happened', Logger::LEVEL_WARNING, 'passport');

        $this->assertSame('warning', $entry['level']);
        $this->assertSame('passport', $entry['category']);
        $this->assertSame('task-service', $entry['service']);
        $this->assertSame('something happened', $entry['message']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $entry['timestamp'],
        );
    }

    public function test_every_line_carries_the_request_id(): void
    {
        $entry = $this->format('anything');

        $this->assertSame(RequestId::get(), $entry['request_id']);
    }

    public function test_array_messages_become_structured_fields(): void
    {
        $entry = $this->format(['event' => 'request', 'status' => 204, 'duration_ms' => 7]);

        $this->assertSame('request', $entry['event']);
        $this->assertSame(204, $entry['status']);
        $this->assertSame(7, $entry['duration_ms']);
    }

    public function test_array_messages_cannot_override_the_envelope(): void
    {
        $entry = $this->format([
            'event' => 'request',
            'level' => 'debug',
            'category' => 'spoofed',
            'request_id' => 'spoofed',
        ], Logger::LEVEL_ERROR, 'access');

        $this->assertSame('error', $entry['level']);
        $this->assertSame('access', $entry['category']);
        $this->assertNotSame('spoofed', $entry['request_id']);
    }

    public function test_exceptions_are_expanded(): void
    {
        $entry = $this->format(new \RuntimeException('boom'), Logger::LEVEL_ERROR);

        $this->assertSame('boom', $entry['message']);
        $this->assertSame(\RuntimeException::class, $entry['exception']);
        $this->assertArrayHasKey('file', $entry);
        $this->assertIsArray($entry['trace']);
    }

    public function test_unicode_is_not_escaped(): void
    {
        $entry = $this->format('Купити молоко');

        $this->assertSame('Купити молоко', $entry['message']);
    }

    /**
     * The default Target dumps $GLOBALS, which inside a container includes
     * $_SERVER with DB_PASSWORD and every other secret.
     */
    public function test_globals_are_never_dumped_into_logs(): void
    {
        $this->assertSame([], (new JsonLogTarget())->logVars);
    }

    // =========================================================================
    // Access log
    // =========================================================================

    /** @return array<string, mixed> */
    private function accessEntry(): array
    {
        $entries = CapturingLogTarget::entriesOfCategory('access');
        $this->assertNotEmpty($entries, 'no access log entry was written');

        return $entries[array_key_last($entries)];
    }

    public function test_successful_request_is_logged_with_status_and_duration(): void
    {
        $this->get('/api/v1/tasks');

        $entry = $this->accessEntry();

        $this->assertSame('request', $entry['event']);
        $this->assertSame('GET', $entry['method']);
        $this->assertSame('/api/v1/tasks', $entry['path']);
        $this->assertSame(200, $entry['status']);
        $this->assertSame('info', $entry['level']);
        $this->assertIsInt($entry['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $entry['duration_ms']);
    }

    public function test_access_log_records_the_authenticated_user(): void
    {
        $this->get('/api/v1/tasks');

        $this->assertSame(self::USER['id'], $this->accessEntry()['user_id']);
    }

    public function test_access_log_records_the_query_string(): void
    {
        $this->get('/api/v1/tasks?status=todo&per_page=5');

        $this->assertSame('status=todo&per_page=5', $this->accessEntry()['query']);
    }

    public function test_created_resource_is_logged_with_201(): void
    {
        $this->post('/api/v1/tasks', ['title' => 'Logged']);

        $this->assertSame(201, $this->accessEntry()['status']);
        $this->assertSame('POST', $this->accessEntry()['method']);
    }

    /** Responses sent by the auth behavior bypass the action entirely. */
    public function test_unauthorised_request_is_still_logged(): void
    {
        $this->get('/api/v1/tasks', auth: false);

        $entry = $this->accessEntry();

        $this->assertSame(401, $entry['status']);
        $this->assertArrayNotHasKey('user_id', $entry);
    }

    public function test_rate_limited_request_is_logged(): void
    {
        Yii::$app->set('rateLimiter', [
            'class' => \app\components\RateLimiter::class,
            'limit' => 1,
            'window' => 60,
        ]);

        $this->get('/api/v1/tasks');
        $this->get('/api/v1/tasks');

        $this->assertSame(429, $this->accessEntry()['status']);
    }

    public function test_server_errors_are_logged_at_error_level(): void
    {
        Yii::$app->db->createCommand('DROP TABLE tasks')->execute();
        Yii::$app->db->schema->refresh();

        $this->get('/api/v1/tasks');

        $entry = $this->accessEntry();

        $this->assertSame(500, $entry['status']);
        $this->assertSame('error', $entry['level'], '5xx must be alertable');
    }

    public function test_health_probes_are_excluded_from_the_access_log(): void
    {
        $this->get('/api/v1/health', auth: false);
        $this->get('/api/v1/ready', auth: false);

        $this->assertSame([], CapturingLogTarget::entriesOfCategory('access'));
    }

    public function test_access_log_shares_the_request_id_with_the_error_body(): void
    {
        $res = $this->get('/api/v1/tasks/999999');

        $this->assertSame(
            $res['body']['error']['request_id'],
            $this->accessEntry()['request_id'],
            'a log line must be findable from the id the client was given',
        );
    }
}
