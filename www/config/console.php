<?php declare(strict_types=1);

use app\components\Env;

$components = [
    'db' => require __DIR__ . '/db.php',
    'cache' => require __DIR__ . '/cache.php',
    'log' => [
        'flushInterval' => 1,
        'targets' => [
            [
                'class' => 'app\components\JsonLogTarget',
                'levels' => ['error', 'warning'],
                'exportInterval' => 1,
            ],
        ],
    ],
];

if (Env::get('CACHE_DRIVER', 'file') === 'redis') {
    $components['redis'] = require __DIR__ . '/redis.php';
}

return [
    'id' => 'task-service-console',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\commands',
    'components' => $components,
    'params' => require __DIR__ . '/params.php',
];
