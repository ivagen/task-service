<?php declare(strict_types=1);

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

// Default is in-memory SQLite (fast, no services). CI additionally runs the
// same suite against MySQL by exporting TEST_DB_DSN/TEST_DB_USER/TEST_DB_PASSWORD.
$db = ['class' => 'yii\db\Connection', 'dsn' => 'sqlite::memory:'];

if (($dsn = getenv('TEST_DB_DSN')) !== false && $dsn !== '') {
    $db = [
        'class' => 'yii\db\Connection',
        'dsn' => $dsn,
        'username' => (string)(getenv('TEST_DB_USER') ?: ''),
        'password' => (string)(getenv('TEST_DB_PASSWORD') ?: ''),
        'charset' => 'utf8mb4',
    ];
}

// When TEST_REDIS_HOST is set, the rate limiter exercises the real atomic
// Redis path instead of the cache fallback. A dedicated database is used and
// flushed per test.
$redis = null;

if (($redisHost = getenv('TEST_REDIS_HOST')) !== false && $redisHost !== '') {
    $redis = [
        'class' => 'yii\redis\Connection',
        'hostname' => $redisHost,
        'port' => (int)(getenv('TEST_REDIS_PORT') ?: 6379),
        'database' => (int)(getenv('TEST_REDIS_DB') ?: 15),
    ];
}

$config = [
    'id' => 'task-service-test',
    'basePath' => dirname(__DIR__),
    // Without this the log Dispatcher is never created and nothing reaches the
    // targets — the same wiring web.php uses.
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\controllers',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            'class' => 'tests\MockRequest',
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ],
        'response' => [
            'format' => \yii\web\Response::FORMAT_JSON,
        ],
        'user' => [
            'class' => 'yii\web\User',
            'identityClass' => 'app\components\User',
            'enableSession' => false,
            'enableAutoLogin' => false,
        ],
        'db' => $db,
        'cache' => [
            'class' => 'yii\caching\ArrayCache',
        ],
        'passportAuth' => [
            'class' => 'app\components\PassportAuth',
        ],
        'rateLimiter' => [
            'class' => 'app\components\RateLimiter',
        ],
        'accessLogger' => [
            'class' => 'app\components\AccessLogger',
        ],
        'log' => [
            'flushInterval' => 1,
            'targets' => [
                [
                    'class' => 'tests\CapturingLogTarget',
                    'levels' => ['error', 'warning', 'info'],
                    'categories' => ['application', 'passport', 'access', 'rateLimiter', 'health'],
                    'exportInterval' => 1,
                ],
            ],
        ],
        'errorHandler' => [
            'class' => 'app\components\JsonErrorHandler',
            'discardExistingOutput' => false,
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
    ],
    'params' => [
        'authServiceUrl' => 'http://localhost:8000',
    ],
];

if ($redis !== null) {
    $config['components']['redis'] = $redis;
}

return $config;
