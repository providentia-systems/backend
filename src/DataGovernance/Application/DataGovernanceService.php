<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Providentia\DataGovernance\Domain\RetainedDataDisclosure;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class DataGovernanceService
{
    public function __construct(
        private readonly DataGovernanceStore $store,
        private readonly HomeAuthorization $homes,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @return array<string, mixed> */
    public function requestAccountExport(AuthenticatedIdentity $identity): array
    {
        return $this->create($identity, 'account_export', 'account', $identity->userId, null);
    }

    /** @return array<string, mixed> */
    public function requestAccountErasure(AuthenticatedIdentity $identity): array
    {
        if ($this->store->isSystemOwner($identity->userId)) {
            throw new Problem(409, 'Protected account', 'The system owner cannot be erased.');
        }
        $ownedHomes = $this->store->ownedHomeIds($identity->userId);
        if ($ownedHomes !== []) {
            throw new Problem(
                409,
                'Ownership transfer required',
                'Transfer or erase every owned home before erasing this account. '
                . 'Owned homes: ' . implode(', ', $ownedHomes),
            );
        }

        return $this->create($identity, 'account_erasure', 'account', $identity->userId, null);
    }

    /** @return array<string, mixed> */
    public function requestHomeExport(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->homes->requirePermission($identity, $homeId, HomePermission::DATA_EXPORT);

        return $this->create($identity, 'home_export', 'home', null, $homeId);
    }

    /** @return array<string, mixed> */
    public function requestHomeErasure(AuthenticatedIdentity $identity, string $homeId): array
    {
        $membership = $this->homes->requirePermission($identity, $homeId, HomePermission::DATA_ERASURE);
        if ((string) $membership['role'] !== HomeAuthorization::OWNER) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }

        return $this->create($identity, 'home_erasure', 'home', null, $homeId);
    }

    /** @return list<array<string, mixed>> */
    public function accountRequests(AuthenticatedIdentity $identity, int $limit, int $offset): array
    {
        return $this->decodeRows($this->store->requestsForUser(
            $identity->userId,
            min(100, max(1, $limit)),
            max(0, $offset),
        ), $identity);
    }

    /** @return list<array<string, mixed>> */
    public function homeRequests(
        AuthenticatedIdentity $identity,
        string $homeId,
        int $limit,
        int $offset,
    ): array {
        $this->homes->requirePermission($identity, $homeId, HomePermission::DATA_EXPORT);

        return $this->decodeRows($this->store->requestsForHome(
            $homeId,
            min(100, max(1, $limit)),
            max(0, $offset),
        ), $identity);
    }

    public function cancel(
        AuthenticatedIdentity $identity,
        string $requestId,
        int $expectedRevision,
    ): void {
        $request = $this->store->request($requestId);
        if ($request === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        if ((string) $request['scopeType'] === 'account') {
            if ((string) $request['subjectUserId'] !== $identity->userId) {
                throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
            }
        } else {
            $this->homes->requirePermission(
                $identity,
                (string) $request['homeId'],
                HomePermission::DATA_EXPORT,
            );
        }
        if (! $this->store->cancel($requestId, $expectedRevision, $this->clock->now())) {
            throw new Problem(409, 'Request conflict', 'The request is no longer queued at that revision.');
        }
    }

    /** @return array<string, mixed> */
    private function create(
        AuthenticatedIdentity $identity,
        string $kind,
        string $scopeType,
        ?string $subjectUserId,
        ?string $homeId,
    ): array {
        $id = $this->ids->generate();
        $now = $this->clock->now();
        $scopeId = $subjectUserId ?? $homeId ?? '';
        $fingerprint = hash('sha256', $scopeType . ':' . $scopeId);
        $disclosure = RetainedDataDisclosure::forRequest($kind);
        try {
            $this->transactions->transactional(function () use (
                $id,
                $kind,
                $scopeType,
                $fingerprint,
                $subjectUserId,
                $homeId,
                $identity,
                $disclosure,
                $now,
            ): void {
                $this->store->createRequest(
                    $id,
                    $kind,
                    $scopeType,
                    $fingerprint,
                    $subjectUserId,
                    $homeId,
                    $identity->userId,
                    $disclosure,
                    $now,
                );
            });
        } catch (DomainException $error) {
            throw new Problem(409, 'Request already active', $error->getMessage());
        }

        return [
            'id' => $id,
            'requestKind' => $kind,
            'scopeType' => $scopeType,
            'status' => 'queued',
            'downloadEligible' => false,
            'revision' => 1,
            'retainedDataDisclosure' => $disclosure,
            'createdAt' => $now->format(DATE_ATOM),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decodeRows(array $rows, AuthenticatedIdentity $identity): array
    {
        foreach ($rows as &$row) {
            $encoded = (string) ($row['retainedDataDisclosure'] ?? '[]');
            $disclosure = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            $expiry = isset($row['artifactExpiresAt'])
                ? new DateTimeImmutable((string) $row['artifactExpiresAt'], new DateTimeZone('UTC'))
                : null;
            $eligible = ($row['status'] ?? null) === 'completed'
                && in_array($row['requestKind'] ?? null, ['account_export', 'home_export'], true)
                && (string) ($row['requestedByUserId'] ?? '') === $identity->userId
                && isset($row['artifactReference'])
                && $expiry !== null && $expiry > $this->clock->now();
            // Credential hashes, encryption nonces, storage references and the
            // requester identity are not request-list or download UI fields.
            $row = array_intersect_key($row, array_flip([
                'id', 'requestKind', 'scopeType', 'homeId', 'status',
                'artifactExpiresAt', 'failureReason', 'createdAt', 'updatedAt', 'revision',
            ]));
            $row['revision'] = (int) ($row['revision'] ?? 0);
            $row['retainedDataDisclosure'] = $disclosure;
            $row['downloadEligible'] = $eligible;
        }
        unset($row);

        return $rows;
    }
}
