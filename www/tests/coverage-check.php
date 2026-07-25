#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Fails the build when line coverage drops below a threshold.
 *
 * PHPUnit 11 has no built-in minimum-coverage gate, so the Clover report is
 * inspected here instead.
 *
 * Usage: php tests/coverage-check.php <clover.xml> <minimum-percent>
 */

$report = $argv[1] ?? null;
$minimum = (float)($argv[2] ?? 85);

if ($report === null || !is_file($report)) {
    fwrite(STDERR, "Coverage report not found: " . var_export($report, true) . PHP_EOL);
    exit(2);
}

$xml = @simplexml_load_file($report);

if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(STDERR, "Not a readable Clover report: {$report}" . PHP_EOL);
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int)$metrics['statements'];
$covered = (int)$metrics['coveredstatements'];

if ($statements === 0) {
    fwrite(STDERR, 'Clover report contains no statements — coverage driver missing?' . PHP_EOL);
    exit(2);
}

$coverage = $covered / $statements * 100;

printf(
    "Line coverage: %.2f%% (%d/%d statements), minimum %.2f%%%s",
    $coverage,
    $covered,
    $statements,
    $minimum,
    PHP_EOL,
);

// Guard against a float comparison rejecting an exactly-on-target run.
if ($coverage + 0.0001 < $minimum) {
    fwrite(STDERR, sprintf('FAIL: coverage is %.2f%% below the minimum.%s', $minimum - $coverage, PHP_EOL));
    exit(1);
}

echo 'OK' . PHP_EOL;
exit(0);
