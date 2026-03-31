<?php declare(strict_types=1);

namespace app\behaviors;

use Yii;
use yii\base\Behavior;
use yii\web\Controller;
use app\components\User;
use yii\base\ActionEvent;
use app\components\PassportAuth;

class PassportAuthBehavior extends Behavior
{
    public function events(): array
    {
        return [
            Controller::EVENT_BEFORE_ACTION => 'beforeAction',
        ];
    }

    public function beforeAction(ActionEvent $event): void
    {
        $request = Yii::$app->request;
        $token = $this->extractToken($request->getHeaders()->get('Authorization', ''));

        if ($token === null) {
            $this->respondUnauthorized('Missing or invalid Authorization header');
            $event->isValid = false;

            return;
        }

        $userData = PassportAuth::validate($token);

        if ($userData === false) {
            $this->respondUnauthorized('Invalid or expired token');
            $event->isValid = false;

            return;
        }

        $identity = new User($userData);
        Yii::$app->user->setIdentity($identity);

        if (!$this->checkRateLimit($identity->id)) {
            $this->respondTooManyRequests();
            $event->isValid = false;

            return;
        }
    }

    private function extractToken(string $header): ?string
    {
        if (str_starts_with($header, 'Bearer ')) {
            $token = trim(substr($header, 7));

            return $token !== '' ? $token : null;
        }

        return null;
    }

    private function checkRateLimit(int $userId): bool
    {
        $cacheKey = 'rate_limit_' . $userId;
        $count = (int)Yii::$app->cache->get($cacheKey);

        if ($count === 0) {
            Yii::$app->cache->set($cacheKey, 1, 60);

            return true;
        }

        if ($count >= 60) {
            return false;
        }

        Yii::$app->cache->set($cacheKey, $count + 1, 60);

        return true;
    }

    private function respondUnauthorized(string $message): void
    {
        $response = Yii::$app->response;
        $response->statusCode = 401;
        $response->data = [
            'success' => false,
            'error' => ['code' => 401, 'message' => $message],
        ];
        $response->send();
    }

    private function respondTooManyRequests(): void
    {
        $response = Yii::$app->response;
        $response->statusCode = 429;
        $response->data = [
            'success' => false,
            'error' => ['code' => 429, 'message' => 'Too Many Requests'],
        ];
        $response->send();
    }
}
