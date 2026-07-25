<?php declare(strict_types=1);

namespace app\components;

use Yii;
use yii\web\Response;
use yii\web\ErrorHandler;
use yii\web\HttpException;

class JsonErrorHandler extends ErrorHandler
{
    /** Null means "follow YII_DEBUG". Only set explicitly in tests. */
    public ?bool $exposeDetails = null;

    public function renderException($exception): void
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;

        $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;
        $requestId = RequestId::get();

        $exposeDetails = $this->exposeDetails ?? YII_DEBUG;

        $error = [
            'code' => $statusCode,
            'message' => $this->buildMessage($exception, $statusCode),
            'request_id' => $requestId,
        ];

        if ($statusCode >= 500) {
            // The full exception only ever goes to the log, keyed by request_id.
            Yii::error(sprintf(
                "request_id=%s %s: %s in %s:%d\n%s",
                $requestId,
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString(),
            ), 'application');

            if ($exposeDetails) {
                $error['exception'] = get_class($exception);
                $error['detail'] = $exception->getMessage();
                $error['trace'] = explode("\n", $exception->getTraceAsString());
            }
        }

        $response->statusCode = $statusCode;
        $response->headers->set(RequestId::HEADER, $requestId);
        $response->data = [
            'success' => false,
            'error' => $error,
        ];

        $response->send();
    }

    private function buildMessage(\Throwable $exception, int $statusCode): string
    {
        // 4xx messages are written for the client; 5xx messages are internal and
        // may leak SQL, file paths or configuration.
        if ($statusCode >= 500) {
            return $this->getDefaultMessage($statusCode);
        }

        return $exception->getMessage() ?: $this->getDefaultMessage($statusCode);
    }

    private function getDefaultMessage(int $code): string
    {
        return match ($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'An error occurred',
        };
    }
}
