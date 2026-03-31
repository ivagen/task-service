<?php

namespace app\models;

use yii\db\ActiveQuery;

class TaskSearch
{
    public const DEFAULT_PAGE     = 1;
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE     = 100;

    public function search(int $userId, array $params): array
    {
        $query = Task::find()->where(['user_id' => $userId]);

        $this->applyFilters($query, $params);

        $total   = (int)$query->count();
        $page    = max(1, (int)($params['page'] ?? self::DEFAULT_PAGE));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int)($params['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $offset  = ($page - 1) * $perPage;

        $tasks = $query->limit($perPage)->offset($offset)->all();

        return [
            'data' => array_map(fn(Task $t) => $t->toArray(), $tasks),
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ];
    }

    private function applyFilters(ActiveQuery $query, array $params): void
    {
        if (!empty($params['status'])) {
            $query->andWhere(['status' => $params['status']]);
        }

        if (!empty($params['priority'])) {
            $query->andWhere(['priority' => (int)$params['priority']]);
        }

        if (!empty($params['due_date_from'])) {
            $query->andWhere(['>=', 'due_date', $params['due_date_from']]);
        }

        if (!empty($params['due_date_to'])) {
            $query->andWhere(['<=', 'due_date', $params['due_date_to']]);
        }

        if (!empty($params['search'])) {
            $like = '%' . addcslashes($params['search'], '%_\\') . '%';
            $query->andWhere(['or',
                ['like', 'title', $like, false],
                ['like', 'description', $like, false],
            ]);
        }
    }
}