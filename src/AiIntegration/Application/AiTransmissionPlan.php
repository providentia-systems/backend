<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

use Providentia\SharedKernel\Application\Problem;

/** The exact, non-secret recipients disclosed before a server extraction. */
final readonly class AiTransmissionPlan
{
    /** @var array<string, mixed> */
    private array $view;

    /**
     * @param list<array<string, mixed>> $profiles
     * @param array<string, mixed>|null $validator
     */
    public function __construct(
        string $homeId,
        string $userId,
        int $settingsRevision,
        int $policyRevision,
        array $profiles,
        ?array $validator,
    ) {
        $view = [
            'settingsRevision' => $settingsRevision,
            'policyRevision' => $policyRevision,
            'extractionProfiles' => array_map(self::recipient(...), $profiles),
            'validationProfile' => $validator === null ? null : self::recipient($validator),
        ];
        $view['sha256'] = hash('sha256', json_encode(
            [$homeId, $userId, $view],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        $this->view = $view;
    }

    /** @return array<string, mixed> */
    public function view(): array
    {
        return $this->view;
    }

    public function requireConsent(?string $sha256, ?string $selectedProfileId): void
    {
        /** @var list<array<string, mixed>> $profiles */
        $profiles = $this->view['extractionProfiles'];
        if (
            $sha256 === null
            || ! hash_equals((string) $this->view['sha256'], $sha256)
            || $profiles === []
            || $selectedProfileId !== $profiles[0]['profileId']
        ) {
            throw new Problem(
                409,
                'AI transmission plan changed',
                'Refresh the provider plan and confirm its recipients again before sending media.',
            );
        }
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private static function recipient(array $profile): array
    {
        if (
            ! is_string($profile['id'] ?? null)
            || preg_match('/^[A-Za-z0-9-]{1,36}$/D', $profile['id']) !== 1
            || ! is_int($profile['revision'] ?? null) || $profile['revision'] < 1
        ) {
            throw new Problem(
                409,
                'AI setup required',
                'Select saved, revisioned provider profiles before extraction.',
            );
        }

        return [
            'profileId' => $profile['id'],
            'revision' => $profile['revision'],
            'provider' => (string) ($profile['provider'] ?? ''),
            'model' => (string) ($profile['model'] ?? ''),
            'endpoint' => $profile['endpoint'] ?? null,
        ];
    }
}
