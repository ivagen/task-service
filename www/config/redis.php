<?php declare(strict_types=1);

use app\components\Env;

return [
    'class' => 'yii\redis\Connection',
    'hostname' => Env::get('REDIS_HOST', '127.0.0.1'),
    'port' => Env::getInt('REDIS_PORT', 6379),
    'database' => Env::getInt('REDIS_DB', 0),
    'password' => Env::get('REDIS_PASSWORD'),
];
