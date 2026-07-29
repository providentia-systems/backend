<?php

declare(strict_types=1);

$path = dirname(__DIR__, 2) . '/contracts/openapi/providentia-v1.json';
$contract = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$expected = [
    '/health/live' => 'getLiveness',
    '/health/ready' => 'getReadiness',
    '/api/v1/system/info' => 'getSystemInfo',
    '/metrics' => 'getMetrics',
];

foreach ($expected as $route => $operationId) {
    if (($contract['paths'][$route]['get']['operationId'] ?? null) !== $operationId) {
        throw new RuntimeException(sprintf('Expected operation %s at %s.', $operationId, $route));
    }
}

foreach (['HealthStatus', 'ReadinessStatus', 'SystemInfo', 'ProblemDetails'] as $schema) {
    if (! isset($contract['components']['schemas'][$schema])) {
        throw new RuntimeException('Missing component schema ' . $schema);
    }
}

if (($contract['components']['schemas']['ProblemDetails']['description'] ?? '') === ''
    || ($contract['openapi'] ?? '') !== '3.1.0') {
    throw new RuntimeException('The OpenAPI/RFC 9457 baseline is incomplete.');
}

fwrite(STDOUT, "OpenAPI foundation contract passed.\n");
