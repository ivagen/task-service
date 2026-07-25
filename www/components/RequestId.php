<?php declare(strict_types=1);

namespace app\components;

use Yii;
use yii\web\Request;

/**
 * Correlation ID for a single request, reused by logs and error responses.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    private static ?string $id = null;

    public static function get(): string
    {
        if (self::$id === null) {
            self::$id = self::fromHeader() ?? bin2hex(random_bytes(16));
        }

        return self::$id;
    }

    public static function reset(): void
    {
        self::$id = null;
    }

    private static function fromHeader(): ?string
    {
        if (Yii::$app === null || !Yii::$app->has('request')) {
            return null;
        }

        $request = Yii::$app->get('request');
        if (!$request instanceof Request) {
            return null;
        }

        $value = $request->getHeaders()->get(self::HEADER);

        // Only accept opaque tokens — the value ends up in log lines.
        return is_string($value) && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $value) === 1
            ? $value
            : null;
    }
}
