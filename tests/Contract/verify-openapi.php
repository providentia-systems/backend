<?php

declare(strict_types=1);

$path = dirname(__DIR__, 2) . '/contracts/openapi/providentia-v1.json';
$source = (string) file_get_contents($path);
$contract = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
$expected = [
    '/health/live' => ['get' => 'getLiveness'],
    '/health/ready' => ['get' => 'getReadiness'],
    '/api/v1/system/info' => ['get' => 'getSystemInfo'],
    '/metrics' => ['get' => 'getMetrics'],
    '/api/v1/auth/register' => ['post' => 'registerAccount'],
    '/api/v1/auth/login' => ['post' => 'login'],
    '/api/v1/auth/login-links' => ['post' => 'startLoginLink'],
    '/api/v1/auth/login-links/{requestId}/status' => ['post' => 'getLoginLinkStatus'],
    '/api/v1/auth/login-links/{requestId}/exchange' => ['post' => 'exchangeLoginLink'],
    '/api/v1/auth/login-links/{requestId}/cancel' => ['post' => 'cancelLoginLink'],
    '/api/v1/auth/step-up-links' => ['post' => 'requestStepUpLink'],
    '/api/v1/me' => ['get' => 'getCurrentUser'],
    '/api/v1/me/home-invitations' => ['get' => 'listPendingHomeInvitations'],
    '/api/v1/me/home-invitations/{invitationId}/accept' => ['post' => 'acceptHomeInvitationById'],
    '/api/v1/platform/administrators' => [
        'get' => 'listPlatformAdministrators',
        'post' => 'grantPlatformAdministrator',
    ],
    '/api/v1/platform/administrators/{administratorId}/revoke' => [
        'post' => 'revokePlatformAdministrator',
    ],
    '/api/v1/homes' => ['get' => 'listHomes'],
    '/api/v1/homes/{homeId}' => ['get' => 'getHome', 'patch' => 'updateHome'],
    '/api/v1/homes/{homeId}/ownership-transfer' => ['post' => 'transferHomeOwnership'],
    '/api/v1/catalog/products' => ['get' => 'searchCatalogProducts'],
    '/api/v1/homes/{homeId}/sync/push' => ['post' => 'pushHomeSynchronization'],
    '/api/v1/homes/{homeId}/sync/pull' => ['get' => 'pullHomeSynchronization'],
    '/api/v1/homes/{homeId}/sync/bootstrap' => ['get' => 'bootstrapHomeSynchronization'],
];

foreach ($expected as $route => $methods) {
    foreach ($methods as $method => $operationId) {
        $actual = $contract['paths'][$route][$method]['operationId'] ?? null;
        if ($actual !== $operationId) {
            throw new RuntimeException(sprintf(
                'Expected operation %s at %s %s.',
                $operationId,
                strtoupper($method),
                $route,
            ));
        }
    }
}

foreach (
    [
        'HealthStatus',
        'ReadinessStatus',
        'SystemInfo',
        'ProblemDetails',
        'RegisterRequest',
        'LoginLinkStartRequest',
        'LoginLinkStarted',
        'LoginLinkRequestProof',
        'LoginLinkStatus',
        'LoginLinkExchangeRequest',
        'StepUpLinkAccepted',
        'SessionTransport',
        'SessionCredentials',
        'DeviceSession',
        'CurrentUserBootstrap',
        'PlatformAdministrator',
        'PlatformAdministratorGrantRequest',
        'PlatformAdministratorRevokeRequest',
        'Home',
        'UpdateHomeRequest',
        'HomeMembership',
        'RecipientHomeInvitation',
        'HomeInvitationAcceptance',
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

$sessionProperties = $contract['components']['schemas']['SessionCredentials']['properties'] ?? [];
foreach (['accessToken', 'refreshToken', 'csrfToken'] as $responseCredential) {
    if (
        ($sessionProperties[$responseCredential]['readOnly'] ?? false) !== true
        || isset($sessionProperties[$responseCredential]['writeOnly'])
    ) {
        throw new RuntimeException(
            sprintf('SessionCredentials.%s must be a response-only field.', $responseCredential),
        );
    }
}

foreach (
    [
        ['RegisterResponse', 'developmentVerificationToken'],
        ['StepUpLinkAccepted', 'developmentStepUpToken'],
        ['InvitationCreated', 'developmentInvitationToken'],
    ] as [$schema, $developmentToken]
) {
    $property = $contract['components']['schemas'][$schema]['properties'][$developmentToken] ?? [];
    if (($property['readOnly'] ?? false) !== true || isset($property['writeOnly'])) {
        throw new RuntimeException(sprintf('%s.%s must be response-only.', $schema, $developmentToken));
    }
}

foreach (
    [
        ['LoginLinkStartRequest', 'pollChallenge'],
        ['LoginLinkStartRequest', 'codeChallenge'],
        ['LoginLinkStartRequest', 'state'],
        ['LoginLinkRequestProof', 'pollToken'],
        ['LoginLinkExchangeRequest', 'pollToken'],
        ['LoginLinkExchangeRequest', 'codeVerifier'],
        ['LoginLinkExchangeRequest', 'state'],
    ] as [$schema, $requestCredential]
) {
    $property = $contract['components']['schemas'][$schema]['properties'][$requestCredential] ?? [];
    if (($property['writeOnly'] ?? false) !== true || isset($property['readOnly'])) {
        throw new RuntimeException(sprintf('%s.%s must be request-only.', $schema, $requestCredential));
    }
}

$loginLinkStartRequired = $contract['components']['schemas']['LoginLinkStartRequest']['required'] ?? [];
foreach (
    [
        'requestId',
        'email',
        'pollChallenge',
        'codeChallenge',
        'codeChallengeMethod',
        'state',
        'installationId',
        'deviceName',
        'platform',
        'transport',
    ] as $field
) {
    if (! in_array($field, $loginLinkStartRequired, true)) {
        throw new RuntimeException('LoginLinkStartRequest must require ' . $field . '.');
    }
}

$loginLinkStarted = $contract['components']['schemas']['LoginLinkStarted'];
foreach (['accepted', 'requestId', 'expiresAt', 'pollIntervalSeconds'] as $field) {
    if (! in_array($field, $loginLinkStarted['required'] ?? [], true)) {
        throw new RuntimeException('LoginLinkStarted must require ' . $field . '.');
    }
}

$loginLinkStatuses = $contract['components']['schemas']['LoginLinkStatus']['properties']['status']['enum'] ?? [];
if (! in_array('denied', $loginLinkStatuses, true)) {
    throw new RuntimeException('LoginLinkStatus must expose the terminal denied browser decision.');
}

$bootstrap = $contract['components']['schemas']['CurrentUserBootstrap'] ?? [];
if (
    ! in_array('pendingInvitations', $bootstrap['required'] ?? [], true)
    || ($bootstrap['properties']['pendingInvitations']['items']['$ref'] ?? null)
        !== '#/components/schemas/RecipientHomeInvitation'
) {
    throw new RuntimeException('CurrentUserBootstrap must include pending recipient invitations.');
}
foreach (['pollToken', 'pollSecret', 'codeVerifier', 'accessToken', 'refreshToken', 'csrfToken'] as $secret) {
    if (isset($loginLinkStarted['properties'][$secret])) {
        throw new RuntimeException('LoginLinkStarted must not return ' . $secret . '.');
    }
}

$sessionRequired = $contract['components']['schemas']['SessionCredentials']['required'] ?? [];
foreach (
    [
        'transport',
        'installationId',
        'accessExpiresAt',
        'refreshExpiresAt',
        'idleExpiresAt',
        'refreshIdleTtlSeconds',
        'activeHomeId',
    ] as $field
) {
    if (! in_array($field, $sessionRequired, true)) {
        throw new RuntimeException('SessionCredentials must require ' . $field . '.');
    }
}

$deviceSessionRequired = $contract['components']['schemas']['DeviceSession']['required'] ?? [];
foreach (['transport', 'current', 'accessExpiresAt', 'refreshExpiresAt', 'idleExpiresAt'] as $field) {
    if (! in_array($field, $deviceSessionRequired, true)) {
        throw new RuntimeException('DeviceSession must require ' . $field . '.');
    }
}

$expectedHomePermissions = [
    'home.read',
    'home.manage',
    'members.read',
    'members.invite',
    'members.manage',
    'permissions.manage',
    'ownership.transfer',
    'inventory.read',
    'inventory.write',
    'inventory.manage',
    'purchases.read',
    'purchases.write',
    'shopping.read',
    'shopping.write',
    'shopping.manage',
    'ai.read',
    'ai.use',
    'ai.manage',
    'reports.read',
    'catalog.contribute',
    'catalog.import',
    'catalog.consent.manage',
    'data.export',
    'data.erasure',
    'billing.read',
    'billing.manage',
];
$actualHomePermissions = $contract['components']['schemas']['HomePermission']['enum'] ?? [];
if ($actualHomePermissions !== $expectedHomePermissions) {
    throw new RuntimeException('HomePermission must enumerate every implemented permission in stable order.');
}

$expectedPlatformRoles = [
    'platform_administrator',
    'catalog_curator',
    'catalog_reviewer',
    'billing_operator',
];
if (($contract['components']['schemas']['PlatformRole']['enum'] ?? []) !== $expectedPlatformRoles) {
    throw new RuntimeException('PlatformRole must enumerate every implemented platform role.');
}

$refreshRequest = $contract['paths']['/api/v1/auth/refresh']['post']['requestBody'] ?? [];
$refreshRequestToken = $refreshRequest['content']['application/json']['schema']['properties']['refreshToken'] ?? [];
if (($refreshRequestToken['writeOnly'] ?? false) !== true || isset($refreshRequestToken['readOnly'])) {
    throw new RuntimeException('The refresh request credential must remain request-only.');
}

if (
    ($contract['components']['schemas']['ProblemDetails']['description'] ?? '') === ''
    || ($contract['openapi'] ?? '') !== '3.1.0'
    || ($contract['components']['schemas']['SyncPushRequestV1']['properties']['protocolVersion']['const'] ?? null) !== 1
    || ($contract['components']['schemas']['SyncPushRequestV2']['properties']['protocolVersion']['const'] ?? null) !== 2
    || ! isset($contract['paths']['/api/v1/auth/register']['post']['responses']['202'])
    || isset($contract['paths']['/api/v1/auth/register']['post']['responses']['409'])
    || ! in_array(
        'accepted',
        $contract['components']['schemas']['RegisterResponse']['required'] ?? [],
        true,
    )
    || ($contract['info']['version'] ?? '') !== '1.11.1'
    || stripos($source, 'magic' . '-link') !== false
    || stripos($source, 'magic' . 'link') !== false
    || isset($contract['paths']['/api/v1/auth/' . 'magic' . '-links'])
    || isset($contract['paths']['/api/v1/auth/' . 'magic' . '-links/exchange'])
    || ($contract['components']['schemas']['LoginLinkStartRequest']['properties']['codeChallengeMethod']['const']
        ?? '') !== 'S256'
    || ($contract['components']['schemas']['SessionTransport']['enum'] ?? []) !== ['web', 'native']
    || ($contract['paths']['/api/v1/home-invitations/accept']['post']['deprecated'] ?? false) !== true
    || ($contract['components']['schemas']['PriceComparisonCollection']['properties']['currencyPolicy']['const']
        ?? '') !== 'never-compare-across-currencies'
) {
    throw new RuntimeException('The OpenAPI/RFC 9457 baseline is incomplete.');
}

fwrite(STDOUT, "OpenAPI foundation contract passed.\n");
