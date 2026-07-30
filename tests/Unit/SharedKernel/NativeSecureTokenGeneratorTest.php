<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Infrastructure\Identifier\NativeSecureTokenGenerator;

final class NativeSecureTokenGeneratorTest extends TestCase
{
    public function testGeneratedTokensAreUrlSafeAndDistinct(): void
    {
        $generator = new NativeSecureTokenGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $second);
        self::assertNotSame($first, $second);
    }

    public function testUnsafeByteCountsAreRejected(): void
    {
        $generator = new NativeSecureTokenGenerator();

        try {
            $generator->generate(15);
            self::fail('A token below the security floor was generated.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('16 to 1024', $error->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $generator->generate(1025);
    }
}
