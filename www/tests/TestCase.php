<?php declare(strict_types=1);

namespace tests;

use Yii;
use GuzzleHttp\Client;
use yii\web\Application;
use GuzzleHttp\HandlerStack;
use app\components\RequestId;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use app\components\JsonErrorHandler;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const TOKEN = 'test-token-abc123';
    protected const USER = ['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com'];

    protected function setUp(): void
    {
        parent::setUp();
        RequestId::reset();
        CapturingLogTarget::reset();
        $this->bootApp();
        $this->createSchema();
        $this->flushRedis();
        $this->seedFakeToken();
        // No test may reach a real auth service; individual tests opt into a
        // scripted response with mockAuthService().
        $this->mockAuthService([new Response(401, [], '{"message":"Unauthenticated."}')]);
    }

    protected function tearDown(): void
    {
        if (Yii::$app !== null) {
            Yii::getLogger()->flush(true);
            Yii::$app->errorHandler->unregister();
            Yii::$app->db->close();
            Yii::$app = null;
        }
        RequestId::reset();
        parent::tearDown();
    }

    /**
     * Swaps the error handler while keeping PHP's handler stack balanced.
     */
    protected function useErrorHandler(bool $exposeDetails): void
    {
        Yii::$app->errorHandler->unregister();
        Yii::$app->set('errorHandler', [
            'class' => JsonErrorHandler::class,
            'discardExistingOutput' => false,
            'exposeDetails' => $exposeDetails,
        ]);
        Yii::$app->errorHandler->register();
    }

    /**
     * @param array<\Throwable|Response> $responses queued auth-service replies
     */
    protected function mockAuthService(array $responses): void
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        Yii::$app->passportAuth->setClient(new Client([
            'handler' => $handler,
            'http_errors' => false,
        ]));
    }

    private function bootApp(): void
    {
        $config = require __DIR__ . '/../config/test.php';
        new Application($config);
    }

    /** Migrations are applied once per process; rows are cleared per test. */
    private static bool $migrated = false;

    protected function isSqlite(): bool
    {
        return Yii::$app->db->driverName === 'sqlite';
    }

    private function createSchema(): void
    {
        // Each SQLite test gets a brand-new in-memory database, and the real
        // migration uses MySQL-only syntax (ENUM), so keep an equivalent DDL.
        if ($this->isSqlite()) {
            Yii::$app->db->createCommand("
                CREATE TABLE IF NOT EXISTS tasks (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id    INTEGER NOT NULL,
                    title      VARCHAR(255) NOT NULL,
                    description TEXT,
                    status     VARCHAR(20) NOT NULL DEFAULT 'todo',
                    priority   TINYINT NOT NULL DEFAULT 1,
                    due_date   DATE,
                    created_at INTEGER NOT NULL DEFAULT 0,
                    updated_at INTEGER NOT NULL DEFAULT 0
                )
            ")->execute();

            return;
        }

        // A persistent database survives across tests, so re-migrate whenever a
        // test has dropped the schema (several do so deliberately).
        if (!self::$migrated || Yii::$app->db->schema->getTableSchema('tasks', true) === null) {
            $this->applyMigrations();
            self::$migrated = true;
        }

        Yii::$app->db->createCommand()->delete('tasks')->execute();
    }

    /**
     * Runs the project's real migrations, so the integration run exercises the
     * same DDL that production gets.
     */
    private function applyMigrations(): void
    {
        $db = Yii::$app->db;

        // Start from an empty schema so a previous run cannot mask a broken
        // migration.
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        foreach ($db->schema->getTableNames('', true) as $table) {
            $db->createCommand()->dropTable($table)->execute();
        }
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        $db->schema->refresh();

        ob_start();
        try {
            foreach (glob(dirname(__DIR__) . '/migrations/m*.php') ?: [] as $file) {
                require_once $file;

                /** @var class-string<\yii\db\Migration> $class */
                $class = basename($file, '.php');
                $migration = new $class(['db' => $db, 'compact' => true]);

                if ($migration->up() === false) {
                    self::fail("Migration {$class} failed.");
                }
            }
        } finally {
            ob_end_clean();
        }

        $db->schema->refresh();
    }

    protected function hasRedis(): bool
    {
        return Yii::$app->has('redis');
    }

    /** Rate-limit counters outlive a test in real Redis; start each test clean. */
    private function flushRedis(): void
    {
        if ($this->hasRedis()) {
            Yii::$app->redis->executeCommand('FLUSHDB');
        }
    }

    private function seedFakeToken(): void
    {
        $key = 'passport_token_' . md5(self::TOKEN);
        Yii::$app->cache->set($key, self::USER);
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * @param bool|string $auth true = seeded valid token, false = no header,
     *                          string = send that exact bearer token
     */
    protected function get(string $url, bool|string $auth = true): array
    {
        return $this->doRequest('GET', $url, [], $auth);
    }

    protected function post(string $url, array $body = [], bool|string $auth = true): array
    {
        return $this->doRequest('POST', $url, $body, $auth);
    }

    protected function put(string $url, array $body = [], bool|string $auth = true): array
    {
        return $this->doRequest('PUT', $url, $body, $auth);
    }

    protected function delete(string $url, bool|string $auth = true): array
    {
        return $this->doRequest('DELETE', $url, [], $auth);
    }

    /**
     * Sends an unparsed JSON string so yii\web\JsonParser handles it for real.
     */
    protected function postRaw(string $url, string $rawBody, bool|string $auth = true): array
    {
        return $this->doRequest('POST', $url, [], $auth, $rawBody);
    }

    private function doRequest(
        string $method,
        string $url,
        array $body,
        bool|string $auth,
        ?string $rawBody = null,
    ): array {
        // Parse URL so query string goes into $_GET and path into REQUEST_URI
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = strtoupper($method);
        $_SERVER['REQUEST_URI'] = $path . $query;
        $_SERVER['QUERY_STRING'] = $parts['query'] ?? '';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = '/var/www/web/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_AUTHORIZATION'] = match (true) {
            is_string($auth) => 'Bearer ' . $auth,
            $auth => 'Bearer ' . self::TOKEN,
            default => '',
        };

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $_GET);
        }

        // Re-register request so it picks up fresh $_SERVER
        Yii::$app->set('request', [
            'class' => MockRequest::class,
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ]);
        /** @var MockRequest $request */
        $request = Yii::$app->request;

        if ($rawBody !== null) {
            $request->useRawBody($rawBody);
        } else {
            $request->setBodyParams($body);
        }

        // Re-register response to get a clean instance
        Yii::$app->set('response', [
            'class' => 'yii\web\Response',
            'format' => \yii\web\Response::FORMAT_JSON,
        ]);

        // The access log hangs off the response, which is rebuilt per request.
        Yii::$app->accessLogger->attach(Yii::$app);

        // Reset user identity between requests
        Yii::$app->user->setIdentity(null);

        // Re-seed fake token
        $this->seedFakeToken();

        ob_start();
        try {
            $response = Yii::$app->handleRequest($request);
            // Application::run() sends the response; without this the harness
            // would skip Response::EVENT_AFTER_SEND and everything on it.
            $response->send();
        } catch (\Throwable $e) {
            // Route through the real error handler so tests cover its output.
            $response = Yii::$app->response;
            $response->clear();

            $errorHandler = Yii::$app->errorHandler;
            if (!$errorHandler instanceof JsonErrorHandler) {
                throw $e;
            }
            $errorHandler->renderException($e);
        } finally {
            ob_end_clean();
        }

        return [
            'status' => $response->statusCode,
            'body' => $response->data ?? [],
            'headers' => $this->collectHeaders($response),
            'content' => $response->content,
        ];
    }

    private function collectHeaders(\yii\web\Response $response): array
    {
        $headers = [];

        foreach ($response->headers as $name => $values) {
            $headers[strtolower($name)] = end($values);
        }

        return $headers;
    }

    // -------------------------------------------------------------------------
    // DB helpers
    // -------------------------------------------------------------------------

    protected function createTask(array $attrs = []): array
    {
        $defaults = [
            'user_id' => self::USER['id'],
            'title' => 'Test task',
            'description' => null,
            'status' => 'todo',
            'priority' => 1,
            'due_date' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $data = array_merge($defaults, $attrs);

        Yii::$app->db->createCommand()->insert('tasks', $data)->execute();

        return array_merge($data, ['id' => (int)Yii::$app->db->getLastInsertID()]);
    }
}
