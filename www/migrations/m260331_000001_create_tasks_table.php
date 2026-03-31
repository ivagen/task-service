<?php declare(strict_types=1);

use yii\db\Migration;

class m260331_000001_create_tasks_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('tasks', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'title' => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'status' => "ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo'",
            'priority' => $this->tinyInteger()->notNull()->defaultValue(1),
            'due_date' => $this->date()->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->createIndex('idx_tasks_user_id', 'tasks', 'user_id');
        $this->createIndex('idx_tasks_status', 'tasks', 'status');
        $this->createIndex('idx_tasks_priority', 'tasks', 'priority');
        $this->createIndex('idx_tasks_due_date', 'tasks', 'due_date');
    }

    public function safeDown(): void
    {
        $this->dropTable('tasks');
    }
}
