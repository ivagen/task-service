<?php

namespace app\controllers;

use app\behaviors\PassportAuthBehavior;
use app\models\Task;
use app\models\TaskSearch;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class TaskController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'passportAuth' => PassportAuthBehavior::class,
        ]);
    }

    public function actionIndex(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $userId = Yii::$app->user->identity->getId();
        $result = (new TaskSearch())->search($userId, Yii::$app->request->queryParams);

        return ['success' => true] + $result;
    }

    public function actionCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $userId = Yii::$app->user->identity->getId();
        $body   = Yii::$app->request->getBodyParams();

        $task = new Task();
        $task->setScenario('create');
        $task->load($body, '');
        $task->user_id = $userId;

        if (!$task->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'error'   => ['code' => 422, 'message' => $task->errors],
            ];
        }

        Yii::$app->response->statusCode = 201;
        return ['success' => true, 'data' => $task->toArray()];
    }

    public function actionView(int $id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $task = $this->findOwnTask($id);

        return ['success' => true, 'data' => $task->toArray()];
    }

    public function actionUpdate(int $id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $task = $this->findOwnTask($id);
        $body = Yii::$app->request->getBodyParams();

        $task->setScenario('update');
        $task->load($body, '');
        $task->user_id = $task->getOldAttribute('user_id');

        if (!$task->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'error'   => ['code' => 422, 'message' => $task->errors],
            ];
        }

        return ['success' => true, 'data' => $task->toArray()];
    }

    public function actionDelete(int $id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $task = $this->findOwnTask($id);
        $task->delete();

        return ['success' => true, 'data' => null];
    }

    private function findOwnTask(int $id): Task
    {
        $task = Task::findOne($id);

        if ($task === null) {
            throw new NotFoundHttpException('Task not found.');
        }

        $userId = Yii::$app->user->identity->getId();
        if ((int)$task->user_id !== $userId) {
            throw new ForbiddenHttpException('Access denied.');
        }

        return $task;
    }
}