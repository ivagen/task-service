<?php declare(strict_types=1);

namespace app\components;

/**
 * Reads environment variables from every source PHP may expose them in.
 *
 * Dotenv populates $_ENV/$_SERVER, while variables injected by Docker are only
 * visible through getenv() unless php.ini `variables_order` contains "E".
 * Reading all three keeps `.env` files and container `environment:` equivalent.
 */
final class Env
{
    public static function get(string $key, ?string $default = null): ?string
    {
        // ?? already skips nulls; getenv() reports "absent" as false.
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return (string)$value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null ? $default : (int)$value;
    }
}
