<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Application;

use DateInterval;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;

final class DataGovernanceDownloadService
{
    public function __construct(
        private readonly DataGovernanceStore $store,
        private readonly HomeAuthorization $homes,
        private readonly DataArtifactStorage $artifacts,
        private readonly CredentialHasher $hasher,
        private readonly SecureTokenGenerator $tokens,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{token: string, expiresAt: string, revision: int} */
    public function issueToken(AuthenticatedIdentity $identity, string $requestId, int $expectedRevision): array
    {
        $request = $this->authorized($identity, $requestId);
        $token = $this->tokens->generate();
        $expires = $this->clock->now()->add(new DateInterval('PT15M'));
        if (! $this->store->setDownloadToken(
            $requestId,
            $expectedRevision,
            $this->hasher->hashToken($token),
            $expires,
            $this->clock->now(),
        )) {
            throw new Problem(409, 'Download conflict', 'The export is unavailable at that revision.');
        }

        return ['token' => $token, 'expiresAt' => $expires->format(DATE_ATOM), 'revision' => $expectedRevision + 1];
    }

    public function download(AuthenticatedIdentity $identity, string $requestId, string $token): string
    {
        $this->authorized($identity, $requestId);
        $request = $this->store->consumeDownload(
            $requestId,
            $this->hasher->hashToken($token),
            $this->clock->now(),
        );
        if ($request === null) {
            throw new Problem(410, 'Download unavailable', 'The one-time download is invalid, expired, or used.');
        }
        $artifact = new DataArtifact(
            (string) $request['artifactReference'],
            (string) $request['artifactNonce'],
            (string) $request['artifactSha256'],
            (int) $request['artifactSize'],
        );

        return $this->artifacts->read($requestId, $artifact);
    }

    /** @return array<string, mixed> */
    private function authorized(AuthenticatedIdentity $identity, string $requestId): array
    {
        $request = $this->store->request($requestId);
        if ($request === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        if ((string) $request['scopeType'] === 'account') {
            if ((string) $request['subjectUserId'] !== $identity->userId) {
                throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
            }
        } else {
            $this->homes->requirePermission($identity, (string) $request['homeId'], HomePermission::DATA_EXPORT);
        }

        return $request;
    }
}
