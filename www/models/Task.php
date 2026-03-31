<?php declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;

class Task extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'tasks';
    }

    public function behaviors(): array
    {
        return [
            TimestampBehavior::class,
        ];
    }

    public function rules(): array
    {
        return [
            [['title'], 'required'],
            [['title'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['status'], 'in', 'range' => ['todo', 'in_progress', 'done']],
            [['priority'], 'integer'],
            [['priority'], 'in', 'range' => [1, 2, 3]],
            [['due_date'], 'date', 'format' => 'php:Y-m-d'],
            [['due_date'], 'validateDueDateFuture', 'on' => 'create'],
            [['description', 'status', 'priority', 'due_date'], 'default', 'value' => null],
            [['status'], 'default', 'value' => 'todo'],
            [['priority'], 'default', 'value' => 1],
        ];
    }

    public function validateDueDateFuture(string $attribute): void
    {
        if ($this->$attribute !== null && $this->$attribute < date('Y-m-d')) {
            $this->addError($attribute, 'due_date must be today or in the future.');
        }
    }

    public function scenarios(): array
    {
        return array_merge(parent::scenarios(), [
            'create' => ['title', 'description', 'status', 'priority', 'due_date'],
            'update' => ['title', 'description', 'status', 'priority', 'due_date'],
        ]);
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => (int)$this->id,
            'user_id' => (int)$this->user_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => (int)$this->priority,
            'due_date' => $this->due_date,
            'created_at' => (int)$this->created_at,
            'updated_at' => (int)$this->updated_at,
        ];
    }
}
