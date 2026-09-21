<?php declare(strict_types=1);

namespace tests\Feature;

use Yii;
use tests\TestCase;
use GuzzleHttp\Psr7\Response;
use app\components\PassportAuth;

/**
 * The auth service answers with its resources wrapped in a `data` envelope.
 * Validation used to expect a flat body, so every real token was rejected
 * while the mocked flat payloads kept the suite green.
 */
class PassportPayloadTest extends TestCase
{
    private function configureAuth(array $config = []): void
    {
        Yii::$app->set('passportAuth', ['class' => PassportAuth::class] + $config);
    }

    public function test_enveloped_payload_is_accepted(): void
    {
        $this->configureAuth();
        $this->mockAuthService([new Response(200, [], (string)json_encode([
            'data' => ['id' => 15, 'name' => 'Enveloped', 'email' => 'e@example.com'],
        ]))]);

        $data = Yii::$app->passportAuth->validate('token-enveloped');

        $this->assertIsArray($data);
        $this->assertSame(15, $data['id']);
        $this->assertSame('e@example.com', $data['email']);
    }

    public function test_flat_payload_is_still_accepted(): void
    {
        $this->configureAuth();
        $this->mockAuthService([new Response(200, [], (string)json_encode(
            ['id' => 3, 'name' => 'Flat', 'email' => 'f@example.com'],
        ))]);

        $data = Yii::$app->passportAuth->validate('token-flat');

        $this->assertIsArray($data);
        $this->assertSame(3, $data['id']);
    }

    public function test_payload_without_an_id_is_rejected(): void
    {
        $this->configureAuth();
        $this->mockAuthService([new Response(200, [], (string)json_encode(['data' => ['name' => 'No id']]))]);

        $this->assertNull(Yii::$app->passportAuth->validate('token-idless'));
    }

    public function test_default_user_path_matches_the_versioned_auth_api(): void
    {
        $this->configureAuth();

        $this->assertSame('api/v1/user', Yii::$app->passportAuth->userPath);
    }
}
