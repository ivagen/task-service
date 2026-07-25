<?php declare(strict_types=1);

namespace tests;

use app\components\JsonLogTarget;

/**
 * Keeps the real formatting but captures the lines instead of writing them,
 * so tests assert on exactly what would land in the collector.
 */
class CapturingLogTarget extends JsonLogTarget
{
    /** @var list<string> */
    public static array $lines = [];

    public function export(): void
    {
        foreach ($this->messages as $message) {
            self::$lines[] = $this->formatMessage($message);
        }
    }

    public static function reset(): void
    {
        self::$lines = [];
    }

    /** @return list<array<string, mixed>> */
    public static function entries(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $line): mixed => json_decode($line, true),
            self::$lines,
        ), 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    public static function entriesOfCategory(string $category): array
    {
        return array_values(array_filter(
            self::entries(),
            static fn (array $entry): bool => ($entry['category'] ?? null) === $category,
        ));
    }
}
