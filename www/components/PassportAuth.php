<?php declare(strict_types=1);

namespace app\components;

use Yii;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

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
                'timeout' => 3,
                'connect_timeout' => 2,
            ]);

            $response = $client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
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
