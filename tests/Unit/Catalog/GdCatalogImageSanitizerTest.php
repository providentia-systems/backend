<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Application\CatalogImageRejection;
use Providentia\Catalog\Infrastructure\Image\GdCatalogImageSanitizer;

final class GdCatalogImageSanitizerTest extends TestCase
{
    public function testSupportedImageIsDecodedAndReencodedAsMetadataFreeWebp(): void
    {
        $sanitized = (new GdCatalogImageSanitizer())->sanitize($this->png(32, 24));

        self::assertSame('image/webp', $sanitized->mediaType);
        self::assertSame(32, $sanitized->width);
        self::assertSame(24, $sanitized->height);
        self::assertSame(hash('sha256', $sanitized->bytes), $sanitized->digest);
        self::assertSame(IMAGETYPE_WEBP, getimagesizefromstring($sanitized->bytes)[2] ?? null);
    }

    public function testMimeSpoofIsRejectedFromDecodedContentRatherThanClientHeaders(): void
    {
        $this->expectException(CatalogImageRejection::class);
        $this->expectExceptionMessage('supported image');

        (new GdCatalogImageSanitizer())->sanitize('not-a-real-jpeg-image-payload');
    }

    public function testCompressedUploadSizeIsBoundedBeforeDecode(): void
    {
        try {
            (new GdCatalogImageSanitizer())->sanitize(str_repeat('x', GdCatalogImageSanitizer::MAX_BYTES + 1));
            self::fail('An oversized upload was accepted.');
        } catch (CatalogImageRejection $rejection) {
            self::assertSame(413, $rejection->status);
        }
    }

    public function testDimensionsAreBoundedBeforeFullDecode(): void
    {
        try {
            (new GdCatalogImageSanitizer())->sanitize($this->png(
                GdCatalogImageSanitizer::MAX_DIMENSION + 1,
                16,
            ));
            self::fail('An oversized dimension was accepted.');
        } catch (CatalogImageRejection $rejection) {
            self::assertSame(422, $rejection->status);
        }
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException('Unable to create the test image.');
        }
        $color = imagecolorallocate($image, 40, 80, 120);
        if ($color === false) {
            throw new \RuntimeException('Unable to allocate the test image color.');
        }
        imagefill($image, 0, 0, $color);
        $bytes = false;
        ob_start();
        self::assertTrue(imagepng($image));
        $bytes = ob_get_clean();

        self::assertIsString($bytes);

        return $bytes;
    }
}
