<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Application;

use DateTimeImmutable;
use DateInterval;
use Providentia\SharedKernel\Application\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

final class DataGovernanceProcessor
{
    public function __construct(
        private readonly DataGovernanceStore $store,
        private readonly Clock $clock,
        private readonly DataExportGenerator $exports,
        private readonly DataArtifactStorage $artifacts,
        private readonly DataErasureExecutor $eraser,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processOnce(): bool
    {
        $request = $this->store->nextQueuedRequest();
        if ($request === null) {
            return false;
        }
        $id = (string) $request['id'];
        $revision = (int) $request['revision'];
        if (! $this->start($id, $revision)) {
            return true;
        }
        try {
            if (str_ends_with((string) $request['requestKind'], '_export')) {
                $json = $this->exports->generate($request);
                $artifact = $this->artifacts->store($id, $json);
                if (
                    ! $this->store->completeExport(
                        $id,
                        $revision + 1,
                        $artifact,
                        $this->clock->now()->add(new DateInterval('P1D')),
                        $this->clock->now(),
                    )
                ) {
                    $this->artifacts->delete($artifact);
                    throw new \RuntimeException('The export request changed while completing.');
                }
            } else {
                $this->eraser->erase($request);
                if (! $this->complete($id, $revision + 1, null, null)) {
                    throw new \RuntimeException('The erasure request changed while completing.');
                }
            }
        } catch (Throwable $error) {
            $this->logger->error('Data-governance processing failed.', [
                'requestId' => $id,
                'requestKind' => (string) $request['requestKind'],
                'exception' => $error,
            ]);
            $this->fail(
                $id,
                $revision + 1,
                'Data-governance processing failed. An operator can retry the request.',
            );
        }

        return true;
    }

    public function start(string $requestId, int $expectedRevision): bool
    {
        return $this->store->transition(
            $requestId,
            'queued',
            'processing',
            $expectedRevision,
            null,
            null,
            null,
            $this->clock->now(),
        );
    }

    public function complete(
        string $requestId,
        int $expectedRevision,
        ?string $artifactReference,
        ?DateTimeImmutable $artifactExpiresAt,
    ): bool {
        $request = $this->store->request($requestId);
        if ($request === null) {
            return false;
        }
        $isExport = str_ends_with((string) $request['requestKind'], '_export');
        if (
            $isExport
            && (
                $artifactReference === null
                || $artifactReference === ''
                || mb_strlen($artifactReference) > 191
                || $artifactExpiresAt === null
                || $artifactExpiresAt <= $this->clock->now()
            )
        ) {
            throw new \InvalidArgumentException('Export artifact reference is invalid.');
        }
        if (! $isExport && ($artifactReference !== null || $artifactExpiresAt !== null)) {
            throw new \InvalidArgumentException('Erasure completion cannot expose an artifact.');
        }

        return $this->store->transition(
            $requestId,
            'processing',
            'completed',
            $expectedRevision,
            $artifactReference,
            $artifactExpiresAt,
            null,
            $this->clock->now(),
        );
    }

    public function fail(string $requestId, int $expectedRevision, string $safeReason): bool
    {
        $safeReason = trim($safeReason);
        if ($safeReason === '' || mb_strlen($safeReason) > 500) {
            throw new \InvalidArgumentException('A bounded failure reason is required.');
        }

        return $this->store->transition(
            $requestId,
            'processing',
            'failed',
            $expectedRevision,
            null,
            null,
            $safeReason,
            $this->clock->now(),
        );
    }
}
