<?php declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property int $priority
 * @property string|null $due_date
 * @property int $created_at
 * @property int $updated_at
 */
class Task extends ActiveRecord
{
    public const SCENARIO_CREATE = 'create';
    public const SCENARIO_UPDATE = 'update';

    public const STATUSES = ['todo', 'in_progress', 'done'];
    public const PRIORITIES = [1, 2, 3];

    /**
     * Whether the client explicitly sent `due_date` in the request body.
     *
     * On update the future-date rule only applies to a date the client actually
     * submitted, so a task whose due_date has since passed stays editable.
     */
    public bool $dueDateSubmitted = false;

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
            [['status'], 'in', 'range' => self::STATUSES],
            [['priority'], 'integer'],
            [['priority'], 'in', 'range' => self::PRIORITIES],
            [['due_date'], 'date', 'format' => 'php:Y-m-d'],
            [['due_date'], 'validateDueDateFuture', 'on' => [self::SCENARIO_CREATE, self::SCENARIO_UPDATE]],
            [['description', 'status', 'priority', 'due_date'], 'default', 'value' => null],
            [['status'], 'default', 'value' => 'todo'],
            [['priority'], 'default', 'value' => 1],
        ];
    }

    public function validateDueDateFuture(string $attribute): void
    {
        if (!$this->isNewRecord && !$this->dueDateSubmitted) {
            return;
        }

        if ($this->$attribute === null || $this->$attribute === '') {
            return;
        }

        if ($this->$attribute < date('Y-m-d')) {
            $this->addError($attribute, 'due_date must be today or in the future.');
        }
    }

    public function scenarios(): array
    {
        return array_merge(parent::scenarios(), [
            self::SCENARIO_CREATE => ['title', 'description', 'status', 'priority', 'due_date'],
            self::SCENARIO_UPDATE => ['title', 'description', 'status', 'priority', 'due_date'],
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
