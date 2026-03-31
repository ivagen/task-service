<?php declare(strict_types=1);

$cacheDriver = $_ENV['CACHE_DRIVER'] ?? 'file';

$cacheComponent = $cacheDriver === 'redis'
    ? [
        'class' => 'yii\redis\Cache',
        'redis' => [
            'hostname' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
        ],
    ]
    : ['class' => 'yii\caching\FileCache'];

return [
    'id' => 'task-service',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\controllers',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'components' => [
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
        'cache' => $cacheComponent,
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
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
                'GET api/v1/tasks' => 'task/index',
                'POST api/v1/tasks' => 'task/create',
                'GET api/v1/tasks/<id:\d+>' => 'task/view',
                'PUT api/v1/tasks/<id:\d+>' => 'task/update',
                'DELETE api/v1/tasks/<id:\d+>' => 'task/delete',
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];
