<?php declare(strict_types=1);

use app\components\Env;

return [
    'class' => 'yii\db\Connection',
    'dsn' => sprintf(
        'mysql:host=%s;port=%s;dbname=%s',
        Env::get('DB_HOST', '127.0.0.1'),
        Env::get('DB_PORT', '3306'),
        Env::get('DB_NAME', 'task_service'),
    ),
    'username' => Env::get('DB_USER', 'root'),
    'password' => Env::get('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
];
