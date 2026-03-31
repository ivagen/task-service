<?php

namespace tests;

use Yii;
use yii\web\Application;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const TOKEN = 'test-token-abc123';
    protected const USER  = ['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
        $this->createSchema();
        $this->seedFakeToken();
    }

    protected function tearDown(): void
    {
        if (Yii::$app !== null) {
            Yii::$app->db->close();
            Yii::$app = null;
        }
        parent::tearDown();
    }

    private function bootApp(): void
    {
        $config = require __DIR__ . '/../config/test.php';
        new Application($config);
    }

    private function createSchema(): void
    {
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
    }

    private function seedFakeToken(): void
    {
        $key = 'passport_token_' . md5(self::TOKEN);
        Yii::$app->cache->set($key, self::USER);
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    protected function get(string $url, bool $auth = true): array
    {
        return $this->doRequest('GET', $url, [], $auth);
    }

    protected function post(string $url, array $body = [], bool $auth = true): array
    {
        return $this->doRequest('POST', $url, $body, $auth);
    }

    protected function put(string $url, array $body = [], bool $auth = true): array
    {
        return $this->doRequest('PUT', $url, $body, $auth);
    }

    protected function delete(string $url, bool $auth = true): array
    {
        return $this->doRequest('DELETE', $url, [], $auth);
    }

    private function doRequest(string $method, string $url, array $body, bool $auth): array
    {
        $_SERVER['REQUEST_METHOD'] = strtoupper($method);
        $_SERVER['REQUEST_URI']    = $url;
        $_SERVER['HTTP_AUTHORIZATION'] = $auth ? 'Bearer ' . self::TOKEN : '';

        // Re-register request so it picks up new $_SERVER state
        Yii::$app->set('request', [
            'class'                  => MockRequest::class,
            'enableCookieValidation' => false,
            'enableCsrfValidation'   => false,
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ]);
        /** @var MockRequest $request */
        $request = Yii::$app->request;
        $request->setBodyParams($body);

        // Re-register response to get a clean instance
        Yii::$app->set('response', [
            'class'  => 'yii\web\Response',
            'format' => \yii\web\Response::FORMAT_JSON,
        ]);

        // Reset user identity between requests
        Yii::$app->user->setIdentity(null);

        // Re-seed fake token (ArrayCache is shared, so this is cheap)
        $this->seedFakeToken();

        ob_start();
        $response = Yii::$app->handleRequest($request);
        ob_end_clean();

        return [
            'status' => $response->statusCode,
            'body'   => $response->data ?? [],
        ];
    }

    // -------------------------------------------------------------------------
    // DB helpers
    // -------------------------------------------------------------------------

    protected function createTask(array $attrs = []): array
    {
        $defaults = [
            'user_id'     => self::USER['id'],
            'title'       => 'Test task',
            'description' => null,
            'status'      => 'todo',
            'priority'    => 1,
            'due_date'    => null,
            'created_at'  => time(),
            'updated_at'  => time(),
        ];

        $data = array_merge($defaults, $attrs);

        Yii::$app->db->createCommand()->insert('tasks', $data)->execute();

        return array_merge($data, ['id' => (int)Yii::$app->db->getLastInsertID()]);
    }
}