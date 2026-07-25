<?php declare(strict_types=1);

namespace app\models;

use yii\base\Model;
use yii\db\ActiveQuery;

class TaskSearch extends Model
{
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    public $status;
    public $priority;
    public $due_date_from;
    public $due_date_to;
    public $search;
    public $page;
    public $per_page;

    public function rules(): array
    {
        return [
            [['status'], 'in', 'range' => Task::STATUSES],
            [['priority'], 'integer'],
            [['priority'], 'in', 'range' => Task::PRIORITIES],
            [['due_date_from', 'due_date_to'], 'date', 'format' => 'php:Y-m-d'],
            [['due_date_from'], 'validateDateRange'],
            [['search'], 'string', 'max' => 255],
            // per_page above the maximum is clamped rather than rejected.
            [['page', 'per_page'], 'integer', 'min' => 1],
        ];
    }

    public function validateDateRange(string $attribute): void
    {
        if ($this->hasErrors('due_date_from') || $this->hasErrors('due_date_to')) {
            return;
        }

        if (empty($this->due_date_from) || empty($this->due_date_to)) {
            return;
        }

        if ($this->due_date_from > $this->due_date_to) {
            $this->addError($attribute, 'due_date_from must be earlier than or equal to due_date_to.');
        }
    }

    public function search(int $userId): array
    {
        $query = Task::find()->where(['user_id' => $userId]);

        $this->applyFilters($query);

        $total = (int)$query->count();
        $page = $this->resolvedPage();
        $perPage = $this->resolvedPerPage();

        // Without a deterministic order the database may repeat or skip rows
        // across pages. created_at alone is not unique, so id breaks the tie.
        $tasks = $query
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->all();

        return [
            'data' => array_map(fn (Task $t) => $t->toArray(), $tasks),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ];
    }

    private function resolvedPage(): int
    {
        return max(1, (int)($this->page ?: self::DEFAULT_PAGE));
    }

    private function resolvedPerPage(): int
    {
        $perPage = (int)($this->per_page ?: self::DEFAULT_PER_PAGE);

        return min(self::MAX_PER_PAGE, max(1, $perPage));
    }

    private function applyFilters(ActiveQuery $query): void
    {
        if (!empty($this->status)) {
            $query->andWhere(['status' => $this->status]);
        }

        if (!empty($this->priority)) {
            $query->andWhere(['priority' => (int)$this->priority]);
        }

        if (!empty($this->due_date_from)) {
            $query->andWhere(['>=', 'due_date', $this->due_date_from]);
        }

        if (!empty($this->due_date_to)) {
            $query->andWhere(['<=', 'due_date', $this->due_date_to]);
        }

        if (!empty($this->search)) {
            $like = '%' . addcslashes((string)$this->search, '%_\\') . '%';
            $query->andWhere(['or',
                ['like', 'title', $like, false],
                ['like', 'description', $like, false],
            ]);
        }
    }
}
