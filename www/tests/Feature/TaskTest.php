<?php

namespace tests\Feature;

use tests\TestCase;

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
        // Token not in cache → PassportAuth will try Guzzle → fail (no real server)
        // But ArrayCache returns false for unknown keys, and Guzzle will throw
        // (connect refused) → returns false → 401
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token-xyz';

        $res = $this->get('/api/v1/tasks', auth: false);

        $this->assertSame(401, $res['status']);
        $this->assertFalse($res['body']['success']);
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

    // =========================================================================
    // POST /api/v1/tasks
    // =========================================================================

    public function test_create_task_with_valid_data(): void
    {
        $res = $this->post('/api/v1/tasks', [
            'title'       => 'Buy groceries',
            'description' => 'Milk and bread',
            'status'      => 'todo',
            'priority'    => 2,
            'due_date'    => '2026-12-31',
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
            'title'   => 'Hack attempt',
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
            'title'  => 'Test',
            'status' => 'invalid_status',
        ]);

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('status', $res['body']['error']['message']);
    }

    public function test_create_task_with_invalid_priority(): void
    {
        $res = $this->post('/api/v1/tasks', [
            'title'    => 'Test',
            'priority' => 5,
        ]);

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('priority', $res['body']['error']['message']);
    }

    public function test_create_task_rejects_past_due_date(): void
    {
        $res = $this->post('/api/v1/tasks', [
            'title'    => 'Test',
            'due_date' => '2020-01-01',
        ]);

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('due_date', $res['body']['error']['message']);
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
            'title'       => 'Full task',
            'description' => 'Details',
            'status'      => 'in_progress',
            'priority'    => 3,
            'due_date'    => '2026-12-01',
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

    public function test_view_other_users_task_returns_403(): void
    {
        $task = $this->createTask(['user_id' => 999, 'title' => 'Not mine']);

        $res = $this->get('/api/v1/tasks/' . $task['id']);

        $this->assertSame(403, $res['status']);
        $this->assertFalse($res['body']['success']);
        $this->assertSame(403, $res['body']['error']['code']);
    }

    // =========================================================================
    // PUT /api/v1/tasks/{id}
    // =========================================================================

    public function test_update_own_task(): void
    {
        $task = $this->createTask(['title' => 'Old title']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], [
            'title'  => 'New title',
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
            'title'   => 'Test',
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

    public function test_update_other_users_task_returns_403(): void
    {
        $task = $this->createTask(['user_id' => 999, 'title' => 'Not mine']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], ['title' => 'Hacked']);

        $this->assertSame(403, $res['status']);
    }

    public function test_update_with_invalid_status_returns_422(): void
    {
        $task = $this->createTask(['title' => 'Test']);

        $res = $this->put('/api/v1/tasks/' . $task['id'], ['status' => 'wrong']);

        $this->assertSame(422, $res['status']);
    }

    // =========================================================================
    // DELETE /api/v1/tasks/{id}
    // =========================================================================

    public function test_delete_own_task(): void
    {
        $task = $this->createTask(['title' => 'To delete']);

        $res = $this->delete('/api/v1/tasks/' . $task['id']);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['success']);
        $this->assertNull($res['body']['data']);

        // Verify actually deleted
        $check = $this->get('/api/v1/tasks/' . $task['id']);
        $this->assertSame(404, $check['status']);
    }

    public function test_delete_nonexistent_task_returns_404(): void
    {
        $res = $this->delete('/api/v1/tasks/9999');

        $this->assertSame(404, $res['status']);
    }

    public function test_delete_other_users_task_returns_403(): void
    {
        $task = $this->createTask(['user_id' => 999, 'title' => 'Not mine']);

        $res = $this->delete('/api/v1/tasks/' . $task['id']);

        $this->assertSame(403, $res['status']);
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