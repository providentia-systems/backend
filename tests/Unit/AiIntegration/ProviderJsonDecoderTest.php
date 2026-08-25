<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Infrastructure\Http\ProviderJsonDecoder;

final class ProviderJsonDecoderTest extends TestCase
{
    /** @return iterable<string, array{callable(string): array<string, mixed>, string}> */
    public static function invalidBoundaries(): iterable
    {
        yield 'outer HTTP body' => [
            ProviderJsonDecoder::httpResponse(...),
            'The provider returned a malformed JSON HTTP response.',
        ];
        yield 'nested structured output' => [
            ProviderJsonDecoder::structuredOutput(...),
            'The provider returned malformed structured-output JSON.',
        ];
    }

    /** @param callable(string): array<string, mixed> $decode */
    #[DataProvider('invalidBoundaries')]
    public function testInvalidProviderJsonIdentifiesItsBoundary(callable $decode, string $detail): void
    {
        foreach (['{', '[]'] as $invalid) {
            try {
                $decode($invalid);
                self::fail('Invalid provider JSON was accepted.');
            } catch (AiProviderException $error) {
                self::assertSame('provider_invalid_json', $error->safeCode);
                self::assertSame($detail, $error->safeDetail);
            }
        }
    }

    public function testJsonObjectIsReturnedWithoutChangingItsData(): void
    {
        self::assertSame(
            ['choices' => [['message' => ['content' => '{}']]]],
            ProviderJsonDecoder::httpResponse('{"choices":[{"message":{"content":"{}"}}]}'),
        );
    }
}
