<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

/** Keep contract-defined maps as JSON objects without changing lists or application data. */
final class JsonObjectMaps
{
    /**
     * @param array<string, mixed> $data
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public static function serialize(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (is_array($data[$field] ?? null)) {
                $data[$field] = (object) $data[$field];
            }
        }

        return $data;
    }
}
