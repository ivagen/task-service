<?php declare(strict_types=1);

namespace app\controllers;

use Yii;
use yii\web\Response;
use yii\web\Controller;

/**
 * Unauthenticated liveness/readiness probes.
 */
class HealthController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionLive(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        return ['success' => true, 'data' => ['status' => 'ok']];
    }

    public function actionReady(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $checks = [
            'db' => $this->check(fn () => Yii::$app->db->createCommand('SELECT 1')->queryScalar()),
            'cache' => $this->check(function () {
                $key = 'health_probe';
                Yii::$app->cache->set($key, 1, 5);

                return Yii::$app->cache->get($key) !== false;
            }),
        ];

        $healthy = !in_array(false, array_column($checks, 'ok'), true);

        if (!$healthy) {
            Yii::$app->response->statusCode = 503;
        }

        return [
            'success' => $healthy,
            'data' => ['status' => $healthy ? 'ready' : 'not_ready', 'checks' => $checks],
        ];
    }

    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true];
        } catch (\Throwable $e) {
            Yii::error('Readiness probe failed: ' . $e->getMessage(), 'health');

            // The reason is logged, not returned — probes are publicly reachable.
            return ['ok' => false];
        }
    }
}
