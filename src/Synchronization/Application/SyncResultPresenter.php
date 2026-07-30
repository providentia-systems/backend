<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

final class SyncResultPresenter
{
    public function __construct(private readonly CursorCodec $cursors)
    {
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    public function applied(string $homeId, array $result): array
    {
        if (($result['status'] ?? null) !== 'accepted') {
            return $result;
        }

        $position = (int) $result['cursor'];
        $revision = (int) $result['serverRevision'];
        $deleted = (bool) $result['deleted'];
        $result['revision'] = $revision;
        $result['changeCursor'] = $this->cursors->encode($homeId, $position, $position);
        $result['representation'] = $deleted
            ? ['id' => $result['entityId'], 'revision' => $revision, 'deleted' => true]
            : array_merge(
                ['id' => $result['entityId'], 'revision' => $revision],
                (array) $result['payload'],
            );
        unset($result['cursor'], $result['serverRevision'], $result['payload'], $result['deleted']);

        return $result;
    }

    /** @param array<string, mixed> $change @return array<string, mixed> */
    public function change(string $homeId, int $highWater, array $change): array
    {
        $position = (int) $change['cursor'];
        $deleted = $change['operationType'] === 'delete';
        $representation = $deleted
            ? null
            : array_merge(
                ['id' => $change['entityId'], 'revision' => $change['revision']],
                (array) $change['payload'],
            );

        return [
            'cursor' => $this->cursors->encode($homeId, $position, $highWater),
            'entityType' => $change['entityType'],
            'entityId' => $change['entityId'],
            'operation' => $deleted ? 'delete' : 'upsert',
            'revision' => $change['revision'],
            'serverTimestamp' => $change['changedAt'],
            'representationSchemaVersion' => $change['payloadSchemaVersion'],
            ...($representation === null
                ? ['tombstone' => ['deletedAt' => $change['changedAt']]]
                : ['representation' => $representation]),
        ];
    }
}
