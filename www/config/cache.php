<?php declare(strict_types=1);

use app\components\Env;

if (Env::get('CACHE_DRIVER', 'file') !== 'redis') {
    return ['class' => 'yii\caching\FileCache'];
}

return [
    'class' => 'yii\redis\Cache',
    'redis' => 'redis',
];
