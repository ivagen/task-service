<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

return [
    'id'                 => 'task-service-test',
    'basePath'           => dirname(__DIR__),
    'controllerNamespace' => 'app\controllers',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            'class'                  => 'tests\MockRequest',
            'enableCookieValidation' => false,
            'enableCsrfValidation'   => false,
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ],
        'response' => [
            'format' => \yii\web\Response::FORMAT_JSON,
        ],
        'user' => [
            'class'           => 'yii\web\User',
            'identityClass'   => 'app\components\User',
            'enableSession'   => false,
            'enableAutoLogin' => false,
        ],
        'db' => [
            'class' => 'yii\db\Connection',
            'dsn'   => 'sqlite::memory:',
        ],
        'cache' => [
            'class' => 'yii\caching\ArrayCache',
        ],
        'log' => [
            'targets' => [],
        ],
        'errorHandler' => [
            'class'                 => 'app\components\JsonErrorHandler',
            'discardExistingOutput' => false,
        ],
        'urlManager' => [
            'enablePrettyUrl'     => true,
            'enableStrictParsing' => true,
            'showScriptName'      => false,
            'rules' => [
                'GET api/v1/tasks'             => 'task/index',
                'POST api/v1/tasks'            => 'task/create',
                'GET api/v1/tasks/<id:\d+>'    => 'task/view',
                'PUT api/v1/tasks/<id:\d+>'    => 'task/update',
                'DELETE api/v1/tasks/<id:\d+>' => 'task/delete',
            ],
        ],
    ],
    'params' => [
        'authServiceUrl' => 'http://localhost:8000',
    ],
];
