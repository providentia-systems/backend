<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\SyncProblemClassifier;

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
            $problem = new Problem($httpStatus, 'Failure', 'Safe detail.', $type);
            $result = SyncProblemClassifier::result('operation', $problem);
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
            $problem = new Problem($status, 'Driver', 'secret database credentials');
            $result = SyncProblemClassifier::result('operation', $problem);
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
