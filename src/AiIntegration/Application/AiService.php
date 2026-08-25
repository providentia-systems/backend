<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

use Providentia\AiIntegration\Domain\AiMode;
use Providentia\AiIntegration\Domain\ExtractionRequest;
use Providentia\AiIntegration\Application\Orchestration\AiExecution;
use Providentia\AiIntegration\Application\Orchestration\AiOrchestrator;
use Providentia\AiIntegration\Application\Media\PrivateMediaService;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Throwable;

final class AiService
{
    public function __construct(
        private readonly AiStore $store,
        private readonly AiMaturityStore $maturity,
        private readonly AiProviderRegistry $providers,
        private readonly CredentialCipher $cipher,
        private readonly ExtractionSchema $schema,
        private readonly AiOrchestrator $orchestrator,
        private readonly PrivateMediaService $media,
        private readonly HomeAuthorization $authorization,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly int $maxImageBytes,
        private readonly int $maxImages,
        private readonly SensitiveBufferEraser $buffers,
    ) {
    }

    /** @return array<string, mixed> */
    public function settings(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_READ);
        $settings = $this->store->settings($homeId) ?? [
            'mode' => AiMode::ManualOnly->value,
            'provider' => null,
            'model' => null,
            'revision' => 0,
        ];
        $settings['availableServerProviders'] = $this->providers->available();
        $settings['cloudByokOnNativeClients'] = false;
        // This legacy flag applies only to the direct extraction upload. The
        // separate private-media API stores ciphertext only after an explicit
        // transient/retained choice; the structured disclosure below is the
        // authoritative client contract.
        $settings['serverPersistsUploadedMedia'] = false;
        $settings['mediaHandling'] = [
            'directExtractionUpload' => 'transient_not_persisted',
            'privateMediaStorage' => 'explicit_encrypted_opt_in',
            'privateMediaRetentionOptions' => ['transient', 'retained'],
            'plaintextMediaAtRest' => false,
            'cloudProviderTransmissionRequiresConsent' => true,
        ];
        $settings['humanReviewRequired'] = true;
        $settings['credentialEncryptionAvailable'] = $this->cipher->available();
        $settings['providerProfiles'] = $this->publicProfiles($this->maturity->providerProfiles($homeId));
        $settings['orchestrationPolicy'] = $this->maturity->orchestrationPolicy($homeId);

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
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_MANAGE);
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
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_MANAGE);
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
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_MANAGE);
        $this->store->removeCredential($homeId, $providerId, $this->clock->now());
    }

    /** @return list<array<string, mixed>> */
    public function providerProfiles(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_READ);

        return $this->publicProfiles($this->maturity->providerProfiles($homeId));
    }

    /** @return array<string, mixed> */
    public function putProviderProfile(
        AuthenticatedIdentity $identity,
        string $homeId,
        ?string $profileId,
        string $label,
        string $providerId,
        string $model,
        ?string $credential,
        int $estimatedCostMicros,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_MANAGE);
        $label = trim($label);
        $model = trim($model);
        $provider = $this->providers->get($providerId);
        if (
            $provider === null
            || $label === ''
            || mb_strlen($label) > 80
            || preg_match('/[\x00-\x1F\x7F]/', $label) === 1
            || $model === ''
            || mb_strlen($model) > 120
            || preg_match('/[\x00-\x1F\x7F]/', $model) === 1
            || $estimatedCostMicros < 0
            || $estimatedCostMicros > 1000000000
            || $expectedRevision < 0
        ) {
            throw new Problem(422, 'Invalid AI provider profile', 'Provider profile fields are invalid.');
        }
        $id = $profileId === null || $profileId === '' ? $this->ids->generate() : $profileId;
        if (preg_match('/^[A-Za-z0-9-]{1,36}$/', $id) !== 1) {
            throw new Problem(422, 'Invalid AI provider profile', 'Provider profile identity is invalid.');
        }
        $existing = $this->maturity->providerProfile($homeId, $id);
        if (($expectedRevision === 0) !== ($existing === null)) {
            throw new Problem(409, 'Revision conflict', 'The provider profile changed on another device.');
        }
        $ciphertext = $existing['ciphertext'] ?? null;
        $nonce = $existing['nonce'] ?? null;
        $keyVersion = isset($existing['keyVersion']) ? (int) $existing['keyVersion'] : null;
        $lastFour = $existing['lastFour'] ?? null;
        if (
            $existing !== null
            && $existing['provider'] !== $providerId
            && ($credential === null || trim($credential) === '')
        ) {
            $ciphertext = $nonce = $keyVersion = $lastFour = null;
        }
        if ($credential !== null && trim($credential) !== '') {
            $credential = trim($credential);
            if (! $provider->requiresCredential() || mb_strlen($credential) < 16 || mb_strlen($credential) > 500) {
                throw new Problem(422, 'Invalid AI credential', 'The provider credential is invalid.');
            }
            if (! $this->cipher->available()) {
                throw new Problem(
                    409,
                    'AI credential encryption unavailable',
                    'Configure credential encryption first.',
                );
            }
            try {
                $encrypted = $this->cipher->encrypt($credential, $this->profileAssociatedData($homeId, $id));
            } catch (AiProviderException $error) {
                throw new Problem(503, 'AI credential encryption unavailable', $error->safeDetail);
            }
            $ciphertext = $encrypted['ciphertext'];
            $nonce = $encrypted['nonce'];
            $keyVersion = $encrypted['keyVersion'];
            $lastFour = mb_substr($credential, -4);
            if (function_exists('sodium_memzero')) {
                sodium_memzero($credential);
            }
        }
        if ($provider->requiresCredential() && ! is_string($ciphertext)) {
            throw new Problem(422, 'AI credential missing', 'This provider profile requires a credential.');
        }
        if (! $provider->requiresCredential()) {
            $ciphertext = $nonce = $keyVersion = $lastFour = null;
        }
        $saved = $this->maturity->saveProviderProfile([
            'id' => $id,
            'homeId' => $homeId,
            'label' => $label,
            'provider' => $providerId,
            'model' => $model,
            'ciphertext' => $ciphertext,
            'nonce' => $nonce,
            'keyVersion' => $keyVersion,
            'lastFour' => $lastFour,
            'estimatedCostMicros' => $estimatedCostMicros,
            'actorUserId' => $identity->userId,
        ], $expectedRevision, $this->clock->now());
        if (! $saved) {
            throw new Problem(409, 'Revision conflict', 'The provider profile changed on another device.');
        }

        return [
            'id' => $id,
            'label' => $label,
            'provider' => $providerId,
            'model' => $model,
            'credentialConfigured' => is_string($ciphertext),
            'lastFour' => $lastFour,
            'estimatedCostMicros' => $estimatedCostMicros,
            'revision' => $expectedRevision + 1,
        ];
    }

    public function removeProviderProfile(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $profileId,
        int $expectedRevision,
    ): void {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_MANAGE);
        $policy = $this->maturity->orchestrationPolicy($homeId);
        if (
            $policy !== null
            && (
                in_array($profileId, (array) $policy['extractionProfileIds'], true)
                || $policy['validationProfileId'] === $profileId
            )
        ) {
            throw new Problem(
                409,
                'Provider profile in use',
                'Update the orchestration policy before revoking this provider profile.',
            );
        }
        if (
            ! $this->maturity->revokeProviderProfile(
                $homeId,
                $profileId,
                $expectedRevision,
                $identity->userId,
                $this->clock->now(),
            )
        ) {
            throw new Problem(409, 'Revision conflict', 'The provider profile changed on another device.');
        }
    }

    /** @return array<string, mixed> */
    public function revokeProviderProfileCredential(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $profileId,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_MANAGE);
        if (preg_match('/^[A-Za-z0-9-]{1,36}$/', $profileId) !== 1 || $expectedRevision < 1) {
            throw new Problem(
                422,
                'Invalid AI provider profile',
                'A valid profile identity and positive expected revision are required.',
            );
        }
        $profile = $this->maturity->providerProfile($homeId, $profileId);
        if ($profile === null || (string) ($profile['status'] ?? '') !== 'active') {
            throw new Problem(404, 'Not found', 'The provider profile is unavailable.');
        }
        if ((int) ($profile['revision'] ?? 0) !== $expectedRevision) {
            throw new Problem(409, 'Revision conflict', 'The provider profile changed on another device.');
        }
        if (
            ($profile['ciphertext'] ?? null) === null
            && ($profile['nonce'] ?? null) === null
            && ($profile['keyVersion'] ?? null) === null
            && ($profile['lastFour'] ?? null) === null
        ) {
            return $this->publicProfile($profile);
        }
        $revoked = $this->transactions->transactional(
            fn (): bool => $this->maturity->revokeProviderProfileCredential(
                $this->ids->generate(),
                $homeId,
                $profileId,
                $expectedRevision,
                $identity->userId,
                $this->clock->now(),
            ),
        );
        if (! $revoked) {
            throw new Problem(409, 'Revision conflict', 'The provider profile changed on another device.');
        }
        $profile['ciphertext'] = null;
        $profile['nonce'] = null;
        $profile['keyVersion'] = null;
        $profile['lastFour'] = null;
        $profile['revision'] = $expectedRevision + 1;

        return $this->publicProfile($profile);
    }

    /** @return array<string, mixed> */
    public function orchestrationPolicy(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_READ);

        return $this->maturity->orchestrationPolicy($homeId) ?? [
            'extractionProfileIds' => [],
            'validationProfileId' => null,
            'maxAttempts' => 4,
            'maxTotalTokens' => 50000,
            'maxEstimatedCostMicros' => 1000000,
            'revision' => 0,
        ];
    }

    /**
     * @param list<string> $extractionProfileIds
     * @return array<string, mixed>
     */
    public function putOrchestrationPolicy(
        AuthenticatedIdentity $identity,
        string $homeId,
        array $extractionProfileIds,
        ?string $validationProfileId,
        int $maxAttempts,
        int $maxTotalTokens,
        int $maxEstimatedCostMicros,
        int $expectedRevision,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_MANAGE);
        $extractionProfileIds = array_values(array_unique(array_map('strval', $extractionProfileIds)));
        $validationProfileId = $validationProfileId === null || trim($validationProfileId) === ''
            ? null
            : trim($validationProfileId);
        if (
            $extractionProfileIds === []
            || count($extractionProfileIds) > 4
            || $maxAttempts < count($extractionProfileIds) + ($validationProfileId === null ? 0 : 1)
            || $maxAttempts > 8
            || $maxTotalTokens < 1
            || $maxTotalTokens > 1000000
            || $maxEstimatedCostMicros < 0
            || $maxEstimatedCostMicros > 1000000000
            || $expectedRevision < 0
        ) {
            throw new Problem(422, 'Invalid AI orchestration policy', 'The orchestration limits are invalid.');
        }
        $profiles = [];
        foreach ($extractionProfileIds as $profileId) {
            $profiles[$profileId] = $this->activeProfile($homeId, $profileId);
        }
        if ($validationProfileId !== null) {
            $validator = $this->activeProfile($homeId, $validationProfileId);
            foreach ($profiles as $profile) {
                if ($profile['provider'] === $validator['provider']) {
                    throw new Problem(
                        422,
                        'Independent validation required',
                        'The validation provider must differ from every extraction provider.',
                    );
                }
            }
            $profiles[$validationProfileId] = $validator;
        }
        $plannedCost = array_sum(array_map(
            static fn (array $profile): int => (int) $profile['estimatedCostMicros'],
            $profiles,
        ));
        if ($plannedCost > $maxEstimatedCostMicros) {
            throw new Problem(
                422,
                'Invalid AI orchestration policy',
                'The configured provider plan exceeds its estimated-cost budget.',
            );
        }
        if (
            ! $this->maturity->saveOrchestrationPolicy(
                $homeId,
                $extractionProfileIds,
                $validationProfileId,
                $maxAttempts,
                $maxTotalTokens,
                $maxEstimatedCostMicros,
                $expectedRevision,
                $identity->userId,
                $this->clock->now(),
            )
        ) {
            throw new Problem(409, 'Revision conflict', 'The AI orchestration policy changed on another device.');
        }

        return [
            'extractionProfileIds' => $extractionProfileIds,
            'validationProfileId' => $validationProfileId,
            'maxAttempts' => $maxAttempts,
            'maxTotalTokens' => $maxTotalTokens,
            'maxEstimatedCostMicros' => $maxEstimatedCostMicros,
            'revision' => $expectedRevision + 1,
        ];
    }

    /**
     * @param list<array{mimeType: string, bytes: string}> $additionalImages
     * @return array{id: string, status: string, candidateCount: int, observationCount: int}
     */
    public function extract(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $kind,
        ?string $targetId,
        bool $transmissionConsent,
        string $declaredMimeType,
        string $bytes,
        array $additionalImages = [],
    ): array {
        $sensitive = new SensitiveBufferScope($this->buffers);
        $sensitive->track($bytes);
        foreach ($additionalImages as &$image) {
            if (isset($image['bytes']) && is_string($image['bytes'])) {
                $sensitive->track($image['bytes']);
            }
        }
        unset($image);

        try {
            return $this->extractTracked(
                $identity,
                $homeId,
                $kind,
                $targetId,
                $transmissionConsent,
                $declaredMimeType,
                $bytes,
                $additionalImages,
                $sensitive,
            );
        } finally {
            $sensitive->eraseAll();
        }
    }

    /**
     * @param list<array{mimeType: string, bytes: string}> $additionalImages
     * @return array{id: string, status: string, candidateCount: int, observationCount: int}
     */
    private function extractTracked(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $kind,
        ?string $targetId,
        bool $transmissionConsent,
        string $declaredMimeType,
        string &$bytes,
        array &$additionalImages,
        SensitiveBufferScope $sensitive,
    ): array {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_USE);
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
        $observations = [['mimeType' => $this->validateImage($declaredMimeType, $bytes), 'bytes' => $bytes]];
        $sensitive->track($observations[0]['bytes']);
        foreach ($additionalImages as &$image) {
            if (! isset($image['mimeType'], $image['bytes'])) {
                throw new Problem(422, 'Invalid extraction', 'Every extraction observation must be an image.');
            }
            $position = count($observations);
            $observations[$position] = [
                'mimeType' => $this->validateImage((string) $image['mimeType'], (string) $image['bytes']),
                'bytes' => (string) $image['bytes'],
            ];
            $sensitive->track($observations[$position]['bytes']);
        }
        unset($image);
        if (count($observations) > $this->maxImages) {
            throw new Problem(413, 'Too many images', 'The configured multi-image observation limit was exceeded.');
        }
        $settings = $this->store->settings($homeId);
        if ($settings === null || (string) $settings['mode'] !== AiMode::ServerProxy->value) {
            throw new Problem(
                409,
                'AI server proxy disabled',
                'Enable a server-side provider or keep using the manual review flow.',
            );
        }
        $policy = $this->maturity->orchestrationPolicy($homeId);
        [$plan, $validator, $credentials] = $policy === null
            ? $this->legacyPlan($homeId, $settings)
            : $this->policyPlan($homeId, $policy);
        foreach ($credentials as &$credential) {
            $sensitive->track($credential);
        }
        unset($credential);
        if ($policy !== null) {
            $plannedCost = array_sum(array_map(
                static fn (AiExecution $execution): int => $execution->estimatedCostMicros,
                $plan,
            )) + ($validator->estimatedCostMicros ?? 0);
            if (
                $plannedCost > 0
                && count($observations) > intdiv(
                    (int) $policy['maxEstimatedCostMicros'],
                    $plannedCost,
                )
            ) {
                throw new Problem(409, 'AI budget exceeded', 'The multi-image request exceeds the policy cost budget.');
            }
        }
        $id = $this->ids->generate();
        $now = $this->clock->now();
        $first = $observations[0];
        $this->store->startExtraction(
            $id,
            $homeId,
            $kind,
            $targetId === '' ? null : $targetId,
            $policy === null ? (string) ($settings['provider'] ?? '') : 'orchestrated',
            $policy === null ? (string) ($settings['model'] ?? '') : 'policy-revision-' . $policy['revision'],
            $first['mimeType'],
            hash('sha256', implode('', array_map(static fn (array $image): string => $image['bytes'], $observations))),
            array_sum(array_map(static fn (array $image): int => strlen($image['bytes']), $observations)),
            ExtractionRequest::PROMPT_TEMPLATE_VERSION,
            $identity->userId,
            $now,
        );
        $startedAt = hrtime(true);
        $attemptPosition = 0;
        $discrepancyPosition = 0;
        try {
            $results = [];
            $usage = ['inputTokens' => 0, 'outputTokens' => 0, 'totalTokens' => 0];
            $seenDigests = [];
            foreach ($observations as $observationIndex => &$observation) {
                $digest = hash('sha256', $observation['bytes']);
                if (isset($seenDigests[$digest])) {
                    $this->maturity->recordObservationDecision(
                        $this->ids->generate(),
                        $homeId,
                        $id,
                        'exact_digest',
                        'observation:' . $seenDigests[$digest],
                        'observation:' . $observationIndex,
                        ['sha256' => $digest],
                        'confirmed_duplicate',
                        $this->clock->now(),
                    );
                    continue;
                }
                $seenDigests[$digest] = $observationIndex;
                $orchestration = $this->orchestrator->execute(
                    $kind,
                    $observation['mimeType'],
                    $observation['bytes'],
                    $plan,
                    $validator,
                    (int) ($policy['maxEstimatedCostMicros'] ?? PHP_INT_MAX),
                    (int) ($policy['maxTotalTokens'] ?? PHP_INT_MAX),
                    function (array $attempt) use ($id, $observationIndex, &$attemptPosition): void {
                        $this->maturity->appendExtractionAttempt(
                            $id,
                            $attemptPosition++,
                            $observationIndex,
                            $attempt,
                            $this->clock->now(),
                        );
                    },
                );
                $results[$observationIndex] = $orchestration->data;
                $usage = $this->addUsage($usage, $orchestration->usage);
                $this->maturity->appendExtractionDiscrepancies(
                    $id,
                    $observationIndex,
                    $discrepancyPosition,
                    $orchestration->discrepancies,
                    $this->clock->now(),
                );
                $discrepancyPosition += count($orchestration->discrepancies);
            }
            unset($observation);
            if (
                $policy !== null
                && $usage['totalTokens'] !== null
                && $usage['totalTokens'] > (int) $policy['maxTotalTokens']
            ) {
                throw new AiProviderException(
                    'orchestration_token_budget_exceeded',
                    'The AI token budget was exceeded; no candidates were released.',
                );
            }
            $result = $this->mergeObservationResults($results, $kind, $homeId, $id);
            $processingMs = max(0, (int) round((hrtime(true) - $startedAt) / 1000000));
            $this->transactions->transactional(
                function () use ($id, $homeId, $result, $usage, $processingMs): void {
                    $this->store->completeExtraction(
                        $id,
                        $homeId,
                        $result,
                        $usage,
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
        }

        $candidateCount = is_array($result['candidates']) ? count($result['candidates']) : 0;

        return [
            'id' => $id,
            'status' => 'review_required',
            'candidateCount' => $candidateCount,
            'observationCount' => count($observations),
        ];
    }

    /**
     * @param list<string> $assetIds
     * @return array<string, mixed>
     */
    public function extractStoredMedia(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $kind,
        ?string $targetId,
        bool $transmissionConsent,
        array $assetIds,
    ): array {
        $images = $this->media->extractionImages($identity, $homeId, $assetIds);
        $first = array_shift($images);
        if ($first === null) {
            throw new Problem(422, 'Invalid extraction media', 'Choose at least one private image.');
        }

        return $this->extract(
            $identity,
            $homeId,
            $kind,
            $targetId,
            $transmissionConsent,
            $first['mimeType'],
            $first['bytes'],
            $images,
        );
    }

    /** @return array<string, mixed> */
    public function extraction(AuthenticatedIdentity $identity, string $homeId, string $id): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_READ);
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
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_USE);
        if (! in_array($decision, ['accepted', 'rejected'], true)) {
            throw new Problem(422, 'Invalid AI review', 'Decision must be accepted or rejected.');
        }
        if ($position < 0 || $expectedRevision < 1) {
            throw new Problem(422, 'Invalid AI review', 'Candidate position and revision are invalid.');
        }
        if (
            $decision === 'accepted'
            && $this->maturity->hasPendingObservationDecisions($homeId, $extractionId)
        ) {
            throw new Problem(
                409,
                'Duplicate review required',
                'Resolve every possible cross-image duplicate before accepting extraction candidates.',
            );
        }
        if (
            $decision === 'accepted'
            && $this->maturity->hasBlockingExtractionDiscrepancies($homeId, $extractionId)
        ) {
            throw new Problem(
                409,
                'Validation discrepancy review required',
                'Resolve every independent-provider discrepancy before accepting extraction candidates.',
            );
        }
        if (
            $decision === 'accepted'
            && $this->maturity->candidateIsConfirmedDuplicate($homeId, $extractionId, $position)
        ) {
            throw new Problem(
                409,
                'Duplicate candidate rejected',
                'This candidate was confirmed as an overlapping observation and cannot be counted twice.',
            );
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

    public function reviewObservationDecision(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $decisionId,
        string $decision,
        int $expectedRevision,
    ): void {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_USE);
        if (! in_array($decision, ['confirmed_duplicate', 'distinct'], true) || $expectedRevision < 1) {
            throw new Problem(422, 'Invalid duplicate review', 'Choose confirmed_duplicate or distinct.');
        }
        if (
            ! $this->maturity->reviewObservationDecision(
                $homeId,
                $decisionId,
                $decision,
                $expectedRevision,
                $identity->userId,
                $this->clock->now(),
            )
        ) {
            throw new Problem(409, 'Revision conflict', 'The duplicate decision changed on another device.');
        }
    }

    public function reviewDiscrepancy(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $extractionId,
        int $position,
        string $decision,
        int $expectedRevision,
    ): void {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_USE);
        if (
            ! in_array($decision, ['accepted_primary', 'rejected_extraction'], true)
            || $position < 0
            || $expectedRevision < 1
        ) {
            throw new Problem(422, 'Invalid discrepancy review', 'Choose an allowed discrepancy decision.');
        }
        if (
            ! $this->maturity->reviewExtractionDiscrepancy(
                $homeId,
                $extractionId,
                $position,
                $decision,
                $expectedRevision,
                $identity->userId,
                $this->clock->now(),
            )
        ) {
            throw new Problem(409, 'Revision conflict', 'The discrepancy changed on another device.');
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{0: non-empty-list<AiExecution>, 1: null, 2: list<string>}
     */
    private function legacyPlan(string $homeId, array $settings): array
    {
        $providerId = (string) ($settings['provider'] ?? '');
        $model = (string) ($settings['model'] ?? '');
        $provider = $this->providers->get($providerId);
        if ($provider === null || $model === '') {
            throw new Problem(409, 'AI provider unavailable', 'The configured server provider is unavailable.');
        }
        $credential = null;
        $credentials = [];
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
            $credentials[] = $credential;
        }

        return [[new AiExecution($provider, $model, $credential)], null, $credentials];
    }

    /**
     * @param array<string, mixed> $policy
     * @return array{0: non-empty-list<AiExecution>, 1: AiExecution|null, 2: list<string>}
     */
    private function policyPlan(string $homeId, array $policy): array
    {
        $profileIds = $policy['extractionProfileIds'] ?? null;
        if (! is_array($profileIds) || $profileIds === [] || count($profileIds) > (int) $policy['maxAttempts']) {
            throw new Problem(409, 'AI policy unavailable', 'The active AI orchestration policy is invalid.');
        }
        $credentials = [];
        $plan = [];
        foreach ($profileIds as $profileId) {
            $plan[] = $this->executionForProfile($homeId, (string) $profileId, $credentials);
        }
        $validator = null;
        if (is_string($policy['validationProfileId'] ?? null) && $policy['validationProfileId'] !== '') {
            $validator = $this->executionForProfile($homeId, $policy['validationProfileId'], $credentials);
        }

        return [$plan, $validator, $credentials];
    }

    /** @param list<string> $credentials */
    private function executionForProfile(string $homeId, string $profileId, array &$credentials): AiExecution
    {
        $profile = $this->activeProfile($homeId, $profileId);
        $provider = $this->providers->get((string) $profile['provider']);
        if ($provider === null) {
            throw new Problem(409, 'AI provider unavailable', 'A policy provider is disabled on this server.');
        }
        $credential = null;
        if ($provider->requiresCredential()) {
            if (! is_string($profile['ciphertext']) || ! is_string($profile['nonce'])) {
                throw new Problem(409, 'AI credential missing', 'A policy provider credential is unavailable.');
            }
            try {
                $credential = $this->cipher->decrypt(
                    $profile['ciphertext'],
                    $profile['nonce'],
                    (int) $profile['keyVersion'],
                    $this->profileAssociatedData($homeId, $profileId),
                );
            } catch (AiProviderException $error) {
                throw new Problem(409, 'AI credential unavailable', $error->safeDetail);
            }
            $credentials[] = $credential;
        }

        return new AiExecution(
            $provider,
            (string) $profile['model'],
            $credential,
            $profileId,
            (int) $profile['estimatedCostMicros'],
        );
    }

    /** @return array<string, mixed> */
    private function activeProfile(string $homeId, string $profileId): array
    {
        $profile = $this->maturity->providerProfile($homeId, $profileId);
        if ($profile === null || $profile['status'] !== 'active') {
            throw new Problem(422, 'Invalid AI provider profile', 'An active provider profile is required.');
        }

        return $profile;
    }

    /**
     * @param list<array<string, mixed>> $profiles
     * @return list<array<string, mixed>>
     */
    private function publicProfiles(array $profiles): array
    {
        return array_map(fn (array $profile): array => $this->publicProfile($profile), $profiles);
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{
     *     id: string,
     *     label: string,
     *     provider: string,
     *     model: string,
     *     credentialConfigured: bool,
     *     lastFour: string|null,
     *     estimatedCostMicros: int,
     *     revision: int
     * }
     */
    private function publicProfile(array $profile): array
    {
        return [
            'id' => (string) $profile['id'],
            'label' => (string) $profile['label'],
            'provider' => (string) $profile['provider'],
            'model' => (string) $profile['model'],
            'credentialConfigured' => is_string($profile['ciphertext'] ?? null),
            'lastFour' => isset($profile['lastFour']) ? (string) $profile['lastFour'] : null,
            'estimatedCostMicros' => (int) $profile['estimatedCostMicros'],
            'revision' => (int) $profile['revision'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private function mergeObservationResults(array $results, string $kind, string $homeId, string $extractionId): array
    {
        $merged = reset($results);
        if (! is_array($merged)) {
            throw new AiProviderException('provider_empty_output', 'No extraction observation completed.');
        }
        $merged['warnings'] = is_array($merged['warnings']) ? $merged['warnings'] : [];
        $merged['candidates'] = [];
        /** @var array<string, array{reference: string, position: int}> $candidateKeys */
        $candidateKeys = [];
        foreach ($results as $observationIndex => $result) {
            foreach ((array) ($result['warnings'] ?? []) as $warning) {
                $merged['warnings'][] = (string) $warning;
            }
            foreach ((array) ($result['candidates'] ?? []) as $candidateIndex => $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                $key = $this->candidateObservationKey($candidate);
                $position = count($merged['candidates']);
                if (isset($candidateKeys[$key])) {
                    $candidate['warnings'][] =
                        'Possible overlap with another image; human duplicate review is required.';
                    $this->maturity->recordObservationDecision(
                        $this->ids->generate(),
                        $homeId,
                        $extractionId,
                        'visual_overlap',
                        $candidateKeys[$key]['reference'],
                        'observation:' . $observationIndex . ':candidate:' . $candidateIndex,
                        [
                            'normalizedCandidateKey' => $key,
                            'leftCandidatePosition' => $candidateKeys[$key]['position'],
                            'rightCandidatePosition' => $position,
                        ],
                        'pending',
                        $this->clock->now(),
                    );
                } else {
                    $candidateKeys[$key] = [
                        'reference' => 'observation:' . $observationIndex . ':candidate:' . $candidateIndex,
                        'position' => $position,
                    ];
                }
                $merged['candidates'][] = $candidate;
            }
        }
        $merged['warnings'] = array_values(array_unique($merged['warnings']));
        if (count($merged['candidates']) > 200) {
            throw new AiProviderException('schema_mismatch', 'The combined observations contain too many candidates.');
        }

        return $this->schema->validate($merged, $kind);
    }

    /** @param array<string, mixed> $candidate */
    private function candidateObservationKey(array $candidate): string
    {
        return hash('sha256', implode('|', [
            mb_strtolower(trim((string) ($candidate['description'] ?? ''))),
            trim((string) ($candidate['quantity'] ?? '')),
            trim((string) ($candidate['quantityMinimum'] ?? '')),
            trim((string) ($candidate['quantityMaximum'] ?? '')),
            mb_strtolower(trim((string) ($candidate['packText'] ?? ''))),
        ]));
    }

    /**
     * @param array{inputTokens: int|null, outputTokens: int|null, totalTokens: int|null} $left
     * @param array{inputTokens: int|null, outputTokens: int|null, totalTokens: int|null} $right
     * @return array{inputTokens: int|null, outputTokens: int|null, totalTokens: int|null}
     */
    private function addUsage(array $left, array $right): array
    {
        return [
            'inputTokens' => $this->addNullable($left['inputTokens'], $right['inputTokens']),
            'outputTokens' => $this->addNullable($left['outputTokens'], $right['outputTokens']),
            'totalTokens' => $this->addNullable($left['totalTokens'], $right['totalTokens']),
        ];
    }

    private function addNullable(?int $left, ?int $right): ?int
    {
        return $left === null || $right === null ? null : $left + $right;
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

    private function profileAssociatedData(string $homeId, string $profileId): string
    {
        return 'providentia-ai-profile:v1:' . $homeId . ':' . $profileId;
    }
}
