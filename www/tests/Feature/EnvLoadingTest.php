<?php declare(strict_types=1);

namespace tests\Feature;

use Dotenv\Dotenv;
use tests\TestCase;
use app\components\Env;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the P0 contract: real environment variables (how Docker injects
 * configuration) and .env files must both work, with the environment winning.
 */
class EnvLoadingTest extends TestCase
{
    private const KEY = 'TASK_SERVICE_ENV_PROBE';

    protected function tearDown(): void
    {
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        putenv(self::KEY);

        parent::tearDown();
    }

    // =========================================================================
    // Env reader
    // =========================================================================

    public function test_reads_from_env_superglobal(): void
    {
        $_ENV[self::KEY] = 'from-env';

        $this->assertSame('from-env', Env::get(self::KEY));
    }

    public function test_reads_from_server_superglobal(): void
    {
        $_SERVER[self::KEY] = 'from-server';

        $this->assertSame('from-server', Env::get(self::KEY));
    }

    /**
     * php-fpm exposes container variables only through getenv() unless
     * variables_order contains "E" — the exact reason CACHE_DRIVER used to be
     * silently ignored inside Docker.
     */
    public function test_reads_from_getenv_when_superglobals_are_empty(): void
    {
        putenv(self::KEY . '=from-getenv');

        $this->assertSame('from-getenv', Env::get(self::KEY));
    }

    public function test_env_superglobal_wins_over_getenv(): void
    {
        putenv(self::KEY . '=from-getenv');
        $_ENV[self::KEY] = 'from-env';

        $this->assertSame('from-env', Env::get(self::KEY));
    }

    public function test_returns_default_when_absent(): void
    {
        $this->assertSame('fallback', Env::get(self::KEY, 'fallback'));
        $this->assertNull(Env::get(self::KEY));
    }

    public function test_treats_empty_string_as_absent(): void
    {
        $_ENV[self::KEY] = '';

        $this->assertSame('fallback', Env::get(self::KEY, 'fallback'));
    }

    #[DataProvider('booleanValues')]
    public function test_parses_boolean_values(string $raw, bool $expected): void
    {
        $_ENV[self::KEY] = $raw;

        $this->assertSame($expected, Env::getBool(self::KEY));
    }

    public static function booleanValues(): array
    {
        return [
            'true' => ['true', true],
            'True' => ['True', true],
            '1' => ['1', true],
            'yes' => ['yes', true],
            'on' => ['on', true],
            'false' => ['false', false],
            '0' => ['0', false],
            'no' => ['no', false],
            'off' => ['off', false],
        ];
    }

    public function test_boolean_falls_back_on_garbage(): void
    {
        $_ENV[self::KEY] = 'perhaps';

        $this->assertTrue(Env::getBool(self::KEY, true));
        $this->assertFalse(Env::getBool(self::KEY, false));
    }

    public function test_parses_integer_values(): void
    {
        $_ENV[self::KEY] = '6379';
        $this->assertSame(6379, Env::getInt(self::KEY));

        unset($_ENV[self::KEY]);
        $this->assertSame(42, Env::getInt(self::KEY, 42));
    }

    // =========================================================================
    // .env file
    // =========================================================================

    public function test_dotenv_file_is_read_when_the_variable_is_absent(): void
    {
        $dir = sys_get_temp_dir() . '/ts-env-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/.env', self::KEY . "=from-dotenv\n");

        try {
            Dotenv::createImmutable($dir)->safeLoad();

            $this->assertSame('from-dotenv', Env::get(self::KEY));
        } finally {
            unlink($dir . '/.env');
            rmdir($dir);
        }
    }

    public function test_dotenv_does_not_override_the_real_environment(): void
    {
        $_ENV[self::KEY] = 'from-container';

        $dir = sys_get_temp_dir() . '/ts-env-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/.env', self::KEY . "=from-dotenv\n");

        try {
            Dotenv::createImmutable($dir)->safeLoad();

            $this->assertSame(
                'from-container',
                Env::get(self::KEY),
                'a .env file must never override an injected environment variable',
            );
        } finally {
            unlink($dir . '/.env');
            rmdir($dir);
        }
    }

    public function test_missing_dotenv_file_is_not_an_error(): void
    {
        $dir = sys_get_temp_dir() . '/ts-env-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            // safeLoad() must tolerate the file being absent (the Docker case,
            // where configuration arrives purely through the environment).
            Dotenv::createImmutable($dir)->safeLoad();

            $this->assertSame('untouched', Env::get(self::KEY, 'untouched'));
        } finally {
            rmdir($dir);
        }
    }

    // =========================================================================
    // bootstrap.php — constants must be resolved from the environment
    // =========================================================================

    /**
     * Runs in a subprocess because YII_DEBUG/YII_ENV can only be defined once
     * per process. This is the regression guard for the bug where index.php
     * fixed both constants *before* the .env was loaded.
     *
     * @return array{env: string, debug: bool}
     */
    private function bootstrapWith(array $environment): array
    {
        $root = dirname(__DIR__, 2);
        $script = sys_get_temp_dir() . '/ts-bootstrap-' . bin2hex(random_bytes(6)) . '.php';

        file_put_contents($script, sprintf(
            '<?php require %s; require %s; echo json_encode(["env" => YII_ENV, "debug" => YII_DEBUG]);',
            var_export($root . '/vendor/autoload.php', true),
            var_export($root . '/config/bootstrap.php', true),
        ));

        $command = 'env';
        foreach ($environment as $name => $value) {
            $command .= ' ' . escapeshellarg("{$name}={$value}");
        }
        $command .= ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1';

        try {
            $output = shell_exec($command);
        } finally {
            unlink($script);
        }

        $decoded = json_decode((string)$output, true);
        $this->assertIsArray($decoded, "bootstrap subprocess returned: {$output}");

        return $decoded;
    }

    public function test_bootstrap_takes_yii_env_from_the_environment(): void
    {
        $result = $this->bootstrapWith(['YII_ENV' => 'staging', 'YII_DEBUG' => 'false']);

        $this->assertSame('staging', $result['env']);
        $this->assertFalse($result['debug']);
    }

    public function test_bootstrap_takes_yii_debug_from_the_environment(): void
    {
        $result = $this->bootstrapWith(['YII_ENV' => 'dev', 'YII_DEBUG' => 'true']);

        $this->assertSame('dev', $result['env']);
        $this->assertTrue($result['debug']);
    }
}
