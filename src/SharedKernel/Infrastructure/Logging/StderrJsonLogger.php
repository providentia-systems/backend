<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Logging;

use Closure;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

final class StderrJsonLogger extends AbstractLogger
{
    private const REDACTED = '[redacted]';

    /** @var Closure(string): void */
    private readonly Closure $writer;

    /** @param null|Closure(string): void $writer */
    public function __construct(?Closure $writer = null)
    {
        $this->writer = $writer ?? static function (string $line): void {
            fwrite(STDERR, $line);
        };
    }

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $record = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $this->safeString((string) $level, 32),
            'message' => $this->safeString((string) $message, 512),
            'context' => $this->sanitizeContext($context),
        ];
        $line = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );

        ($this->writer)($line . PHP_EOL);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $name = $this->safeString((string) $key, 128);
            $safe[$name] = $this->isSensitiveKey($name)
                ? self::REDACTED
                : $this->sanitizeValue($value, 0);
        }

        return $safe;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth >= 3) {
            return '[depth-limited]';
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) || $value instanceof Stringable) {
            return $this->safeString((string) $value, 2048);
        }
        if ($value instanceof Throwable) {
            return ['class' => $value::class];
        }
        if (is_array($value)) {
            $safe = [];
            foreach (array_slice($value, 0, 50, true) as $key => $item) {
                $name = $this->safeString((string) $key, 128);
                $safe[$name] = $this->isSensitiveKey($name)
                    ? self::REDACTED
                    : $this->sanitizeValue($item, $depth + 1);
            }

            return $safe;
        }
        if (is_object($value)) {
            return ['class' => $value::class];
        }
        if (is_resource($value)) {
            return ['resource' => get_resource_type($value)];
        }

        return '[unsupported]';
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/(?:authorization|cookie|credential|dsn|key|pass|payload|secret|token|body|content)/i',
            $key,
        ) === 1;
    }

    private function safeString(string $value, int $maximumLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '?', $value) ?? '';

        return mb_substr($value, 0, $maximumLength);
    }
}
