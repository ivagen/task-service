<?php

namespace app\components;

use Yii;
use yii\web\ErrorHandler;
use yii\web\HttpException;

class JsonErrorHandler extends ErrorHandler
{
    public function renderException($exception): void
    {
        $response = Yii::$app->response;
        $response->format = \yii\web\Response::FORMAT_JSON;

        if ($exception instanceof HttpException) {
            $statusCode = $exception->statusCode;
        } else {
            $statusCode = 500;
        }

        $response->statusCode = $statusCode;
        $response->data = [
            'success' => false,
            'error'   => [
                'code'    => $statusCode,
                'message' => $exception->getMessage() ?: $this->getDefaultMessage($statusCode),
            ],
        ];

        $response->send();
    }

    private function getDefaultMessage(int $code): string
    {
        return match($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            default => 'An error occurred',
        };
    }
}