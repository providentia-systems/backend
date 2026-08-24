<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Catalog;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Infrastructure\Security\SodiumCatalogImageCipher;

final class SodiumCatalogImageCipherTest extends TestCase
{
    public function testCurrentWriteKeyAndPreviousReadKeySupportSafeRotation(): void
    {
        $oldKey = base64_encode(str_repeat('o', 32));
        $newKey = base64_encode(str_repeat('n', 32));
        $oldCipher = new SodiumCatalogImageCipher($oldKey, 1);
        $oldEnvelope = $oldCipher->encrypt('moderated image', 'catalog-image:test');

        $rotated = new SodiumCatalogImageCipher($newKey, 2, [
            ['version' => 1, 'kek' => $oldKey],
        ]);

        self::assertSame('moderated image', $rotated->decrypt($oldEnvelope, 'catalog-image:test'));
        self::assertSame(2, $rotated->encrypt('new image', 'catalog-image:new')->keyVersion);
    }

    public function testDuplicateOrMalformedReadKeysAreRejected(): void
    {
        $key = base64_encode(str_repeat('k', 32));
        $invalidKeySets = [
            [['version' => 2, 'kek' => $key]],
            [['version' => 0, 'kek' => $key]],
            [['version' => 1, 'kek' => base64_encode('too short')]],
        ];

        foreach ($invalidKeySets as $previousKeys) {
            try {
                new SodiumCatalogImageCipher($key, 2, $previousKeys);
                self::fail('An invalid catalog image read key was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
