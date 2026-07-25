<?php declare(strict_types=1);

use Dotenv\Dotenv;
use app\components\Env;

// Must run before Yii.php, which otherwise pins these constants to its defaults.
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

defined('YII_DEBUG') or define('YII_DEBUG', Env::getBool('YII_DEBUG', false));
defined('YII_ENV') or define('YII_ENV', Env::get('YII_ENV', 'prod'));
