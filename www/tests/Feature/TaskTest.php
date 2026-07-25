<?php declare(strict_types=1);

namespace tests\Feature;

use Yii;
use tests\TestCase;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use app\components\RateLimiter;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

class TaskTest extends TestCase
{
    // =========================================================================
    // Authentication
    // =========================================================================

    public function test_request_without_token_returns_401(): void
    {
        $res = $this->get('/api/v1/tasks', auth: false);

        $this->assertSame(401, $res['status']);
        $this->assertFalse($res['body']['success']);
        $this->assertSame(401, $res['body']['error']['code']);
    }

    public function test_request_with_invalid_token_returns_401(): void
    {
        // Unknown token → not in cache → auth service answers 401.
        $this->mockAuthService([new Response(401, [], '{"message":"Unauthenticated."}')]);

        $res = $this->get('/api/v1/tasks', auth: 'invalid-token-xyz');

        $this->assertSame(401, $res['status']);
        $this->assertFalse($res['body']['success']);
        $this->assertSame('Invalid or expired token', $res['body']['error']['message']);
    }

    public function test_auth_service_connection_failure_returns_503(): void
    {
        // Two entries: the initial attempt plus the one retry.
        $this->mockAuthService([
            new ConnectException('Connection refused', new Request('GET', 'api/user')),
            new ConnectException('Connection refused', new Request('GET', 'api/user')),
        ]);

        $res = $this->get('/api/v1/tasks', auth: 'some-token');

        $this->assertSame(503, $res['status']);
        $this->assertFalse($res['body']['success']);
        $this->assertSame(503, $res['body']['error']['code']);
        $this->assertArrayHasKey('retry-after', $res['headers']);
    }

    public function test_auth_service_read_timeout_returns_503(): void
    {
        // A transfer-level timeout is not a ConnectException and is not retried,
        // so a single queued failure is enough.
        $this->mockAuthService([
            new RequestException(
                'cURL error 28: Operation timed out',
                new Request('GET', 'api/user'),
            ),
        ]);

        $res = $this->get('/api/v1/tasks', auth: 'some-token');

        $this->assertSame(503, $res['status']);
    }

    public function test_auth_service_server_error_returns_503_not_401(): void
    {
        $this->mockAuthService([new Response(500, [], 'Internal Server Error')]);

        $res = $this->get('/api/v1/tasks', auth: 'some-token');

        $this->assertSame(503, $res['status']);
    }

    public function test_valid_token_is_resolved_through_auth_service(): void
    {
        $this->mockAuthService([
            new Response(200, [], json_encode(['id' => 7, 'name' => 'Seven', 'email' => 's@example.com'])),
        ]);

        $this->createTask(['title' => 'Belongs to 7', 'user_id' => 7]);
        $this->createTask(['title' => 'Belongs to 1', 'user_id' => 1]);

        $res = $this->get('/api/v1/tasks', auth: 'fresh-token');

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
        $this->assertSame('Belongs to 7', $res['body']['data'][0]['title']);
    }

    public function test_responses_carry_rate_limit_headers(): void
    {
        $res = $this->get('/api/v1/tasks');

        $this->assertSame('60', $res['headers']['x-ratelimit-limit']);
        $this->assertSame('59', $res['headers']['x-ratelimit-remaining']);
    }

    public function test_rate_limit_exceeded_returns_429_with_retry_after(): void
    {
        Yii::$app->set('rateLimiter', [
            'class' => RateLimiter::class,
            'limit' => 3,
            'window' => 60,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->get('/api/v1/tasks')['status']);
        }

        $res = $this->get('/api/v1/tasks');

        $this->assertSame(429, $res['status']);
        $this->assertSame(429, $res['body']['error']['code']);
        $this->assertSame('0', $res['headers']['x-ratelimit-remaining']);
        $this->assertArrayHasKey('retry-after', $res['headers']);
        $this->assertGreaterThan(0, (int)$res['headers']['retry-after']);
    }

    public function test_rate_limit_window_does_not_slide_on_allowed_requests(): void
    {
        Yii::$app->set('rateLimiter', [
            'class' => RateLimiter::class,
            'limit' => 10,
            'window' => 60,
        ]);

        $first = Yii::$app->rateLimiter->hit('user-1');
        $second = Yii::$app->rateLimiter->hit('user-1');

        // A second hit must consume budget without extending the window.
        $this->assertSame(9, $first->remaining);
        $this->assertSame(8, $second->remaining);
        $this->assertLessThanOrEqual($first->limit, $second->limit);
    }

    // =========================================================================
    // GET /api/v1/tasks
    // =========================================================================

    public function test_index_returns_empty_list_for_new_user(): void
    {
        $res = $this->get('/api/v1/tasks');

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['success']);
        $this->assertSame([], $res['body']['data']);
        $this->assertSame(0, $res['body']['meta']['total']);
        $this->assertSame(1, $res['body']['meta']['page']);
        $this->assertSame(20, $res['body']['meta']['per_page']);
        $this->assertSame(0, $res['body']['meta']['total_pages']);
    }

    public function test_index_returns_only_own_tasks(): void
    {
        $this->createTask(['title' => 'My task']);
        $this->createTask(['title' => 'Other user task', 'user_id' => 999]);

        $res = $this->get('/api/v1/tasks');

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
        $this->assertSame('My task', $res['body']['data'][0]['title']);
        $this->assertSame(1, $res['body']['meta']['total']);
    }

    public function test_index_filter_by_status(): void
    {
        $this->createTask(['title' => 'Todo task',        'status' => 'todo']);
        $this->createTask(['title' => 'In progress task', 'status' => 'in_progress']);
        $this->createTask(['title' => 'Done task',        'status' => 'done']);

        $res = $this->get('/api/v1/tasks?status=in_progress');

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
        $this->assertSame('In progress task', $res['body']['data'][0]['title']);
    }

    public function test_index_filter_by_priority(): void
    {
        $this->createTask(['title' => 'Low',    'priority' => 1]);
        $this->createTask(['title' => 'Medium', 'priority' => 2]);
        $this->createTask(['title' => 'High',   'priority' => 3]);

        $res = $this->get('/api/v1/tasks?priority=2');

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
        $this->assertSame('Medium', $res['body']['data'][0]['title']);
    }

    public function test_index_filter_by_due_date_range(): void
    {
        $this->createTask(['title' => 'Early',  'due_date' => '2026-04-01']);
        $this->createTask(['title' => 'Middle', 'due_date' => '2026-04-15']);
        $this->createTask(['title' => 'Late',   'due_date' => '2026-04-30']);

        $res = $this->get('/api/v1/tasks?due_date_from=2026-04-10&due_date_to=2026-04-20');

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
        $this->assertSame('Middle', $res['body']['data'][0]['title']);
    }

    public function test_index_search_by_title(): void
    {
        $this->createTask(['title' => 'Buy milk']);
        $this->createTask(['title' => 'Write tests']);

        $res = $this->get('/api/v1/tasks?search=milk');

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
        $this->assertSame('Buy milk', $res['body']['data'][0]['title']);
    }

    public function test_index_search_by_description(): void
    {
        $this->createTask(['title' => 'Task 1', 'description' => 'important grocery run']);
        $this->createTask(['title' => 'Task 2', 'description' => 'write unit tests']);

        $res = $this->get('/api/v1/tasks?search=grocery');

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
        $this->assertSame('Task 1', $res['body']['data'][0]['title']);
    }

    public function test_index_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createTask(['title' => "Task $i"]);
        }

        $res = $this->get('/api/v1/tasks?page=2&per_page=2');

        $this->assertSame(200, $res['status']);
        $this->assertCount(2, $res['body']['data']);
        $this->assertSame(5, $res['body']['meta']['total']);
        $this->assertSame(2, $res['body']['meta']['page']);
        $this->assertSame(2, $res['body']['meta']['per_page']);
        $this->assertSame(3, $res['body']['meta']['total_pages']);
    }

    public function test_index_per_page_max_is_100(): void
    {
        $res = $this->get('/api/v1/tasks?per_page=999');

        $this->assertSame(200, $res['status']);
        $this->assertSame(100, $res['body']['meta']['per_page']);
    }

    public function test_index_is_sorted_newest_first_and_stable(): void
    {
        // Same created_at for every row: only the id tiebreaker makes the order
        // deterministic, which is what pagination relies on.
        $now = time();
        $ids = [];
        for ($i = 1; $i <= 6; $i++) {
            $ids[] = $this->createTask(['title' => "Task $i", 'created_at' => $now])['id'];
        }

        $expected = array_reverse($ids);

        $firstPage = $this->get('/api/v1/tasks?page=1&per_page=3');
        $secondPage = $this->get('/api/v1/tasks?page=2&per_page=3');

        $seen = array_merge(
            array_column($firstPage['body']['data'], 'id'),
            array_column($secondPage['body']['data'], 'id'),
        );

        $this->assertSame($expected, $seen);
        $this->assertSame($seen, array_unique($seen), 'pages must not repeat rows');
    }

    public function test_index_orders_newer_tasks_first(): void
    {
        $old = $this->createTask(['title' => 'Older', 'created_at' => 1000]);
        $new = $this->createTask(['title' => 'Newer', 'created_at' => 2000]);

        $res = $this->get('/api/v1/tasks');

        $this->assertSame([$new['id'], $old['id']], array_column($res['body']['data'], 'id'));
    }

    // =========================================================================
    // Query parameter validation
    // =========================================================================

    public function test_index_rejects_unknown_status(): void
    {
        $res = $this->get('/api/v1/tasks?status=nonsense');

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('status', $res['body']['error']['message']);
    }

    public function test_index_rejects_non_numeric_priority(): void
    {
        $res = $this->get('/api/v1/tasks?priority=abc');

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('priority', $res['body']['error']['message']);
    }

    public function test_index_rejects_out_of_range_priority(): void
    {
        $res = $this->get('/api/v1/tasks?priority=9');

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('priority', $res['body']['error']['message']);
    }

    public function test_index_rejects_malformed_date(): void
    {
        $res = $this->get('/api/v1/tasks?due_date_from=not-a-date');

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('due_date_from', $res['body']['error']['message']);
    }

    public function test_index_rejects_inverted_date_range(): void
    {
        $res = $this->get('/api/v1/tasks?due_date_from=2026-05-10&due_date_to=2026-05-01');

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('due_date_from', $res['body']['error']['message']);
    }

    public function test_index_accepts_equal_date_range_bounds(): void
    {
        $this->createTask(['title' => 'Exactly', 'due_date' => '2026-05-01']);

        $res = $this->get('/api/v1/tasks?due_date_from=2026-05-01&due_date_to=2026-05-01');

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
    }

    public function test_index_rejects_invalid_pagination_params(): void
    {
        $this->assertSame(422, $this->get('/api/v1/tasks?page=0')['status']);
        $this->assertSame(422, $this->get('/api/v1/tasks?page=abc')['status']);
        $this->assertSame(422, $this->get('/api/v1/tasks?per_page=-5')['status']);
    }

    public function test_index_ignores_unknown_query_params(): void
    {
        $this->createTask(['title' => 'Visible']);

        $res = $this->get('/api/v1/tasks?whatever=1&user_id=999');

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
    }

    public function test_index_search_supports_unicode(): void
    {
        $this->createTask(['title' => 'Купити молоко']);
        $this->createTask(['title' => 'Написати тести']);

        $res = $this->get('/api/v1/tasks?search=' . rawurlencode('молоко'));

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
        $this->assertSame('Купити молоко', $res['body']['data'][0]['title']);
    }

    // =========================================================================
    // POST /api/v1/tasks
    // =========================================================================

    public function test_create_task_with_valid_data(): void
    {
        $res = $this->post('/api/v1/tasks', [
            'title' => 'Buy groceries',
            'description' => 'Milk and bread',
            'status' => 'todo',
            'priority' => 2,
            'due_date' => '2026-12-31',
        ]);

        $this->assertSame(201, $res['status']);
        $this->assertTrue($res['body']['success']);
        $this->assertSame('Buy groceries', $res['body']['data']['title']);
        $this->assertSame('Milk and bread', $res['body']['data']['description']);
        $this->assertSame('todo', $res['body']['data']['status']);
        $this->assertSame(2, $res['body']['data']['priority']);
        $this->assertSame('2026-12-31', $res['body']['data']['due_date']);
        $this->assertSame(self::USER['id'], $res['body']['data']['user_id']);
    }

    public function test_create_task_uses_token_user_id_not_body(): void
    {
        $res = $this->post('/api/v1/tasks', [
            'title' => 'Hack attempt',
            'user_id' => 9999,
        ]);

        $this->assertSame(201, $res['status']);
        $this->assertSame(self::USER['id'], $res['body']['data']['user_id']);
    }

    public function test_create_task_with_minimal_data(): void
    {
        $res = $this->post('/api/v1/tasks', ['title' => 'Minimal task']);

        $this->assertSame(201, $res['status']);
        $this->assertSame('Minimal task', $res['body']['data']['title']);
        $this->assertSame('todo', $res['body']['data']['status']);
        $this->assertSame(1, $res['body']['data']['priority']);
        $this->assertNull($res['body']['data']['description']);
        $this->assertNull($res['body']['data']['due_date']);
    }

    public function test_create_task_requires_title(): void
    {
        $res = $this->post('/api/v1/tasks', ['description' => 'No title']);

        $this->assertSame(422, $res['status']);
        $this->assertFalse($res['body']['success']);
        $this->assertArrayHasKey('title', $res['body']['error']['message']);
    }

    public function test_create_task_with_invalid_status(): void
    {
        $res = $this->post('/api/v1/tasks', [
            'title' => 'Test',
            'status' => 'invalid_status',
        ]);

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('status', $res['body']['error']['message']);
    }

    public function test_create_task_with_invalid_priority(): void
    {
        $res = $this->post('/api/v1/tasks', [
            'title' => 'Test',
            'priority' => 5,
        ]);

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('priority', $res['body']['error']['message']);
    }

    public function test_create_task_rejects_past_due_date(): void
    {
        $res = $this->post('/api/v1/tasks', [
            'title' => 'Test',
            'due_date' => '2020-01-01',
        ]);

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('due_date', $res['body']['error']['message']);
    }

    public function test_create_task_rejects_too_long_title(): void
    {
        $res = $this->post('/api/v1/tasks', ['title' => str_repeat('a', 256)]);

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('title', $res['body']['error']['message']);
    }

    public function test_create_task_with_empty_body_returns_422(): void
    {
        $res = $this->post('/api/v1/tasks', []);

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('title', $res['body']['error']['message']);
    }

    // =========================================================================
    // GET /api/v1/tasks/{id}
    // =========================================================================

    public function test_view_own_task(): void
    {
        $task = $this->createTask(['title' => 'My task']);

        $res = $this->get('/api/v1/tasks/' . $task['id']);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['success']);
        $this->assertSame($task['id'], $res['body']['data']['id']);
        $this->assertSame('My task', $res['body']['data']['title']);
    }

    public function test_view_returns_correct_fields(): void
    {
        $task = $this->createTask([
            'title' => 'Full task',
            'description' => 'Details',
            'status' => 'in_progress',
            'priority' => 3,
            'due_date' => '2026-12-01',
        ]);

        $res = $this->get('/api/v1/tasks/' . $task['id']);

        $data = $res['body']['data'];
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('user_id', $data);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('description', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('priority', $data);
        $this->assertArrayHasKey('due_date', $data);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('updated_at', $data);
    }

    public function test_view_nonexistent_task_returns_404(): void
    {
        $res = $this->get('/api/v1/tasks/9999');

        $this->assertSame(404, $res['status']);
        $this->assertFalse($res['body']['success']);
        $this->assertSame(404, $res['body']['error']['code']);
    }

    public function test_view_other_users_task_returns_404(): void
    {
        $task = $this->createTask(['user_id' => 999, 'title' => 'Not mine']);

        $res = $this->get('/api/v1/tasks/' . $task['id']);

        $this->assertSame(404, $res['status']);
        $this->assertFalse($res['body']['success']);
        $this->assertSame(404, $res['body']['error']['code']);
    }

    public function test_foreign_task_is_indistinguishable_from_missing_task(): void
    {
        $foreign = $this->createTask(['user_id' => 999, 'title' => 'Not mine']);

        $foreignRes = $this->get('/api/v1/tasks/' . $foreign['id']);
        $missingRes = $this->get('/api/v1/tasks/987654');

        $this->assertSame($missingRes['status'], $foreignRes['status']);
        $this->assertSame($missingRes['body']['error']['message'], $foreignRes['body']['error']['message']);
    }

    // =========================================================================
    // PUT /api/v1/tasks/{id}
    // =========================================================================

    public function test_update_own_task(): void
    {
        $task = $this->createTask(['title' => 'Old title']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], [
            'title' => 'New title',
            'status' => 'in_progress',
        ]);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['success']);
        $this->assertSame('New title', $res['body']['data']['title']);
        $this->assertSame('in_progress', $res['body']['data']['status']);
    }

    public function test_update_cannot_change_user_id(): void
    {
        $task = $this->createTask(['title' => 'Test']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], [
            'title' => 'Test',
            'user_id' => 9999,
        ]);

        $this->assertSame(200, $res['status']);
        $this->assertSame(self::USER['id'], $res['body']['data']['user_id']);
    }

    public function test_update_nonexistent_task_returns_404(): void
    {
        $res = $this->put('/api/v1/tasks/9999', ['title' => 'Test']);

        $this->assertSame(404, $res['status']);
    }

    public function test_update_other_users_task_returns_404(): void
    {
        $task = $this->createTask(['user_id' => 999, 'title' => 'Not mine']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], ['title' => 'Hacked']);

        $this->assertSame(404, $res['status']);
    }

    public function test_update_with_invalid_status_returns_422(): void
    {
        $task = $this->createTask(['title' => 'Test']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], ['status' => 'wrong']);

        $this->assertSame(422, $res['status']);
    }

    public function test_update_rejects_past_due_date(): void
    {
        $task = $this->createTask(['title' => 'Test']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], [
            'title' => 'Test',
            'due_date' => '2020-01-01',
        ]);

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('due_date', $res['body']['error']['message']);
    }

    public function test_update_rejects_resubmitting_the_same_past_due_date(): void
    {
        $task = $this->createTask(['title' => 'Stale', 'due_date' => '2020-01-01']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], ['due_date' => '2020-01-01']);

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('due_date', $res['body']['error']['message']);
    }

    public function test_update_accepts_today_as_due_date(): void
    {
        $task = $this->createTask(['title' => 'Test']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], ['due_date' => date('Y-m-d')]);

        $this->assertSame(200, $res['status']);
        $this->assertSame(date('Y-m-d'), $res['body']['data']['due_date']);
    }

    public function test_update_of_other_fields_is_allowed_when_due_date_already_passed(): void
    {
        // A task whose due_date has since passed must stay editable as long as
        // the client is not (re)submitting that date.
        $task = $this->createTask(['title' => 'Overdue', 'due_date' => '2020-01-01']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], ['status' => 'done']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('done', $res['body']['data']['status']);
        $this->assertSame('2020-01-01', $res['body']['data']['due_date']);
    }

    public function test_update_rejects_too_long_title(): void
    {
        $task = $this->createTask(['title' => 'Test']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], ['title' => str_repeat('a', 256)]);

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('title', $res['body']['error']['message']);
    }

    public function test_update_with_empty_body_keeps_the_task_valid(): void
    {
        $task = $this->createTask(['title' => 'Unchanged']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], []);

        $this->assertSame(200, $res['status']);
        $this->assertSame('Unchanged', $res['body']['data']['title']);
    }

    // =========================================================================
    // DELETE /api/v1/tasks/{id}
    // =========================================================================

    public function test_delete_own_task_returns_204_with_empty_body(): void
    {
        $task = $this->createTask(['title' => 'To delete']);

        $res = $this->delete('/api/v1/tasks/' . $task['id']);

        $this->assertSame(204, $res['status']);
        $this->assertSame([], $res['body']);
        $this->assertSame('', $res['content']);

        // Verify actually deleted
        $check = $this->get('/api/v1/tasks/' . $task['id']);
        $this->assertSame(404, $check['status']);
    }

    public function test_delete_nonexistent_task_returns_404(): void
    {
        $res = $this->delete('/api/v1/tasks/9999');

        $this->assertSame(404, $res['status']);
    }

    public function test_delete_other_users_task_returns_404(): void
    {
        $task = $this->createTask(['user_id' => 999, 'title' => 'Not mine']);

        $res = $this->delete('/api/v1/tasks/' . $task['id']);

        $this->assertSame(404, $res['status']);
    }

    public function test_database_failure_on_delete_returns_500(): void
    {
        $task = $this->createTask(['title' => 'Undeletable']);

        // Raw PDO: Yii's SQLite command splits on ";" and would break the body.
        Yii::$app->db->open();
        Yii::$app->db->pdo->exec($this->isSqlite()
            ? "CREATE TRIGGER block_delete BEFORE DELETE ON tasks
               BEGIN SELECT RAISE(ABORT, 'delete blocked'); END"
            : "CREATE TRIGGER block_delete BEFORE DELETE ON tasks
               FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'delete blocked'", );

        try {
            $res = $this->delete('/api/v1/tasks/' . $task['id']);

            $this->assertSame(500, $res['status']);
            $this->assertFalse($res['body']['success']);
        } finally {
            Yii::$app->db->open();
            Yii::$app->db->pdo->exec('DROP TRIGGER IF EXISTS block_delete');
        }

        // The row must still be there.
        $this->assertSame(200, $this->get('/api/v1/tasks/' . $task['id'])['status']);
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function test_internal_errors_hide_exception_details_in_production(): void
    {
        $this->useErrorHandler(exposeDetails: false);

        Yii::$app->db->createCommand('DROP TABLE tasks')->execute();
        Yii::$app->db->schema->refresh();

        $res = $this->get('/api/v1/tasks');

        $this->assertSame(500, $res['status']);
        $this->assertSame('Internal Server Error', $res['body']['error']['message']);
        $this->assertArrayNotHasKey('detail', $res['body']['error']);
        $this->assertArrayNotHasKey('trace', $res['body']['error']);
        $this->assertArrayNotHasKey('exception', $res['body']['error']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $res['body']['error']['request_id']);
    }

    public function test_internal_errors_expose_details_in_debug_mode(): void
    {
        $this->useErrorHandler(exposeDetails: true);

        Yii::$app->db->createCommand('DROP TABLE tasks')->execute();
        Yii::$app->db->schema->refresh();

        $res = $this->get('/api/v1/tasks');

        $this->assertSame(500, $res['status']);
        $this->assertArrayHasKey('detail', $res['body']['error']);
        $this->assertArrayHasKey('trace', $res['body']['error']);
    }

    public function test_client_errors_keep_their_message(): void
    {
        $res = $this->get('/api/v1/tasks/9999');

        $this->assertSame(404, $res['status']);
        $this->assertSame('Task not found.', $res['body']['error']['message']);
    }

    // =========================================================================
    // Health endpoints
    // =========================================================================

    public function test_health_endpoint_needs_no_auth(): void
    {
        $res = $this->get('/api/v1/health', auth: false);

        $this->assertSame(200, $res['status']);
        $this->assertSame('ok', $res['body']['data']['status']);
    }

    public function test_ready_endpoint_reports_dependencies(): void
    {
        $res = $this->get('/api/v1/ready', auth: false);

        $this->assertSame(200, $res['status']);
        $this->assertSame('ready', $res['body']['data']['status']);
        $this->assertTrue($res['body']['data']['checks']['db']['ok']);
        $this->assertTrue($res['body']['data']['checks']['cache']['ok']);
    }

    public function test_ready_endpoint_returns_503_when_database_is_broken(): void
    {
        Yii::$app->db->close();
        Yii::$app->set('db', ['class' => 'yii\db\Connection', 'dsn' => 'sqlite:/nonexistent/path/db.sqlite']);

        $res = $this->get('/api/v1/ready', auth: false);

        $this->assertSame(503, $res['status']);
        $this->assertFalse($res['body']['data']['checks']['db']['ok']);
    }

    public function test_delete_does_not_affect_other_tasks(): void
    {
        $task1 = $this->createTask(['title' => 'Keep me']);
        $task2 = $this->createTask(['title' => 'Delete me']);

        $this->delete('/api/v1/tasks/' . $task2['id']);

        $res = $this->get('/api/v1/tasks');
        $this->assertSame(1, $res['body']['meta']['total']);
        $this->assertSame('Keep me', $res['body']['data'][0]['title']);
    }
}
