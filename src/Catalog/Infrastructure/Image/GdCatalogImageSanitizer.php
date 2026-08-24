<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Image;

use GdImage;
use Providentia\Catalog\Application\CatalogImageRejection;
use Providentia\Catalog\Application\CatalogImageSanitizer;
use Providentia\Catalog\Application\SanitizedCatalogImage;

final class GdCatalogImageSanitizer implements CatalogImageSanitizer
{
    public const MAX_BYTES = 5242880;
    public const MAX_DIMENSION = 4096;
    public const MAX_PIXELS = 16777216;

    public function sanitize(string $uploadedBytes): SanitizedCatalogImage
    {
        $size = strlen($uploadedBytes);
        if ($size < 16) {
            throw new CatalogImageRejection(422, 'The uploaded image is empty or incomplete.');
        }
        if ($size > self::MAX_BYTES) {
            throw new CatalogImageRejection(413, 'The uploaded image exceeds the five MiB limit.');
        }
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            throw new CatalogImageRejection(503, 'Image sanitization is unavailable.');
        }
        $information = @getimagesizefromstring($uploadedBytes);
        if (! is_array($information) || ! isset($information[0], $information[1], $information[2])) {
            throw new CatalogImageRejection(415, 'The upload is not a supported image.');
        }
        $width = (int) $information[0];
        $height = (int) $information[1];
        $type = (int) $information[2];
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new CatalogImageRejection(415, 'Only JPEG, PNG, and WebP images are accepted.');
        }
        if (
            $width < 16
            || $height < 16
            || $width > self::MAX_DIMENSION
            || $height > self::MAX_DIMENSION
            || $width * $height > self::MAX_PIXELS
        ) {
            throw new CatalogImageRejection(422, 'Image dimensions are outside the accepted limits.');
        }
        $image = @imagecreatefromstring($uploadedBytes);
        if (! $image instanceof GdImage) {
            throw new CatalogImageRejection(415, 'The image payload could not be decoded safely.');
        }
        $encoded = false;
        $sanitized = false;
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $encoded = imagewebp($image, null, 85);
            $sanitized = ob_get_clean();
        } catch (\Throwable) {
            if (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw new CatalogImageRejection(422, 'The image could not be sanitized.');
        } finally {
            imagedestroy($image);
        }
        if ($encoded !== true || ! is_string($sanitized) || $sanitized === '') {
            throw new CatalogImageRejection(422, 'The image could not be sanitized.');
        }
        if (strlen($sanitized) > self::MAX_BYTES) {
            throw new CatalogImageRejection(413, 'The sanitized image exceeds the five MiB limit.');
        }

        return new SanitizedCatalogImage(
            $sanitized,
            hash('sha256', $sanitized),
            'image/webp',
            $width,
            $height,
        );
    }
}
