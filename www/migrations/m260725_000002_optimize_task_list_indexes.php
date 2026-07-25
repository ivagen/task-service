<?php declare(strict_types=1);

use yii\db\Migration;

/**
 * Replaces single-column indexes with indexes matching the tenant-scoped list
 * queries exposed by TaskSearch.
 *
 * The number of secondary indexes stays unchanged: reads gain useful prefixes
 * while writes do not acquire another set of indexes to maintain.
 */
class m260725_000002_optimize_task_list_indexes extends Migration
{
    public function safeUp(): void
    {
        $this->dropIndex('idx_tasks_user_id', 'tasks');
        $this->dropIndex('idx_tasks_status', 'tasks');
        $this->dropIndex('idx_tasks_priority', 'tasks');
        $this->dropIndex('idx_tasks_due_date', 'tasks');

        // Default list: WHERE user_id = ? ORDER BY created_at DESC, id DESC.
        $this->createIndex(
            'idx_tasks_user_created_id',
            'tasks',
            ['user_id', 'created_at', 'id'],
        );

        // Equality filters retain the requested stable list order.
        $this->createIndex(
            'idx_tasks_user_status_created_id',
            'tasks',
            ['user_id', 'status', 'created_at', 'id'],
        );
        $this->createIndex(
            'idx_tasks_user_priority_created_id',
            'tasks',
            ['user_id', 'priority', 'created_at', 'id'],
        );

        // Date-range scans use the tenant and due-date prefix. Once MySQL enters
        // a range it may still sort by created_at/id, but it scans only the
        // matching user's date interval instead of the whole table.
        $this->createIndex(
            'idx_tasks_user_due_date',
            'tasks',
            ['user_id', 'due_date'],
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_tasks_user_created_id', 'tasks');
        $this->dropIndex('idx_tasks_user_status_created_id', 'tasks');
        $this->dropIndex('idx_tasks_user_priority_created_id', 'tasks');
        $this->dropIndex('idx_tasks_user_due_date', 'tasks');

        $this->createIndex('idx_tasks_user_id', 'tasks', 'user_id');
        $this->createIndex('idx_tasks_status', 'tasks', 'status');
        $this->createIndex('idx_tasks_priority', 'tasks', 'priority');
        $this->createIndex('idx_tasks_due_date', 'tasks', 'due_date');
    }
}
