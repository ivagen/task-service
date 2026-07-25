<?php declare(strict_types=1);

use app\components\Env;
use app\components\RequestId;

$components = [
    'request' => [
        'enableCookieValidation' => false,
        'enableCsrfValidation' => false,
        'parsers' => [
            'application/json' => 'yii\web\JsonParser',
        ],
    ],
    'response' => [
        'format' => yii\web\Response::FORMAT_JSON,
    ],
    'user' => [
        'class' => 'yii\web\User',
        'identityClass' => 'app\components\User',
        'enableSession' => false,
        'enableAutoLogin' => false,
    ],
    'db' => require __DIR__ . '/db.php',
    'cache' => require __DIR__ . '/cache.php',
    'passportAuth' => [
        'class' => 'app\components\PassportAuth',
        'timeout' => (float)Env::getInt('AUTH_TIMEOUT', 3),
        'connectTimeout' => (float)Env::getInt('AUTH_CONNECT_TIMEOUT', 2),
        'cacheDuration' => Env::getInt('AUTH_CACHE_TTL', 60),
        'retries' => Env::getInt('AUTH_RETRIES', 1),
        'retryDelayMs' => Env::getInt('AUTH_RETRY_DELAY_MS', 100),
        'totalTimeout' => (float)Env::getInt('AUTH_TOTAL_TIMEOUT', 5),
    ],
    'accessLogger' => [
        'class' => 'app\components\AccessLogger',
    ],
    'rateLimiter' => [
        'class' => 'app\components\RateLimiter',
        'limit' => Env::getInt('RATE_LIMIT', 60),
        'window' => Env::getInt('RATE_LIMIT_WINDOW', 60),
    ],
    'log' => [
        'traceLevel' => YII_DEBUG ? 3 : 0,
        // Flush on every message: a fatal error must not take the buffered
        // access log down with it.
        'flushInterval' => 1,
        'targets' => [
            [
                'class' => 'app\components\JsonLogTarget',
                'levels' => ['error', 'warning', 'info'],
                'categories' => ['application', 'passport', 'access', 'rateLimiter', 'health'],
                'exportInterval' => 1,
            ],
        ],
    ],
    'errorHandler' => [
        'class' => 'app\components\JsonErrorHandler',
    ],
    'urlManager' => [
        'enablePrettyUrl' => true,
        'enableStrictParsing' => true,
        'showScriptName' => false,
        'rules' => [
            'GET api/v1/health' => 'health/live',
            'GET api/v1/ready' => 'health/ready',
            'GET api/v1/tasks' => 'task/index',
            'POST api/v1/tasks' => 'task/create',
            'GET api/v1/tasks/<id:\d+>' => 'task/view',
            'PUT api/v1/tasks/<id:\d+>' => 'task/update',
            'DELETE api/v1/tasks/<id:\d+>' => 'task/delete',
        ],
    ],
];

// Only define the Redis connection when it is actually the configured backend,
// so a file-cache setup never tries to reach a Redis host.
if (Env::get('CACHE_DRIVER', 'file') === 'redis') {
    $components['redis'] = require __DIR__ . '/redis.php';
}

return [
    'id' => 'task-service',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\controllers',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'components' => $components,
    'on ' . yii\base\Application::EVENT_BEFORE_REQUEST => static function (): void {
        \Yii::$app->response->headers->set(RequestId::HEADER, RequestId::get());
        \Yii::$app->accessLogger->attach(\Yii::$app);
    },
    'params' => require __DIR__ . '/params.php',
];
