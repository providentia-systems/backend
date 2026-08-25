<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Http;

use JsonException;
use Providentia\AiIntegration\Application\AiProviderException;

final class ProviderJsonDecoder
{
    /** @return array<string, mixed> */
    public static function httpResponse(string $json): array
    {
        return self::object($json, 'The provider returned a malformed JSON HTTP response.');
    }

    /** @return array<string, mixed> */
    public static function structuredOutput(string $json): array
    {
        return self::object($json, 'The provider returned malformed structured-output JSON.');
    }

    /** @return array<string, mixed> */
    private static function object(string $json, string $safeDetail): array
    {
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiProviderException('provider_invalid_json', $safeDetail);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new AiProviderException('provider_invalid_json', $safeDetail);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
