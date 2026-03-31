# Task Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Побудувати Yii2 REST мікросервіс для управління завданнями з авторизацією через Laravel Passport.

**Architecture:** PassportAuthBehavior перехоплює кожен запит, викликає PassportAuth::validate() який звертається до Laravel Passport `/api/user`, кешує результат у Redis на 60с. TaskController реалізує CRUD з перевіркою ownership через findOwnTask(). Rate limiting вбудовано у behavior.

**Tech Stack:** PHP 8.5+, Yii2 yii2-app-basic, MySQL 8.0, Redis, Guzzle, vlucas/phpdotenv, Docker + Nginx

---

## File Map

| File | Відповідальність |
|------|-----------------|
| `www/composer.json` | Залежності: yii2, guzzle, phpdotenv |
| `www/web/index.php` | Entry point, завантаження .env і bootstrap |
| `www/config/bootstrap.php` | Завантаження .env через phpdotenv |
| `www/config/db.php` | DB конфіг з env змінних |
| `www/config/params.php` | AUTH_SERVICE_URL |
| `www/config/web.php` | App конфіг: urlManager, components, errorHandler |
| `www/components/PassportAuth.php` | Stateless сервіс: Guzzle → Passport, кеш токенів |
| `www/components/User.php` | Yii2 identity, імплементує IdentityInterface |
| `www/behaviors/PassportAuthBehavior.php` | beforeAction: validate token + rate limiting |
| `www/models/Task.php` | ActiveRecord, правила валідації, toArray() |
| `www/models/TaskSearch.php` | Фільтрація та пагінація |
| `www/controllers/TaskController.php` | REST CRUD, findOwnTask(), JSON responses |
| `www/migrations/m260331_000001_create_tasks_table.php` | Міграція таблиці tasks |
| `www/.env.example` | Шаблон env змінних |
| `Dockerfile` | php:8.5-fpm + extensions |
| `docker-compose.yml` | app, nginx, mysql, redis |
| `docker/nginx/default.conf` | Nginx конфіг |
| `README.md` | Setup інструкції + curl приклади |

---

## Task 1: Composer та базова структура Yii2

**Files:**
- Create: `www/composer.json`
- Create: `www/web/index.php`
- Create: `www/config/bootstrap.php`

- [ ] **Step 1: Створи `www/composer.json`**

```json
{
    "name": "task-service/task-service",
    "type": "project",
    "require": {
        "php": ">=8.5",
        "yiisoft/yii2": "~2.0.0",
        "yiisoft/yii2-redis": "~2.0.0",
        "guzzlehttp/guzzle": "^7.0",
        "vlucas/phpdotenv": "^5.0"
    },
    "autoload": {
        "psr-4": {
            "app\\": ""
        }
    },
    "config": {
        "allow-plugins": {
            "yiisoft/yii2-composer": true
        }
    },
    "extra": {
        "asset-installer-paths": {
            "npm-asset-dir": "vendor/npm-asset",
            "bower-asset-dir": "vendor/bower-asset"
        }
    }
}
```

- [ ] **Step 2: Встанови залежності**

```bash
cd www
composer install --no-interaction --prefer-dist
```

Очікуваний результат: папка `www/vendor/` з yii2, guzzle, phpdotenv.

- [ ] **Step 3: Створи `www/config/bootstrap.php`**

```php
<?php
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
```

- [ ] **Step 4: Створи `www/web/index.php`**

```php
<?php
defined('YII_DEBUG') or define('YII_DEBUG', (bool)($_ENV['YII_DEBUG'] ?? false));
defined('YII_ENV') or define('YII_ENV', $_ENV['YII_ENV'] ?? 'prod');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/../config/bootstrap.php';

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
```

- [ ] **Step 5: Створи `www/.env.example`**

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=task_service
DB_USER=root
DB_PASSWORD=
AUTH_SERVICE_URL=http://localhost:8000
CACHE_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
YII_DEBUG=false
YII_ENV=prod
```

- [ ] **Step 6: Скопіюй .env.example в .env для локального запуску**

```bash
cp www/.env.example www/.env
```

- [ ] **Step 7: Commit**

```bash
git add www/composer.json www/composer.lock www/web/index.php www/config/bootstrap.php www/.env.example
git commit -m "feat: scaffold Yii2 project with composer dependencies"
```

---

## Task 2: Конфіги db.php, params.php, web.php

**Files:**
- Create: `www/config/db.php`
- Create: `www/config/params.php`
- Create: `www/config/web.php`

- [ ] **Step 1: Створи `www/config/db.php`**

```php
<?php
return [
    'class' => 'yii\db\Connection',
    'dsn' => sprintf(
        'mysql:host=%s;port=%s;dbname=%s',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_NAME'] ?? 'task_service'
    ),
    'username' => $_ENV['DB_USER'] ?? 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'charset' => 'utf8mb4',
];
```

- [ ] **Step 2: Створи `www/config/params.php`**

```php
<?php
return [
    'authServiceUrl' => rtrim($_ENV['AUTH_SERVICE_URL'] ?? 'http://localhost:8000', '/'),
];
```

- [ ] **Step 3: Створи `www/config/web.php`**

```php
<?php
$cacheDriver = $_ENV['CACHE_DRIVER'] ?? 'file';

$cacheComponent = $cacheDriver === 'redis'
    ? [
        'class' => 'yii\redis\Cache',
        'redis' => [
            'hostname' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port'     => (int)($_ENV['REDIS_PORT'] ?? 6379),
        ],
    ]
    : ['class' => 'yii\caching\FileCache'];

return [
    'id' => 'task-service',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            'enableCookieValidation' => false,
            'enableCsrfValidation'   => false,
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ],
        'response' => [
            'format' => yii\web\Response::FORMAT_JSON,
        ],
        'user' => [
            'class'            => 'yii\web\User',
            'identityClass'    => 'app\components\User',
            'enableSession'    => false,
            'enableAutoLogin'  => false,
        ],
        'db'    => require __DIR__ . '/db.php',
        'cache' => $cacheComponent,
        'log'   => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class'  => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'errorHandler' => [
            'class' => 'yii\web\ErrorHandler',
            'errorAction' => null,
        ],
        'urlManager' => [
            'enablePrettyUrl'     => true,
            'enableStrictParsing' => true,
            'showScriptName'      => false,
            'rules' => [
                ['class' => 'yii\rest\UrlRule', 'controller' => ['api/v1/task']],
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
    'on beforeAction' => function ($event) {
        // JSON error handler for all exceptions
    },
];
```

> **Примітка:** errorHandler перевизначається нижче в Task 8.

- [ ] **Step 4: Перевір що app стартує без помилок**

```bash
cd www && php -r "require 'vendor/autoload.php'; require 'vendor/yiisoft/yii2/Yii.php'; require 'config/bootstrap.php'; \$c = require 'config/web.php'; echo 'OK' . PHP_EOL;"
```

Очікуваний результат: `OK`

- [ ] **Step 5: Commit**

```bash
git add www/config/
git commit -m "feat: add db, params, web config files"
```

---

## Task 3: User identity component

**Files:**
- Create: `www/components/User.php`

- [ ] **Step 1: Створи `www/components/User.php`**

```php
<?php

namespace app\components;

use yii\web\IdentityInterface;

class User implements IdentityInterface
{
    public int $id;
    public string $email;
    public string $name;
    private array $data;

    public function __construct(array $data)
    {
        $this->data  = $data;
        $this->id    = (int)($data['id'] ?? 0);
        $this->email = $data['email'] ?? '';
        $this->name  = $data['name'] ?? '';
    }

    public static function findIdentity($id): ?static
    {
        return null; // not used — session disabled
    }

    public static function findIdentityByAccessToken($token, $type = null): ?static
    {
        return null; // handled by PassportAuthBehavior
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getAuthKey(): ?string
    {
        return null;
    }

    public function validateAuthKey($authKey): bool
    {
        return false;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add www/components/User.php
git commit -m "feat: add User identity component"
```

---

## Task 4: PassportAuth сервіс

**Files:**
- Create: `www/components/PassportAuth.php`

- [ ] **Step 1: Створи `www/components/PassportAuth.php`**

```php
<?php

namespace app\components;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Yii;

class PassportAuth
{
    public static function validate(string $token): array|false
    {
        $cacheKey = 'passport_token_' . md5($token);

        $cached = Yii::$app->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $url = Yii::$app->params['authServiceUrl'] . '/api/user';

        try {
            $client = new Client([
                'timeout'         => 3,
                'connect_timeout' => 2,
            ]);

            $response = $client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $data = json_decode((string)$response->getBody(), true);

            if (empty($data) || !isset($data['id'])) {
                return false;
            }

            Yii::$app->cache->set($cacheKey, $data, 60);

            return $data;
        } catch (GuzzleException $e) {
            Yii::warning('PassportAuth Guzzle error: ' . $e->getMessage(), 'passport');
            return false;
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add www/components/PassportAuth.php
git commit -m "feat: add PassportAuth component with Guzzle and cache"
```

---

## Task 5: PassportAuthBehavior

**Files:**
- Create: `www/behaviors/PassportAuthBehavior.php`

- [ ] **Step 1: Створи `www/behaviors/PassportAuthBehavior.php`**

```php
<?php

namespace app\behaviors;

use app\components\PassportAuth;
use app\components\User;
use Yii;
use yii\base\ActionEvent;
use yii\base\Behavior;
use yii\web\Controller;

class PassportAuthBehavior extends Behavior
{
    public function events(): array
    {
        return [
            Controller::EVENT_BEFORE_ACTION => 'beforeAction',
        ];
    }

    public function beforeAction(ActionEvent $event): void
    {
        $request = Yii::$app->request;
        $token   = $this->extractToken($request->getHeaders()->get('Authorization', ''));

        if ($token === null) {
            $this->respondUnauthorized('Missing or invalid Authorization header');
            $event->isValid = false;
            return;
        }

        $userData = PassportAuth::validate($token);

        if ($userData === false) {
            $this->respondUnauthorized('Invalid or expired token');
            $event->isValid = false;
            return;
        }

        $identity = new User($userData);
        Yii::$app->user->setIdentity($identity);

        if (!$this->checkRateLimit($identity->id)) {
            $this->respondTooManyRequests();
            $event->isValid = false;
            return;
        }
    }

    private function extractToken(string $header): ?string
    {
        if (str_starts_with($header, 'Bearer ')) {
            $token = trim(substr($header, 7));
            return $token !== '' ? $token : null;
        }
        return null;
    }

    private function checkRateLimit(int $userId): bool
    {
        $cacheKey = 'rate_limit_' . $userId;
        $count    = (int)Yii::$app->cache->get($cacheKey);

        if ($count === 0) {
            Yii::$app->cache->set($cacheKey, 1, 60);
            return true;
        }

        if ($count >= 60) {
            return false;
        }

        Yii::$app->cache->set($cacheKey, $count + 1, 60);
        return true;
    }

    private function respondUnauthorized(string $message): void
    {
        $response = Yii::$app->response;
        $response->statusCode = 401;
        $response->data = [
            'success' => false,
            'error'   => ['code' => 401, 'message' => $message],
        ];
        $response->send();
    }

    private function respondTooManyRequests(): void
    {
        $response = Yii::$app->response;
        $response->statusCode = 429;
        $response->data = [
            'success' => false,
            'error'   => ['code' => 429, 'message' => 'Too Many Requests'],
        ];
        $response->send();
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add www/behaviors/PassportAuthBehavior.php
git commit -m "feat: add PassportAuthBehavior with token validation and rate limiting"
```

---

## Task 6: Міграція tasks

**Files:**
- Create: `www/migrations/m260331_000001_create_tasks_table.php`

- [ ] **Step 1: Створи `www/migrations/m260331_000001_create_tasks_table.php`**

```php
<?php

use yii\db\Migration;

class m260331_000001_create_tasks_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('tasks', [
            'id'          => $this->bigPrimaryKey()->unsigned(),
            'user_id'     => $this->bigInteger()->unsigned()->notNull(),
            'title'       => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'status'      => "ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo'",
            'priority'    => $this->tinyInteger()->notNull()->defaultValue(1),
            'due_date'    => $this->date()->null(),
            'created_at'  => $this->integer()->notNull(),
            'updated_at'  => $this->integer()->notNull(),
        ]);

        $this->createIndex('idx_tasks_user_id', 'tasks', 'user_id');
        $this->createIndex('idx_tasks_status', 'tasks', 'status');
        $this->createIndex('idx_tasks_priority', 'tasks', 'priority');
        $this->createIndex('idx_tasks_due_date', 'tasks', 'due_date');
    }

    public function safeDown(): void
    {
        $this->dropTable('tasks');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add www/migrations/
git commit -m "feat: add tasks table migration"
```

---

## Task 7: Task модель та TaskSearch

**Files:**
- Create: `www/models/Task.php`
- Create: `www/models/TaskSearch.php`

- [ ] **Step 1: Створи `www/models/Task.php`**

```php
<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

class Task extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'tasks';
    }

    public function behaviors(): array
    {
        return [
            TimestampBehavior::class,
        ];
    }

    public function rules(): array
    {
        return [
            [['title'], 'required'],
            [['title'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['status'], 'in', 'range' => ['todo', 'in_progress', 'done']],
            [['priority'], 'integer'],
            [['priority'], 'in', 'range' => [1, 2, 3]],
            [['due_date'], 'date', 'format' => 'php:Y-m-d'],
            [['due_date'], 'validateDueDateFuture', 'on' => 'create'],
            [['description', 'status', 'priority', 'due_date'], 'default', 'value' => null],
            [['status'], 'default', 'value' => 'todo'],
            [['priority'], 'default', 'value' => 1],
        ];
    }

    public function validateDueDateFuture(string $attribute): void
    {
        if ($this->$attribute !== null && $this->$attribute < date('Y-m-d')) {
            $this->addError($attribute, 'due_date must be today or in the future.');
        }
    }

    public function scenarios(): array
    {
        return array_merge(parent::scenarios(), [
            'create' => ['title', 'description', 'status', 'priority', 'due_date'],
            'update' => ['title', 'description', 'status', 'priority', 'due_date'],
        ]);
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id'          => (int)$this->id,
            'user_id'     => (int)$this->user_id,
            'title'       => $this->title,
            'description' => $this->description,
            'status'      => $this->status,
            'priority'    => (int)$this->priority,
            'due_date'    => $this->due_date,
            'created_at'  => (int)$this->created_at,
            'updated_at'  => (int)$this->updated_at,
        ];
    }
}
```

- [ ] **Step 2: Створи `www/models/TaskSearch.php`**

```php
<?php

namespace app\models;

use yii\db\ActiveQuery;

class TaskSearch
{
    public const DEFAULT_PAGE     = 1;
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE     = 100;

    public function search(int $userId, array $params): array
    {
        $query = Task::find()->where(['user_id' => $userId]);

        $this->applyFilters($query, $params);

        $total   = (int)$query->count();
        $page    = max(1, (int)($params['page'] ?? self::DEFAULT_PAGE));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int)($params['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $offset  = ($page - 1) * $perPage;

        $tasks = $query->limit($perPage)->offset($offset)->all();

        return [
            'data' => array_map(fn(Task $t) => $t->toArray(), $tasks),
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ];
    }

    private function applyFilters(ActiveQuery $query, array $params): void
    {
        if (!empty($params['status'])) {
            $query->andWhere(['status' => $params['status']]);
        }

        if (!empty($params['priority'])) {
            $query->andWhere(['priority' => (int)$params['priority']]);
        }

        if (!empty($params['due_date_from'])) {
            $query->andWhere(['>=', 'due_date', $params['due_date_from']]);
        }

        if (!empty($params['due_date_to'])) {
            $query->andWhere(['<=', 'due_date', $params['due_date_to']]);
        }

        if (!empty($params['search'])) {
            $like = '%' . addcslashes($params['search'], '%_\\') . '%';
            $query->andWhere(['or',
                ['like', 'title', $like, false],
                ['like', 'description', $like, false],
            ]);
        }
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add www/models/
git commit -m "feat: add Task model and TaskSearch"
```

---

## Task 8: TaskController та error handler

**Files:**
- Create: `www/controllers/TaskController.php`
- Modify: `www/config/web.php` (errorHandler section)

- [ ] **Step 1: Оновлення errorHandler в `www/config/web.php`**

Замінити блок `'errorHandler'` та додати глобальний обробник помилок. Знайди рядок:
```php
        'errorHandler' => [
            'class' => 'yii\web\ErrorHandler',
            'errorAction' => null,
        ],
```
і замінити на:
```php
        'errorHandler' => [
            'class' => 'yii\web\ErrorHandler',
            'errorAction' => 'site/error',
        ],
```

Також після блоку `'components'` додай:
```php
    'controllerNamespace' => 'app\controllers',
```

- [ ] **Step 2: Створи `www/controllers/TaskController.php`**

```php
<?php

namespace app\controllers;

use app\behaviors\PassportAuthBehavior;
use app\models\Task;
use app\models\TaskSearch;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class TaskController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'passportAuth' => PassportAuthBehavior::class,
        ]);
    }

    public function actionIndex(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $userId = Yii::$app->user->identity->getId();
        $result = (new TaskSearch())->search($userId, Yii::$app->request->queryParams);

        return ['success' => true] + $result;
    }

    public function actionCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $userId = Yii::$app->user->identity->getId();
        $body   = Yii::$app->request->getBodyParams();

        $task          = new Task();
        $task->user_id = $userId;
        $task->setScenario('create');
        $task->load($body, '');

        if (!$task->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'error'   => ['code' => 422, 'message' => $task->errors],
            ];
        }

        Yii::$app->response->statusCode = 201;
        return ['success' => true, 'data' => $task->toArray()];
    }

    public function actionView(int $id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $task = $this->findOwnTask($id);

        return ['success' => true, 'data' => $task->toArray()];
    }

    public function actionUpdate(int $id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $task = $this->findOwnTask($id);
        $body = Yii::$app->request->getBodyParams();

        $task->setScenario('update');
        $task->load($body, '');

        if (!$task->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'error'   => ['code' => 422, 'message' => $task->errors],
            ];
        }

        return ['success' => true, 'data' => $task->toArray()];
    }

    public function actionDelete(int $id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $task = $this->findOwnTask($id);
        $task->delete();

        return ['success' => true, 'data' => null];
    }

    private function findOwnTask(int $id): Task
    {
        $task = Task::findOne($id);

        if ($task === null) {
            throw new NotFoundHttpException('Task not found.');
        }

        $userId = Yii::$app->user->identity->getId();
        if ((int)$task->user_id !== $userId) {
            throw new ForbiddenHttpException('Access denied.');
        }

        return $task;
    }
}
```

- [ ] **Step 3: Оновлення urlManager в `www/config/web.php`**

Замінити `'rules'` блок у `urlManager`:
```php
            'rules' => [
                ['class' => 'yii\rest\UrlRule', 'controller' => ['api/v1/task']],
            ],
```
на:
```php
            'rules' => [
                'GET api/v1/tasks'        => 'task/index',
                'POST api/v1/tasks'       => 'task/create',
                'GET api/v1/tasks/<id:\d+>'    => 'task/view',
                'PUT api/v1/tasks/<id:\d+>'    => 'task/update',
                'DELETE api/v1/tasks/<id:\d+>' => 'task/delete',
            ],
```

- [ ] **Step 4: Commit**

```bash
git add www/controllers/ www/config/web.php
git commit -m "feat: add TaskController with CRUD, ownership check, error responses"
```

---

## Task 9: Docker інфраструктура

**Files:**
- Create: `Dockerfile`
- Create: `docker-compose.yml`
- Create: `docker/nginx/default.conf`

- [ ] **Step 1: Створи `Dockerfile`**

```dockerfile
FROM php:8.5-fpm

RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libpng-dev libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY www/ .

RUN composer install --no-interaction --no-scripts --prefer-dist

RUN mkdir -p /var/www/runtime /var/www/web/assets \
    && chown -R www-data:www-data /var/www/runtime /var/www/web/assets

EXPOSE 9000
CMD ["php-fpm"]
```

- [ ] **Step 2: Створи `docker-compose.yml`**

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    volumes:
      - ./www:/var/www
    depends_on:
      - mysql
      - redis
    networks:
      - task-service

  nginx:
    image: nginx:alpine
    ports:
      - "8002:80"
    volumes:
      - ./www:/var/www
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app
    networks:
      - task-service

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: ${DB_NAME:-task_service}
      MYSQL_USER: ${DB_USER:-task_service}
      MYSQL_PASSWORD: ${DB_PASSWORD:-secret}
      MYSQL_ROOT_PASSWORD: root
    ports:
      - "3307:3306"
    volumes:
      - mysql_data:/var/lib/mysql
    networks:
      - task-service

  redis:
    image: redis:alpine
    ports:
      - "6380:6379"
    networks:
      - task-service

volumes:
  mysql_data:

networks:
  task-service:
    driver: bridge
```

> MySQL на порту 3307 і Redis на 6380 щоб не конфліктувати з auth_service.

- [ ] **Step 3: Створи `docker/nginx/default.conf`**

```nginx
server {
    listen 80;
    server_name localhost;
    root /var/www/web;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add Dockerfile docker-compose.yml docker/
git commit -m "feat: add Docker, docker-compose, Nginx config"
```

---

## Task 10: README.md з curl прикладами

**Files:**
- Create: `README.md`

- [ ] **Step 1: Створи `README.md`**

```markdown
# Task Service

Yii2 REST мікросервіс для управління завданнями. Авторизація через Laravel Passport.

## Вимоги

- Docker та Docker Compose
- Запущений auth_service (Laravel Passport) на порту 8000

## Налаштування

### 1. Клонуй репозиторій та перейди в директорію

```bash
git clone <repo-url> task-service
cd task-service
```

### 2. Створи .env файл

```bash
cp www/.env.example www/.env
```

Відредагуй `www/.env`:

```
DB_HOST=mysql
DB_PORT=3306
DB_NAME=task_service
DB_USER=task_service
DB_PASSWORD=secret
AUTH_SERVICE_URL=http://host.docker.internal:8000
CACHE_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
```

### 3. Запусти контейнери

```bash
docker compose up -d --build
```

### 4. Виконай міграції

```bash
docker compose exec app php yii migrate --interactive=0
```

### 5. Сервіс доступний на http://localhost:8002

---

## API Endpoints

Всі запити вимагають заголовок `Authorization: Bearer <token>`.

### Отримати список завдань

```bash
curl -X GET "http://localhost:8002/api/v1/tasks" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

З фільтрами:

```bash
curl -X GET "http://localhost:8002/api/v1/tasks?status=todo&priority=2&page=1&per_page=10" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Пошук:

```bash
curl -X GET "http://localhost:8002/api/v1/tasks?search=купити" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Створити завдання

```bash
curl -X POST "http://localhost:8002/api/v1/tasks" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Купити молоко",
    "description": "2 літри",
    "status": "todo",
    "priority": 1,
    "due_date": "2026-04-15"
  }'
```

### Отримати завдання за ID

```bash
curl -X GET "http://localhost:8002/api/v1/tasks/1" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Оновити завдання

```bash
curl -X PUT "http://localhost:8002/api/v1/tasks/1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "in_progress",
    "priority": 2
  }'
```

### Видалити завдання

```bash
curl -X DELETE "http://localhost:8002/api/v1/tasks/1" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## Формат відповідей

**Успішна відповідь (одне завдання):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "user_id": 5,
    "title": "Купити молоко",
    "description": "2 літри",
    "status": "todo",
    "priority": 1,
    "due_date": "2026-04-15",
    "created_at": 1743379200,
    "updated_at": 1743379200
  }
}
```

**Список:**
```json
{
  "success": true,
  "data": [...],
  "meta": {
    "total": 50,
    "page": 1,
    "per_page": 20,
    "total_pages": 3
  }
}
```

**Помилка:**
```json
{
  "success": false,
  "error": {
    "code": 401,
    "message": "Unauthorized"
  }
}
```

---

## Зупинити сервіс

```bash
docker compose down
```
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: add README with setup instructions and curl examples"
```

---

## Task 11: Фінальна перевірка

- [ ] **Step 1: Збери Docker образ**

```bash
docker compose build --no-cache
```

Очікуваний результат: успішний build без помилок.

- [ ] **Step 2: Запусти контейнери**

```bash
docker compose up -d
```

- [ ] **Step 3: Виконай міграції**

```bash
docker compose exec app php yii migrate --interactive=0
```

Очікуваний результат:
```
Applied 1 migration.
```

- [ ] **Step 4: Перевір healthcheck (без токена — має повернути 401)**

```bash
curl -s http://localhost:8002/api/v1/tasks | python3 -m json.tool
```

Очікуваний результат:
```json
{
  "success": false,
  "error": {
    "code": 401,
    "message": "Missing or invalid Authorization header"
  }
}
```

- [ ] **Step 5: Commit фінального стану**

```bash
git add -A
git commit -m "chore: finalize task-service implementation"
```