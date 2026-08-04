<?php

declare(strict_types=1);

$path = dirname(__DIR__, 2) . '/contracts/openapi/providentia-v1.json';
$contract = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$expected = [
    '/health/live' => 'getLiveness',
    '/health/ready' => 'getReadiness',
    '/api/v1/system/info' => 'getSystemInfo',
    '/metrics' => 'getMetrics',
    '/api/v1/auth/register' => 'registerAccount',
    '/api/v1/auth/login' => 'login',
    '/api/v1/auth/magic-links' => 'requestMagicLink',
    '/api/v1/auth/magic-links/exchange' => 'exchangeMagicLink',
    '/api/v1/auth/step-up-links' => 'requestStepUpLink',
    '/api/v1/homes' => 'listHomes',
    '/api/v1/homes/{homeId}/ownership-transfer' => 'transferHomeOwnership',
    '/api/v1/catalog/products' => 'searchCatalogProducts',
    '/api/v1/homes/{homeId}/sync/push' => 'pushHomeSynchronization',
    '/api/v1/homes/{homeId}/sync/pull' => 'pullHomeSynchronization',
    '/api/v1/homes/{homeId}/sync/bootstrap' => 'bootstrapHomeSynchronization',
];

foreach ($expected as $route => $operationId) {
    $operations = $contract['paths'][$route] ?? [];
    $actual = null;
    foreach (['get', 'post', 'patch', 'delete'] as $method) {
        $actual ??= $operations[$method]['operationId'] ?? null;
    }
    if ($actual !== $operationId) {
        throw new RuntimeException(sprintf('Expected operation %s at %s.', $operationId, $route));
    }
}

foreach (
    [
        'HealthStatus',
        'ReadinessStatus',
        'SystemInfo',
        'ProblemDetails',
        'RegisterRequest',
        'MagicLinkRequest',
        'MagicLinkExchangeRequest',
        'MagicLinkAccepted',
        'Home',
        'HomeMembership',
        'SyncPrivateNotePayload',
        'SyncHomePreferencePayload',
        'SyncPushRequest',
        'SyncPushResponse',
        'SyncPullResponse',
        'SyncBootstrapResponse',
        'ConsumptionEstimate',
        'ShoppingSuggestion',
        'SuggestionExplanation',
        'PriceComparison',
        'StockPreference',
        'SuggestionBacktest',
        'HomeReport',
    ] as $schema
) {
    if (! isset($contract['components']['schemas'][$schema])) {
        throw new RuntimeException('Missing component schema ' . $schema);
    }
}

if (
    ($contract['components']['schemas']['ProblemDetails']['description'] ?? '') === ''
    || ($contract['openapi'] ?? '') !== '3.1.0'
    || ($contract['components']['schemas']['SyncPushRequest']['properties']['protocolVersion']['const'] ?? null) !== 1
    || ! isset($contract['paths']['/api/v1/auth/register']['post']['responses']['202'])
    || isset($contract['paths']['/api/v1/auth/register']['post']['responses']['409'])
    || ! in_array(
        'accepted',
        $contract['components']['schemas']['RegisterResponse']['required'] ?? [],
        true,
    )
    || ($contract['info']['version'] ?? '') !== '1.9.0'
    || ($contract['components']['schemas']['PriceComparisonCollection']['properties']['currencyPolicy']['const']
        ?? '') !== 'never-compare-across-currencies'
) {
    throw new RuntimeException('The OpenAPI/RFC 9457 baseline is incomplete.');
}

fwrite(STDOUT, "OpenAPI foundation contract passed.\n");
