<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

use Providentia\AiIntegration\Domain\AiMode;
use Providentia\AiIntegration\Domain\ExtractionRequest;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Throwable;

final class AiService
{
    private const WRITERS = [
        HomeAuthorization::OWNER,
        HomeAuthorization::MANAGER,
        HomeAuthorization::MEMBER,
    ];

    public function __construct(
        private readonly AiStore $store,
        private readonly AiProviderRegistry $providers,
        private readonly CredentialCipher $cipher,
        private readonly ExtractionSchema $schema,
        private readonly HomeAuthorization $authorization,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly int $maxImageBytes,
    ) {
    }

    /** @return array<string, mixed> */
    public function settings(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requireMember($identity, $homeId);
        $settings = $this->store->settings($homeId) ?? [
            'mode' => AiMode::ManualOnly->value,
            'provider' => null,
            'model' => null,
            'revision' => 0,
        ];
        $settings['availableServerProviders'] = $this->providers->available();
        $settings['cloudByokOnNativeClients'] = false;
        $settings['serverPersistsUploadedMedia'] = false;
        $settings['humanReviewRequired'] = true;
        $settings['credentialEncryptionAvailable'] = $this->cipher->available();

        return $settings;
    }

    /** @return array{mode: string, provider: string|null, model: string|null, revision: int} */
    public function configure(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $mode,
        ?string $provider,
        ?string $model,
        int $expectedRevision,
    ): array {
        $this->authorization->requireRole($identity, $homeId, [
            HomeAuthorization::OWNER,
            HomeAuthorization::MANAGER,
        ]);
        $parsedMode = AiMode::tryFrom($mode);
        if ($parsedMode === null) {
            throw new Problem(422, 'Invalid AI settings', 'AI mode is not supported.');
        }
        $provider = $provider === null ? null : trim($provider);
        $model = $model === null ? null : trim($model);
        if ($parsedMode === AiMode::ManualOnly) {
            $provider = null;
            $model = null;
        } elseif ($parsedMode === AiMode::ServerProxy) {
            $selectedProvider = $provider === null ? null : $this->providers->get($provider);
            if ($selectedProvider === null) {
                throw new Problem(422, 'Invalid AI settings', 'Choose an enabled server-side provider.');
            }
            if ($selectedProvider->requiresCredential() && ! $this->cipher->available()) {
                throw new Problem(
                    409,
                    'AI credential encryption unavailable',
                    'Configure the server credential-encryption key before enabling this provider.',
                );
            }
            if ($model === null || $model === '' || mb_strlen($model) > 120) {
                throw new Problem(422, 'Invalid AI settings', 'Choose a configured provider model.');
            }
        } else {
            if ($provider !== 'ollama' || $model === null || $model === '') {
                throw new Problem(
                    422,
                    'Invalid AI settings',
                    'Local-direct mode requires an explicit Ollama model on the client.',
                );
            }
        }
        if (
            ! $this->store->saveSettings(
                $homeId,
                $parsedMode->value,
                $provider,
                $model,
                $expectedRevision,
                $identity->userId,
                $this->clock->now(),
            )
        ) {
            throw new Problem(409, 'Revision conflict', 'AI settings changed on another device.');
        }

        return [
            'mode' => $parsedMode->value,
            'provider' => $provider,
            'model' => $model,
            'revision' => $expectedRevision + 1,
        ];
    }

    /** @return array{provider: string, configured: bool, lastFour: string} */
    public function putCredential(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $providerId,
        string $credential,
    ): array {
        $this->authorization->requireRole($identity, $homeId, [
            HomeAuthorization::OWNER,
            HomeAuthorization::MANAGER,
        ]);
        $provider = $this->providers->get($providerId);
        $credential = trim($credential);
        if ($provider === null || ! $provider->requiresCredential()) {
            throw new Problem(422, 'Invalid AI credential', 'This provider does not accept a server credential.');
        }
        if (! $this->cipher->available()) {
            throw new Problem(
                409,
                'AI credential encryption unavailable',
                'Configure the server credential-encryption key before storing provider credentials.',
            );
        }
        if (mb_strlen($credential) < 16 || mb_strlen($credential) > 500) {
            throw new Problem(422, 'Invalid AI credential', 'Credential length is outside the accepted range.');
        }
        try {
            $encrypted = $this->cipher->encrypt(
                $credential,
                $this->associatedData($homeId, $providerId),
            );
        } catch (AiProviderException $error) {
            throw new Problem(503, 'AI credential encryption unavailable', $error->safeDetail);
        }
        $lastFour = mb_substr($credential, -4);
        $this->store->saveCredential(
            $this->ids->generate(),
            $homeId,
            $providerId,
            $encrypted['ciphertext'],
            $encrypted['nonce'],
            $encrypted['keyVersion'],
            $lastFour,
            $identity->userId,
            $this->clock->now(),
        );

        return ['provider' => $providerId, 'configured' => true, 'lastFour' => $lastFour];
    }

    public function removeCredential(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $providerId,
    ): void {
        $this->authorization->requireRole($identity, $homeId, [
            HomeAuthorization::OWNER,
            HomeAuthorization::MANAGER,
        ]);
        $this->store->removeCredential($homeId, $providerId, $this->clock->now());
    }

    /** @return array{id: string, status: string, candidateCount: int} */
    public function extract(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $kind,
        ?string $targetId,
        bool $transmissionConsent,
        string $declaredMimeType,
        string $bytes,
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
        if (! $transmissionConsent) {
            throw new Problem(
                422,
                'Transmission consent required',
                'Confirm the selected provider and privacy mode before sending an image.',
            );
        }
        if (! in_array($kind, ['receipt', 'stock'], true)) {
            throw new Problem(422, 'Invalid extraction', 'Extraction kind must be receipt or stock.');
        }
        if (! $this->store->targetExists($homeId, $kind, $targetId)) {
            throw new Problem(404, 'Not found', 'The requested extraction target is unavailable.');
        }
        $mimeType = $this->validateImage($declaredMimeType, $bytes);
        $settings = $this->store->settings($homeId);
        if ($settings === null || (string) $settings['mode'] !== AiMode::ServerProxy->value) {
            throw new Problem(
                409,
                'AI server proxy disabled',
                'Enable a server-side provider or keep using the manual review flow.',
            );
        }
        $providerId = (string) ($settings['provider'] ?? '');
        $model = (string) ($settings['model'] ?? '');
        $provider = $this->providers->get($providerId);
        if ($provider === null || $model === '') {
            throw new Problem(409, 'AI provider unavailable', 'The configured server provider is unavailable.');
        }
        $credential = null;
        if ($provider->requiresCredential()) {
            $stored = $this->store->credential($homeId, $providerId);
            if ($stored === null) {
                throw new Problem(409, 'AI credential missing', 'Configure the server provider credential first.');
            }
            try {
                $credential = $this->cipher->decrypt(
                    (string) $stored['ciphertext'],
                    (string) $stored['nonce'],
                    (int) $stored['keyVersion'],
                    $this->associatedData($homeId, $providerId),
                );
            } catch (AiProviderException $error) {
                throw new Problem(409, 'AI credential unavailable', $error->safeDetail);
            }
        }
        $id = $this->ids->generate();
        $now = $this->clock->now();
        $this->store->startExtraction(
            $id,
            $homeId,
            $kind,
            $targetId === '' ? null : $targetId,
            $providerId,
            $model,
            $mimeType,
            hash('sha256', $bytes),
            strlen($bytes),
            ExtractionRequest::PROMPT_TEMPLATE_VERSION,
            $identity->userId,
            $now,
        );
        $startedAt = hrtime(true);
        try {
            $outcome = $provider->extract(new ExtractionRequest(
                $kind,
                $mimeType,
                $bytes,
                $model,
                $credential,
            ));
            $result = $this->schema->validate($outcome->data, $kind);
            $processingMs = max(0, (int) round((hrtime(true) - $startedAt) / 1000000));
            $this->transactions->transactional(
                function () use ($id, $homeId, $result, $outcome, $processingMs): void {
                    $this->store->completeExtraction(
                        $id,
                        $homeId,
                        $result,
                        $outcome->usage,
                        $processingMs,
                        $this->clock->now(),
                    );
                },
            );
        } catch (AiProviderException $error) {
            $this->store->failExtraction(
                $id,
                $homeId,
                $error->safeCode,
                $error->safeDetail,
                $this->clock->now(),
            );
            throw new Problem(502, 'AI extraction failed', $error->safeDetail);
        } catch (Throwable) {
            $detail = 'The provider request could not be completed safely.';
            $this->store->failExtraction($id, $homeId, 'provider_failure', $detail, $this->clock->now());
            throw new Problem(502, 'AI extraction failed', $detail);
        } finally {
            if ($credential !== null && function_exists('sodium_memzero')) {
                sodium_memzero($credential);
            }
        }

        $candidateCount = is_array($result['candidates']) ? count($result['candidates']) : 0;

        return ['id' => $id, 'status' => 'review_required', 'candidateCount' => $candidateCount];
    }

    /** @return array<string, mixed> */
    public function extraction(AuthenticatedIdentity $identity, string $homeId, string $id): array
    {
        $this->authorization->requireMember($identity, $homeId);
        $extraction = $this->store->extraction($homeId, $id);
        if ($extraction === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }

        return $extraction;
    }

    public function reviewCandidate(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $extractionId,
        int $position,
        string $decision,
        int $expectedRevision,
    ): void {
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
        if (! in_array($decision, ['accepted', 'rejected'], true)) {
            throw new Problem(422, 'Invalid AI review', 'Decision must be accepted or rejected.');
        }
        if ($position < 0 || $expectedRevision < 1) {
            throw new Problem(422, 'Invalid AI review', 'Candidate position and revision are invalid.');
        }
        if (
            ! $this->store->reviewCandidate(
                $homeId,
                $extractionId,
                $position,
                $decision,
                $expectedRevision,
                $identity->userId,
                $this->clock->now(),
            )
        ) {
            throw new Problem(409, 'Revision conflict', 'The AI candidate changed on another device.');
        }
    }

    private function validateImage(string $declaredMimeType, string $bytes): string
    {
        $length = strlen($bytes);
        if ($length < 16 || $length > $this->maxImageBytes) {
            throw new Problem(413, 'Image rejected', 'Image size is outside the configured limit.');
        }
        $detected = match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
        if ($detected === null || ($declaredMimeType !== '' && $declaredMimeType !== $detected)) {
            throw new Problem(415, 'Image rejected', 'Only verified JPEG, PNG, or WebP images are accepted.');
        }
        if (
            str_contains($bytes, "Exif\x00\x00")
            || str_contains($bytes, 'eXIf')
            || ($detected === 'image/webp' && str_contains($bytes, 'EXIF'))
        ) {
            throw new Problem(
                422,
                'Image metadata must be removed',
                'Remove EXIF metadata on the device before transmission.',
            );
        }

        return $detected;
    }

    private function associatedData(string $homeId, string $provider): string
    {
        return 'providentia-ai-credential:v1:' . $homeId . ':' . $provider;
    }
}
