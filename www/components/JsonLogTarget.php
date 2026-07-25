<?php declare(strict_types=1);

namespace app\components;

use Yii;
use yii\log\Logger;
use yii\log\Target;
use yii\helpers\VarDumper;

/**
 * Emits one JSON object per line to a stream, so a collector can parse logs
 * without regexes.
 *
 * Writes to stderr by default: php-fpm sends worker stdout to /dev/null, while
 * stderr is wired to error_log and therefore reaches `docker logs`.
 */
class JsonLogTarget extends Target
{
    public string $stream = 'php://stderr';

    /**
     * Never dump $GLOBALS. The default Target behaviour includes $_SERVER,
     * which in a container holds DB_PASSWORD and every other secret.
     */
    public $logVars = [];

    /** Static field added to every line, useful when several services share a collector. */
    public string $service = 'task-service';

    private mixed $handle = null;

    public function export(): void
    {
        $handle = $this->handle();

        foreach ($this->messages as $message) {
            fwrite($handle, $this->formatMessage($message) . "\n");
        }
    }

    /**
     * @param array $message [text, level, category, timestamp, traces, memory]
     */
    public function formatMessage($message): string
    {
        [$text, $level, $category, $timestamp] = $message;

        $line = [
            'timestamp' => $this->formatTimestamp((float)$timestamp),
            'level' => Logger::getLevelName($level),
            'service' => $this->service,
            'category' => $category,
        ];

        $line += $this->describe($text);
        $line['request_id'] = RequestId::get();

        if (isset($message[4]) && is_array($message[4]) && $message[4] !== []) {
            $line['trace'] = array_map(
                static fn (array $frame): string => ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?'),
                $message[4],
            );
        }

        return (string)json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Turns whatever was passed to Yii::info()/error() into JSON fields.
     *
     * An array becomes structured fields directly — that is how the access log
     * records method, status and duration without string parsing.
     */
    private function describe(mixed $text): array
    {
        if (is_string($text)) {
            return ['message' => $text];
        }

        if ($text instanceof \Throwable) {
            return [
                'message' => $text->getMessage(),
                'exception' => $text::class,
                'file' => $text->getFile() . ':' . $text->getLine(),
                'trace' => explode("\n", $text->getTraceAsString()),
            ];
        }

        if (is_array($text)) {
            // Reserved keys stay owned by the envelope.
            unset($text['timestamp'], $text['level'], $text['category'], $text['request_id'], $text['service']);

            return $text;
        }

        return ['message' => VarDumper::export($text)];
    }

    private function formatTimestamp(float $timestamp): string
    {
        return sprintf(
            '%s.%03dZ',
            gmdate('Y-m-d\TH:i:s', (int)$timestamp),
            (int)round(($timestamp - floor($timestamp)) * 1000),
        );
    }

    private function handle(): mixed
    {
        if ($this->handle === null) {
            $handle = @fopen($this->stream, 'a');

            if ($handle === false) {
                throw new \RuntimeException("Cannot open log stream: {$this->stream}");
            }

            $this->handle = $handle;
        }

        return $this->handle;
    }
}
