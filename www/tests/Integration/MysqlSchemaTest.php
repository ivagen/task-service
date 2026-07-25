<?php declare(strict_types=1);

namespace tests\Integration;

use Yii;
use tests\TestCase;

/**
 * Exercises what SQLite cannot reproduce: the real migration on MySQL 8,
 * ENUM semantics, strict mode, utf8mb4 collation and the actual indexes.
 *
 * Skipped unless TEST_DB_DSN points at MySQL.
 */
class MysqlSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->isSqlite()) {
            $this->markTestSkipped('Requires MySQL: set TEST_DB_DSN.');
        }
    }

    public function test_migration_creates_the_expected_columns(): void
    {
        $table = Yii::$app->db->schema->getTableSchema('tasks', true);

        $this->assertNotNull($table);
        $this->assertSame(['id'], $table->primaryKey);
        $this->assertEqualsCanonicalizing([
            'id', 'user_id', 'title', 'description',
            'status', 'priority', 'due_date', 'created_at', 'updated_at',
        ], $table->getColumnNames());

        $this->assertTrue($table->getColumn('id')->autoIncrement);
        $this->assertFalse($table->getColumn('user_id')->allowNull);
        $this->assertFalse($table->getColumn('title')->allowNull);
        $this->assertTrue($table->getColumn('due_date')->allowNull);
    }

    public function test_status_column_is_an_enum_with_the_documented_values(): void
    {
        $column = Yii::$app->db->schema->getTableSchema('tasks', true)?->getColumn('status');

        $this->assertNotNull($column);
        $this->assertStringStartsWith('enum', strtolower((string)$column->dbType));
        $this->assertEqualsCanonicalizing(['todo', 'in_progress', 'done'], $column->enumValues);
        $this->assertSame('todo', $column->defaultValue);
    }

    public function test_migration_creates_the_declared_indexes(): void
    {
        $indexes = Yii::$app->db
            ->createCommand('SHOW INDEX FROM tasks')
            ->queryAll();

        $names = array_unique(array_column($indexes, 'Key_name'));

        $expectedIndexes = [
            'idx_tasks_user_created_id' => ['user_id', 'created_at', 'id'],
            'idx_tasks_user_status_created_id' => ['user_id', 'status', 'created_at', 'id'],
            'idx_tasks_user_priority_created_id' => ['user_id', 'priority', 'created_at', 'id'],
            'idx_tasks_user_due_date' => ['user_id', 'due_date'],
        ];

        foreach ($expectedIndexes as $name => $columns) {
            $this->assertContains($name, $names);

            $actualColumns = array_column(
                array_filter($indexes, static fn (array $index): bool => $index['Key_name'] === $name),
                'Column_name',
            );

            $this->assertSame($columns, $actualColumns, "Unexpected column order for {$name}");
        }
    }

    public function test_database_rejects_a_status_outside_the_enum(): void
    {
        $this->expectException(\yii\db\Exception::class);

        Yii::$app->db->createCommand()->insert('tasks', [
            'user_id' => 1,
            'title' => 'Bad status',
            'status' => 'archived',
            'priority' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();
    }

    public function test_strict_mode_rejects_an_overlong_title(): void
    {
        $this->expectException(\yii\db\Exception::class);

        Yii::$app->db->createCommand()->insert('tasks', [
            'user_id' => 1,
            'title' => str_repeat('a', 256),
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();
    }

    public function test_utf8mb4_round_trips_multibyte_text(): void
    {
        // Cyrillic plus a 4-byte emoji: utf8 (3-byte) would truncate or fail.
        $title = 'Купити молоко 🥛';

        $res = $this->post('/api/v1/tasks', ['title' => $title]);
        $this->assertSame(201, $res['status']);

        $stored = $this->get('/api/v1/tasks/' . $res['body']['data']['id']);
        $this->assertSame($title, $stored['body']['data']['title']);
    }

    public function test_unicode_search_matches_through_the_api(): void
    {
        $this->post('/api/v1/tasks', ['title' => 'Купити молоко']);
        $this->post('/api/v1/tasks', ['title' => 'Написати тести']);

        $res = $this->get('/api/v1/tasks?search=' . rawurlencode('молоко'));

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
    }

    public function test_pagination_is_stable_on_mysql(): void
    {
        $now = time();
        $ids = [];
        for ($i = 1; $i <= 6; $i++) {
            $ids[] = $this->createTask(['title' => "Task $i", 'created_at' => $now])['id'];
        }

        $seen = array_merge(
            array_column($this->get('/api/v1/tasks?page=1&per_page=3')['body']['data'], 'id'),
            array_column($this->get('/api/v1/tasks?page=2&per_page=3')['body']['data'], 'id'),
        );

        $this->assertSame(array_reverse($ids), $seen);
    }
}
