<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

use InvalidArgumentException;

final class AiProviderRegistry
{
    /** @var array<string, AiProvider> */
    private array $providers = [];

    /**
     * @param iterable<AiProvider> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $id = $provider->id();
            if ($id === '' || isset($this->providers[$id])) {
                throw new InvalidArgumentException('AI provider identifiers must be non-empty and unique.');
            }
            $this->providers[$id] = $provider;
        }
    }

    public function get(string $id): ?AiProvider
    {
        return $this->providers[$id] ?? null;
    }

    /**
     * @return list<array{id: string, requiresCredential: bool}> */
    public function available(): array
    {
        return array_values(array_map(
            static fn (AiProvider $provider): array => [
                'id' => $provider->id(),
                'requiresCredential' => $provider->requiresCredential(),
            ],
            $this->providers,
        ));
    }
}
