<?php declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\models\Task;
use yii\web\Response;
use yii\web\Controller;
use app\models\TaskSearch;
use yii\web\NotFoundHttpException;
use yii\web\ServerErrorHttpException;
use app\behaviors\PassportAuthBehavior;

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

        $searchModel = new TaskSearch();
        $searchModel->load(Yii::$app->request->queryParams, '');

        if (!$searchModel->validate()) {
            return $this->unprocessable($searchModel->errors);
        }

        return ['success' => true] + $searchModel->search($this->userId());
    }

    public function actionCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $body = Yii::$app->request->getBodyParams();

        $task = new Task();
        $task->setScenario(Task::SCENARIO_CREATE);
        $task->load($body, '');
        $task->user_id = $this->userId();

        if (!$task->save()) {
            return $this->unprocessable($task->errors);
        }

        Yii::$app->response->statusCode = 201;

        return ['success' => true, 'data' => $task->toArray()];
    }

    public function actionView(int $id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        return ['success' => true, 'data' => $this->findOwnTask($id)->toArray()];
    }

    public function actionUpdate(int $id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $task = $this->findOwnTask($id);
        $body = Yii::$app->request->getBodyParams();

        $task->setScenario(Task::SCENARIO_UPDATE);
        $task->dueDateSubmitted = array_key_exists('due_date', $body);
        $task->load($body, '');
        $task->user_id = $task->getOldAttribute('user_id');

        if (!$task->save()) {
            return $this->unprocessable($task->errors);
        }

        return ['success' => true, 'data' => $task->toArray()];
    }

    public function actionDelete(int $id): void
    {
        $task = $this->findOwnTask($id);

        // delete() returns false (blocked) or the number of affected rows.
        if (!$task->delete()) {
            throw new ServerErrorHttpException('Failed to delete task ' . $id . '.');
        }

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->statusCode = 204;
        $response->content = '';
    }

    /**
     * Looks the task up by id *and* owner, so a foreign id is indistinguishable
     * from a missing one and cannot be used to probe other users' tasks.
     */
    private function findOwnTask(int $id): Task
    {
        $task = Task::findOne([
            'id' => $id,
            'user_id' => $this->userId(),
        ]);

        if ($task === null) {
            throw new NotFoundHttpException('Task not found.');
        }

        return $task;
    }

    private function userId(): int
    {
        return (int)Yii::$app->user->identity->getId();
    }

    private function unprocessable(array $errors): array
    {
        Yii::$app->response->statusCode = 422;

        return [
            'success' => false,
            'error' => ['code' => 422, 'message' => $errors],
        ];
    }
}
