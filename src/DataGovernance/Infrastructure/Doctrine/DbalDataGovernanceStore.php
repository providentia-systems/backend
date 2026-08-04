<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Providentia\DataGovernance\Application\DataGovernanceStore;

final class DbalDataGovernanceStore implements DataGovernanceStore
{
    private const SELECT_REQUEST =
        'SELECT id, request_kind AS requestKind, scope_type AS scopeType,
                subject_user_id AS subjectUserId, home_id AS homeId,
                requested_by_user_id AS requestedByUserId, status, revision,
                retained_data_disclosure_json AS retainedDataDisclosure,
                artifact_reference AS artifactReference,
                artifact_nonce AS artifactNonce, artifact_sha256 AS artifactSha256,
                artifact_size AS artifactSize,
                artifact_expires_at AS artifactExpiresAt,
                downloaded_at AS downloadedAt,
                failure_reason AS failureReason, started_at AS startedAt,
                completed_at AS completedAt, cancelled_at AS cancelledAt,
                created_at AS createdAt, updated_at AS updatedAt
         FROM data_governance_requests';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function ownedHomeIds(string $userId): array
    {
        return array_map(
            'strval',
            $this->connection->fetchFirstColumn(
                'SELECT home_id FROM home_memberships
                 WHERE user_id = :user AND role = :role AND status = :status
                 ORDER BY home_id',
                ['user' => $userId, 'role' => 'owner', 'status' => 'active'],
            ),
        );
    }

    public function createRequest(
        string $id,
        string $requestKind,
        string $scopeType,
        string $scopeFingerprint,
        ?string $subjectUserId,
        ?string $homeId,
        string $requestedByUserId,
        array $disclosure,
        DateTimeImmutable $at,
    ): void {
        $date = $this->date($at);
        try {
            $this->connection->insert('data_governance_requests', [
                'id' => $id,
                'request_kind' => $requestKind,
                'scope_type' => $scopeType,
                'scope_fingerprint' => $scopeFingerprint,
                'subject_user_id' => $subjectUserId,
                'home_id' => $homeId,
                'requested_by_user_id' => $requestedByUserId,
                'status' => 'queued',
                'active_key' => 'active',
                'revision' => 1,
                'retained_data_disclosure_json' => json_encode($disclosure, JSON_THROW_ON_ERROR),
                'artifact_reference' => null,
                'artifact_nonce' => null,
                'artifact_sha256' => null,
                'artifact_size' => null,
                'artifact_expires_at' => null,
                'download_token_hash' => null,
                'download_token_expires_at' => null,
                'downloaded_at' => null,
                'failure_reason' => null,
                'started_at' => null,
                'completed_at' => null,
                'cancelled_at' => null,
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('An active request of this kind already exists for the scope.');
        }
    }

    public function request(string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            self::SELECT_REQUEST . ' WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    public function requestsForUser(string $userId, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            self::SELECT_REQUEST
            . ' WHERE scope_type = :scope AND subject_user_id = :user'
            . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['scope' => 'account', 'user' => $userId, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    public function requestsForHome(string $homeId, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            self::SELECT_REQUEST
            . ' WHERE scope_type = :scope AND home_id = :home'
            . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['scope' => 'home', 'home' => $homeId, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    public function cancel(string $id, int $expectedRevision, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            'UPDATE data_governance_requests
             SET status = :cancelled, active_key = NULL, cancelled_at = :at,
                 revision = revision + 1, updated_at = :at
             WHERE id = :id AND status = :queued AND revision = :revision',
            [
                'cancelled' => 'cancelled',
                'at' => $this->date($at),
                'id' => $id,
                'queued' => 'queued',
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function nextQueuedRequest(): ?array
    {
        $row = $this->connection->fetchAssociative(
            self::SELECT_REQUEST . ' WHERE status = :status ORDER BY created_at, id LIMIT 1',
            ['status' => 'queued'],
        );

        return $row === false ? null : $row;
    }

    public function completeExport(
        string $id,
        int $expectedRevision,
        \Providentia\DataGovernance\Application\DataArtifact $artifact,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE data_governance_requests
             SET status = :completed, active_key = NULL, artifact_reference = :reference,
                 artifact_nonce = :nonce, artifact_sha256 = :sha256, artifact_size = :size,
                 artifact_expires_at = :expires, completed_at = :at,
                 revision = revision + 1, updated_at = :at
             WHERE id = :id AND status = :processing AND revision = :revision',
            [
                'completed' => 'completed',
                'reference' => $artifact->reference,
                'nonce' => $artifact->nonce,
                'sha256' => $artifact->sha256,
                'size' => $artifact->size,
                'expires' => $this->date($expiresAt),
                'at' => $this->date($at),
                'id' => $id,
                'processing' => 'processing',
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function setDownloadToken(
        string $id,
        int $expectedRevision,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE data_governance_requests
             SET download_token_hash = :hash, download_token_expires_at = :expires,
                 downloaded_at = NULL, revision = revision + 1, updated_at = :at
             WHERE id = :id AND status = :completed AND artifact_reference IS NOT NULL
               AND artifact_expires_at > :at AND revision = :revision',
            [
                'hash' => $tokenHash,
                'expires' => $this->date($expiresAt),
                'at' => $this->date($at),
                'id' => $id,
                'completed' => 'completed',
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function consumeDownload(string $id, string $tokenHash, DateTimeImmutable $at): ?array
    {
        $updated = $this->connection->executeStatement(
            'UPDATE data_governance_requests
             SET downloaded_at = :at, download_token_hash = NULL,
                 download_token_expires_at = NULL,
                 revision = revision + 1, updated_at = :at
             WHERE id = :id AND status = :completed AND download_token_hash = :hash
               AND downloaded_at IS NULL AND artifact_expires_at > :at
               AND download_token_expires_at > :at',
            ['at' => $this->date($at), 'id' => $id, 'completed' => 'completed', 'hash' => $tokenHash],
        );
        if ($updated !== 1) {
            return null;
        }

        return $this->request($id);
    }

    public function transition(
        string $id,
        string $fromStatus,
        string $toStatus,
        int $expectedRevision,
        ?string $artifactReference,
        ?DateTimeImmutable $artifactExpiresAt,
        ?string $failureReason,
        DateTimeImmutable $at,
    ): bool {
        $transition = $fromStatus . ':' . $toStatus;
        if (! in_array($transition, ['queued:processing', 'processing:completed', 'processing:failed'], true)) {
            throw new \InvalidArgumentException('Unsupported data-governance request transition.');
        }
        $isProcessing = $toStatus === 'processing';
        $isTerminal = in_array($toStatus, ['completed', 'failed'], true);

        return $this->connection->executeStatement(
            'UPDATE data_governance_requests
             SET status = :next_status, active_key = :active_key,
                 started_at = CASE WHEN :is_processing = 1 THEN :at ELSE started_at END,
                 completed_at = CASE WHEN :is_terminal = 1 THEN :at ELSE completed_at END,
                 artifact_reference = :artifact,
                 artifact_expires_at = :artifact_expires,
                 failure_reason = :failure,
                 revision = revision + 1, updated_at = :at
             WHERE id = :id AND status = :current_status AND revision = :revision',
            [
                'next_status' => $toStatus,
                'active_key' => $isTerminal ? null : 'active',
                'is_processing' => $isProcessing ? 1 : 0,
                'is_terminal' => $isTerminal ? 1 : 0,
                'at' => $this->date($at),
                'artifact' => $artifactReference,
                'artifact_expires' => $artifactExpiresAt === null ? null : $this->date($artifactExpiresAt),
                'failure' => $failureReason,
                'id' => $id,
                'current_status' => $fromStatus,
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.u');
    }
}
