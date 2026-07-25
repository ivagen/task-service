<?php declare(strict_types=1);

namespace app\components;

use Yii;
use yii\web\Response;
use yii\base\Component;
use yii\web\Application;

/**
 * Records one structured line per HTTP request: method, path, status, duration.
 *
 * Hooks Response::EVENT_AFTER_SEND rather than the application's afterRequest,
 * because PassportAuthBehavior sends 401/429/503 responses itself — those must
 * be logged too, and send() fires the event exactly once.
 */
class AccessLogger extends Component
{
    public const CATEGORY = 'access';

    /** Paths excluded from the access log — probes would otherwise dominate it. */
    public array $excludePaths = ['api/v1/health', 'api/v1/ready'];

    public function attach(Application $app): void
    {
        $app->response->on(Response::EVENT_AFTER_SEND, function (): void {
            $this->log();
        });
    }

    public function log(): void
    {
        $request = Yii::$app->request;
        $response = Yii::$app->response;

        if (!$request instanceof \yii\web\Request || !$response instanceof Response) {
            return;
        }

        $path = trim((string)$request->getPathInfo(), '/');

        if (in_array($path, $this->excludePaths, true)) {
            return;
        }

        $entry = [
            'event' => 'request',
            'method' => $request->getMethod(),
            'path' => '/' . $path,
            'status' => $response->statusCode,
            'duration_ms' => $this->durationMs(),
        ];

        if (($query = $request->getQueryString()) !== '') {
            $entry['query'] = $query;
        }

        $userId = $this->userId();
        if ($userId !== null) {
            $entry['user_id'] = $userId;
        }

        // Server faults belong at error level so they surface in alerting.
        if ($response->statusCode >= 500) {
            Yii::error($entry, self::CATEGORY);

            return;
        }

        Yii::info($entry, self::CATEGORY);
    }

    private function durationMs(): int
    {
        return (int)round((microtime(true) - YII_BEGIN_TIME) * 1000);
    }

    private function userId(): ?int
    {
        if (!Yii::$app->has('user', true)) {
            return null;
        }

        $identity = Yii::$app->user->identity;

        return $identity === null ? null : (int)$identity->getId();
    }
}
