<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

final class SyncRequestHasher
{
    public function hash(SyncOperation $operation): string
    {
        return hash('sha256', $this->canonicalJson($operation->requestShape()));
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item);

            return array_map($normalize, $item);
        };

        return json_encode(
            $normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
