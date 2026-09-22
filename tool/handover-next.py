#!/usr/bin/env python3
"""Temporary branch-scoped applicator; never reads runtime or user data."""
from pathlib import Path

p = Path('src/Synchronization/Application/SyncProblemClassifier.php')
p.write_text('''<?php

declare(strict_types=1);

namespace Providentia\\Synchronization\\Application;

use Providentia\\SharedKernel\\Application\\Problem;

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
''')
p = Path('src/Synchronization/Application/SyncEnvelopeValidator.php')
s = p.read_text()
needle = "                'The synchronization device does not match the session.',\n"
if 'SyncProblemClassifier::DEVICE_MISMATCH' not in s:
    assert needle in s
    s = s.replace(needle, needle + '                SyncProblemClassifier::DEVICE_MISMATCH,\n')
p.write_text(s)
p = Path('src/Synchronization/Application/SynchronizationService.php')
s = p.read_text()
if 'private function requireMember(' not in s:
    s = s.replace('$this->authorization->requireMember($identity, $homeId)', '$this->requireMember($identity, $homeId)')
    old = "throw new Problem(403, 'Device mismatch', 'Operation receipts are bound to the authenticated device.');"
    assert old in s
    s = s.replace(old, "throw new Problem(\n                403,\n                'Device mismatch',\n                'Operation receipts are bound to the authenticated device.',\n                SyncProblemClassifier::DEVICE_MISMATCH,\n            );")
    a = s.index('    /** @return array{operationId: string, status: string, detail: string} */')
    b = s.index('    /** A concurrent revocation', a)
    s = s[:a] + '''    /** @return array{operationId: string, status: string, code: string, detail: string} */
    private function problemResult(string $operationId, Problem $problem): array
    {
        return SyncProblemClassifier::result($operationId, $problem);
    }

    /** @return array<string, mixed> */
    private function requireMember(AuthenticatedIdentity $identity, string $homeId): array
    {
        try {
            return $this->authorization->requireMember($identity, $homeId);
        } catch (Problem $problem) {
            if ($problem->status !== 404) {
                throw $problem;
            }
            // Only this membership check establishes the home-access classification.
            // The public 404 remains non-enumerating and carries no resource details.
            throw new Problem(
                404,
                'Not found',
                'The requested resource is unavailable.',
                SyncProblemClassifier::HOME_ACCESS_DENIED,
            );
        }
    }

''' + s[b:]
    s = s.replace("                    'status' => 'authorization_failure',\n                    'detail' => 'The current home role is read-only.',", "                    'status' => 'authorization_failure',\n                    'code' => 'permission_denied',\n                    'detail' => 'The current home role is read-only.',")
p.write_text(s)
# Mark permission denials at their source without changing membership privacy,
# status codes, access decisions or audit boundaries.
p = Path('src/Home/Application/HomeAuthorization.php')
s = p.read_text()
old = "        if (! $allowed) {\n            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');\n        }"
new = "        if (! $allowed) {\n            throw new Problem(\n                404,\n                'Not found',\n                'The requested resource is unavailable.',\n                'https://providentia.invalid/problems/sync_permission_denied',\n            );\n        }"
if old in s:
    s = s.replace(old, new)
p.write_text(s)
p = Path('tests/Unit/Synchronization/SyncProblemClassifierTest.php')
p.write_text('''<?php

declare(strict_types=1);

namespace ProvidentiaTest\\Unit\\Synchronization;

use PHPUnit\\Framework\\TestCase;
use Providentia\\SharedKernel\\Application\\Problem;
use Providentia\\Synchronization\\Application\\SyncProblemClassifier;

final class SyncProblemClassifierTest extends TestCase
{
    public function testMachineTypesDistinguishDeviceMembershipPermissionAndResourceFailures(): void
    {
        $cases = [
            [403, SyncProblemClassifier::DEVICE_MISMATCH, 'authorization_failure', 'device_binding_mismatch'],
            [404, SyncProblemClassifier::HOME_ACCESS_DENIED, 'authorization_failure', 'home_access_denied'],
            [404, SyncProblemClassifier::PERMISSION_DENIED, 'authorization_failure', 'permission_denied'],
            [404, 'about:blank', 'validation_error', 'resource_unavailable'],
            [403, 'about:blank', 'authorization_failure', 'permission_denied'],
            [409, 'about:blank', 'conflict', 'revision_conflict'],
            [412, 'about:blank', 'conflict', 'revision_conflict'],
            [422, 'about:blank', 'validation_error', 'invalid_command'],
        ];
        foreach ($cases as [$httpStatus, $type, $status, $code]) {
            $result = SyncProblemClassifier::result('operation', new Problem($httpStatus, 'Failure', 'Safe detail.', $type));
            self::assertSame([
                'operationId' => 'operation',
                'status' => $status,
                'code' => $code,
                'detail' => 'Safe detail.',
            ], $result);
        }
    }

    public function testProseCannotConvertAnUnclassifiedResourceFailureIntoRevocation(): void
    {
        $result = SyncProblemClassifier::result('operation', new Problem(
            404,
            'Device mismatch',
            'Membership revoked or session device mismatch: untrusted prose.',
        ));
        self::assertSame('resource_unavailable', $result['code']);
        self::assertSame('validation_error', $result['status']);
    }

    public function testTransientFailuresNeverExposeInfrastructureDetails(): void
    {
        foreach ([408, 425, 429, 500, 503] as $status) {
            $result = SyncProblemClassifier::result('operation', new Problem($status, 'Driver', 'secret database credentials'));
            self::assertSame('retryable_failure', $result['status']);
            self::assertSame('service_unavailable', $result['code']);
            self::assertStringNotContainsString('secret', $result['detail']);
        }
    }

    public function testAuthenticationRemainsARequestFailureNotATerminalCommandReceipt(): void
    {
        $problem = new Problem(401, 'Sign in', 'Authentication required.');
        try {
            SyncProblemClassifier::result('operation', $problem);
            self::fail('Authentication was incorrectly persisted as a command outcome.');
        } catch (Problem $caught) {
            self::assertSame($problem, $caught);
        }
    }
}
''')
p = Path('tests/Unit/Synchronization/SyncEnvelopeValidatorTest.php')
s = p.read_text()
s = s.replace("            self::assertSame(403, $problem->status);", "            self::assertSame(403, $problem->status);\n            self::assertSame(\\Providentia\\Synchronization\\Application\\SyncProblemClassifier::DEVICE_MISMATCH, $problem->type);")
p.write_text(s)
Path('docs/handover-sync-classification.md').write_text('''# Synchronization failure classifications

The existing problem `type` and operation-result `code` fields carry stable
recovery classifications; status codes and authorization decisions are not
relaxed. Envelope/status device mismatch uses `sync_device_mismatch`.
Only the synchronization membership guard emits `sync_home_access_denied`.
A denied command permission is distinct from a missing referenced record;
ordinary command-level 404 failures use `resource_unavailable` rather than
asserting membership revocation. Service failures retain sanitized details.

Clients must not purge a home, rebind immutable queued work, refresh credentials,
or create replacement operation IDs solely because of a generic 403/404 or
English error text. Authentication remains a request-level 401. Lost outcomes
remain recoverable through the existing exact account/home/device-scoped
operation receipts. This classification change does not itself recover any
historical queue or establish authorship for schema-2 records.
''')
print('Typed synchronization problem classifications and regressions applied.')
