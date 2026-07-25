<?php declare(strict_types=1);

namespace app\behaviors;

use Yii;
use yii\base\Behavior;
use yii\web\Controller;
use app\components\User;
use yii\base\ActionEvent;
use app\components\RequestId;
use app\components\RateLimitResult;
use app\components\AuthServiceUnavailableException;

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
        $token = $this->extractToken((string)$request->getHeaders()->get('Authorization', ''));

        if ($token === null) {
            $this->respond(401, 'Missing or invalid Authorization header');
            $event->isValid = false;

            return;
        }

        try {
            $userData = Yii::$app->passportAuth->validate($token);
        } catch (AuthServiceUnavailableException) {
            // Already logged with detail by PassportAuth.
            $this->respond(503, 'Auth service is temporarily unavailable', ['Retry-After' => '5']);
            $event->isValid = false;

            return;
        }

        if ($userData === null) {
            $this->respond(401, 'Invalid or expired token');
            $event->isValid = false;

            return;
        }

        $identity = new User($userData);
        Yii::$app->user->setIdentity($identity);

        $rateLimit = Yii::$app->rateLimiter->hit((string)$identity->getId());
        $this->applyRateLimitHeaders($rateLimit);

        if (!$rateLimit->allowed) {
            $this->respond(429, 'Too Many Requests', ['Retry-After' => (string)$rateLimit->retryAfter]);
            $event->isValid = false;
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

    private function applyRateLimitHeaders(RateLimitResult $result): void
    {
        $headers = Yii::$app->response->headers;
        $headers->set('X-RateLimit-Limit', (string)$result->limit);
        $headers->set('X-RateLimit-Remaining', (string)$result->remaining);
    }

    private function respond(int $statusCode, string $message, array $headers = []): void
    {
        $response = Yii::$app->response;
        $response->statusCode = $statusCode;

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        $response->headers->set(RequestId::HEADER, RequestId::get());
        $response->data = [
            'success' => false,
            'error' => [
                'code' => $statusCode,
                'message' => $message,
                'request_id' => RequestId::get(),
            ],
        ];
        $response->send();
    }
}
