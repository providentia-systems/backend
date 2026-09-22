<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use Providentia\SharedKernel\Application\Problem;

/** Stable recovery classifications; never infer authorization from prose. */
final class SyncProblemClassifier
{
    public const DEVICE_MISMATCH = 'https://providentia.invalid/problems/sync_device_mismatch';
    public const HOME_ACCESS_DENIED = 'https://providentia.invalid/problems/sync_home_access_denied';
    public const PERMISSION_DENIED = 'https://providentia.invalid/problems/sync_permission_denied';

    /** @return array{operationId: string, status: string, code: string, detail: string} */
    public static function result(string $operationId, Problem $problem): array
    {
        if ($problem->status === 401) {
            throw $problem;
        }
        $retryable = $problem->status >= 500 || in_array($problem->status, [408, 425, 429], true);
        [$status, $code] = match (true) {
            $retryable => ['retryable_failure', 'service_unavailable'],
            $problem->type === self::DEVICE_MISMATCH => ['authorization_failure', 'device_binding_mismatch'],
            $problem->type === self::HOME_ACCESS_DENIED => ['authorization_failure', 'home_access_denied'],
            $problem->type === self::PERMISSION_DENIED, $problem->status === 403
                => ['authorization_failure', 'permission_denied'],
            // A missing command dependency is not evidence that home membership was revoked.
            $problem->status === 404 => ['validation_error', 'resource_unavailable'],
            in_array($problem->status, [409, 412], true) => ['conflict', 'revision_conflict'],
            default => ['validation_error', 'invalid_command'],
        };

        return [
            'operationId' => $operationId,
            'status' => $status,
            'code' => $code,
            'detail' => $retryable
                ? 'The service could not process this operation. Retry the same operation.'
                : $problem->getMessage(),
        ];
    }
}
