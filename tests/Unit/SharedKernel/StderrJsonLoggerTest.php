<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Infrastructure\Logging\StderrJsonLogger;
use Providentia\SharedKernel\Infrastructure\Logging\StderrLineWriter;
use RuntimeException;

final class StderrJsonLoggerTest extends TestCase
{
    public function testLogRecordIsJsonAndSensitiveContextIsRedacted(): void
    {
        $output = '';
        $logger = new StderrJsonLogger(static function (string $line) use (&$output): void {
            $output .= $line;
        });

        $logger->error('HTTP request failed.', [
            'request_id' => 'request-123',
            'status' => 500,
            'authorization' => 'Bearer secret',
            'nested' => ['refreshToken' => 'secret', 'safe' => 'value'],
            'exception' => new RuntimeException('must not be logged'),
        ]);

        /** @var array<string, mixed> $record */
        $record = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $context */
        $context = $record['context'];
        /** @var array<string, mixed> $nested */
        $nested = $context['nested'];
        /** @var array<string, mixed> $exception */
        $exception = $context['exception'];

        self::assertSame('error', $record['level']);
        self::assertSame('[redacted]', $context['authorization']);
        self::assertSame('[redacted]', $nested['refreshToken']);
        self::assertSame('value', $nested['safe']);
        self::assertSame(RuntimeException::class, $exception['class']);
        self::assertStringNotContainsString('Bearer secret', $output);
        self::assertStringNotContainsString('must not be logged', $output);
    }

    public function testControlCharactersAreNeutralizedAndLargeValuesAreBounded(): void
    {
        $output = '';
        $logger = new StderrJsonLogger(static function (string $line) use (&$output): void {
            $output .= $line;
        });

        $logger->warning("line-one\nline-two", ['value' => str_repeat('a', 4096)]);

        /** @var array<string, mixed> $record */
        $record = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $context */
        $context = $record['context'];

        self::assertSame('line-one?line-two', $record['message']);
        self::assertSame(2048, strlen((string) $context['value']));
        self::assertSame(1, substr_count($output, "\n"));
    }

    public function testSapiIndependentWriterAppendsCompleteLogLines(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'providentia-log-');
        self::assertIsString($path);

        try {
            $writer = new StderrLineWriter($path);
            $writer("first\n");
            $writer("second\n");

            self::assertSame("first\nsecond\n", file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testSapiIndependentWriterFallsBackWhenTheStreamCannotOpen(): void
    {
        $fallbackOutput = '';
        $writer = new StderrLineWriter(
            'providentia-missing-wrapper://stderr',
            static function (string $line) use (&$fallbackOutput): void {
                $fallbackOutput .= $line;
            },
        );

        $writer("fallback\n");

        self::assertSame("fallback\n", $fallbackOutput);
    }
}
