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
    '/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}/cancel' => [
        'post' => 'cancelStockCountSession',
    ],
    '/api/v1/homes/{homeId}/receipts/{receiptId}/lines/{lineId}/unresolve' => [
        'post' => 'unresolveReceiptLine',
    ],
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
        'ReceiptLineDecisionResult',
        'SyncReceiptLineUnresolvePayload',
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

$aiSettings = $contract['components']['schemas']['AiSettings'] ?? [];
$mediaHandling = $contract['components']['schemas']['AiMediaHandling'] ?? [];
if (
    ($aiSettings['properties']['serverPersistsUploadedMedia']['enum'] ?? null) !== [false]
    || ($aiSettings['properties']['serverPersistsUploadedMedia']['deprecated'] ?? null) !== true
    || ($aiSettings['properties']['mediaHandling']['$ref'] ?? null)
        !== '#/components/schemas/AiMediaHandling'
    || ($mediaHandling['properties']['directExtractionUpload']['const'] ?? null)
        !== 'transient_not_persisted'
    || ($mediaHandling['properties']['privateMediaStorage']['const'] ?? null)
        !== 'explicit_encrypted_opt_in'
    || ($mediaHandling['properties']['plaintextMediaAtRest']['const'] ?? null) !== false
    || ($mediaHandling['properties']['cloudProviderTransmissionRequiresConsent']['const'] ?? null) !== true
) {
    throw new RuntimeException('AI media-handling privacy contract regression detected.');
}

$itemMaster = $contract['components']['schemas']['HomeItemMasterProduct'] ?? [];
$itemMasterPage = $contract['components']['schemas']['HomeItemMasterPage'] ?? [];
$homeProductsPath = $contract['paths']['/api/v1/homes/{homeId}/products'] ?? [];
$itemMasterResponseSchema = $homeProductsPath['get']['responses']['200']['content']['application/json']['schema'] ?? [];
$createHomeProductSchema = $homeProductsPath['post']['responses']['201']['content']['application/json']['schema'] ?? [];
$syncHomeProductCreate = $contract['components']['schemas']['SyncHomeProductCreatePayload'] ?? [];
$homeAuthorizedAiAndShoppingOperations = [
    ['/api/v1/homes/{homeId}/shopping-lists', 'get', 'listShoppingLists'],
    ['/api/v1/homes/{homeId}/shopping-lists', 'post', 'createShoppingList'],
    ['/api/v1/homes/{homeId}/shopping-lists/{listId}', 'get', 'getShoppingList'],
    ['/api/v1/homes/{homeId}/shopping-lists/{listId}/lines', 'post', 'createShoppingListLine'],
    [
        '/api/v1/homes/{homeId}/shopping-lists/{listId}/lines/{lineId}/checked',
        'put',
        'setShoppingListLineChecked',
    ],
    ['/api/v1/homes/{homeId}/shopping-suggestions', 'get', 'listShoppingSuggestions'],
    ['/api/v1/homes/{homeId}/shopping-suggestion-runs', 'post', 'createShoppingSuggestionRun'],
    [
        '/api/v1/homes/{homeId}/shopping-suggestions/{suggestionId}/explanation',
        'get',
        'getShoppingSuggestionExplanation',
    ],
    [
        '/api/v1/homes/{homeId}/shopping-suggestions/{suggestionId}/feedback',
        'post',
        'createShoppingSuggestionFeedback',
    ],
    ['/api/v1/homes/{homeId}/consumption-estimates', 'get', 'listConsumptionEstimates'],
    ['/api/v1/homes/{homeId}/stock-preferences/{homeProductId}', 'get', 'getStockPreference'],
    ['/api/v1/homes/{homeId}/stock-preferences/{homeProductId}', 'put', 'putStockPreference'],
    ['/api/v1/homes/{homeId}/price-comparisons', 'get', 'listPriceComparisons'],
    ['/api/v1/homes/{homeId}/suggestion-backtests', 'post', 'createSuggestionBacktest'],
    ['/api/v1/homes/{homeId}/suggestion-backtests/{backtestId}', 'get', 'getSuggestionBacktest'],
    ['/api/v1/homes/{homeId}/ai/settings', 'get', 'getAiSettings'],
    ['/api/v1/homes/{homeId}/ai/settings', 'put', 'putAiSettings'],
    ['/api/v1/homes/{homeId}/ai/credentials/{providerId}', 'put', 'putAiProviderCredential'],
    ['/api/v1/homes/{homeId}/ai/credentials/{providerId}', 'delete', 'deleteAiProviderCredential'],
    ['/api/v1/homes/{homeId}/ai/profiles', 'get', 'listAiProviderProfiles'],
    ['/api/v1/homes/{homeId}/ai/profiles', 'post', 'createAiProviderProfile'],
    ['/api/v1/homes/{homeId}/ai/profiles/{profileId}', 'put', 'updateAiProviderProfile'],
    ['/api/v1/homes/{homeId}/ai/profiles/{profileId}', 'delete', 'deleteAiProviderProfile'],
    ['/api/v1/homes/{homeId}/ai/policy', 'get', 'getAiOrchestrationPolicy'],
    ['/api/v1/homes/{homeId}/ai/policy', 'put', 'putAiOrchestrationPolicy'],
    ['/api/v1/homes/{homeId}/ai/extractions', 'post', 'createAiExtraction'],
    [
        '/api/v1/homes/{homeId}/ai/extractions/stored-media',
        'post',
        'createAiExtractionFromStoredMedia',
    ],
    ['/api/v1/homes/{homeId}/ai/extractions/{extractionId}', 'get', 'getAiExtraction'],
    [
        '/api/v1/homes/{homeId}/ai/extractions/{extractionId}/candidates/{position}',
        'put',
        'reviewAiExtractionCandidate',
    ],
    [
        '/api/v1/homes/{homeId}/ai/extractions/{extractionId}/observations/{decisionId}',
        'put',
        'reviewAiObservationDecision',
    ],
    [
        '/api/v1/homes/{homeId}/ai/extractions/{extractionId}/discrepancies/{position}',
        'put',
        'reviewAiExtractionDiscrepancy',
    ],
    ['/api/v1/homes/{homeId}/ai/media', 'get', 'listPrivateAiMedia'],
    ['/api/v1/homes/{homeId}/ai/media', 'post', 'uploadPrivateAiMedia'],
    ['/api/v1/homes/{homeId}/ai/media/export', 'get', 'exportPrivateAiMediaMetadata'],
    ['/api/v1/homes/{homeId}/ai/media/{assetId}', 'get', 'downloadPrivateAiMedia'],
    ['/api/v1/homes/{homeId}/ai/media/{assetId}', 'delete', 'deletePrivateAiMedia'],
    [
        '/api/v1/homes/{homeId}/ai/media/{assetId}/retention',
        'put',
        'updatePrivateAiMediaRetention',
    ],
];
$expectedAiAndShoppingOperationSet = [];
foreach ($homeAuthorizedAiAndShoppingOperations as [$path, $method, $operationId]) {
    $expectedAiAndShoppingOperationSet[$path . ' ' . $method] = $operationId;
}
$actualAiAndShoppingOperationSet = [];
foreach ($contract['paths'] as $path => $pathItem) {
    foreach ($pathItem as $method => $operation) {
        if (! is_array($operation)) {
            continue;
        }
        $tags = $operation['tags'] ?? [];
        if (
            array_intersect(['AI Integration', 'Shopping', 'Intelligence'], $tags) === []
            || ! str_starts_with($path, '/api/v1/homes/{homeId}/')
        ) {
            continue;
        }
        $actualAiAndShoppingOperationSet[$path . ' ' . $method] = $operation['operationId'] ?? null;
    }
}
ksort($expectedAiAndShoppingOperationSet);
ksort($actualAiAndShoppingOperationSet);
if ($actualAiAndShoppingOperationSet !== $expectedAiAndShoppingOperationSet) {
    throw new RuntimeException('The audited home-authorized AI and shopping operation set changed.');
}

$nonDisclosingHomeDenialOperations = [
    ['/api/v1/homes/{homeId}/sync/push', 'post'],
    ['/api/v1/homes/{homeId}/sync/pull', 'get'],
    ['/api/v1/homes/{homeId}/sync/bootstrap', 'get'],
    ['/api/v1/homes/{homeId}/sync/operation-status', 'post'],
    ['/api/v1/homes/{homeId}/products', 'get'],
    ['/api/v1/homes/{homeId}/receipts/{receiptId}/lines/{lineId}/unresolve', 'post'],
];
$requiredItemMasterFields = [
    'productId',
    'packId',
    'canonicalName',
    'brand',
    'categoryId',
    'categoryName',
    'packText',
    'packStatus',
    'aliases',
    'homeProductId',
    'homeProductStatus',
    'quantity',
];
if (
    ($itemMasterResponseSchema['$ref'] ?? null) !== '#/components/schemas/HomeItemMasterPage'
    || ($itemMasterPage['properties']['pagination']['$ref'] ?? null)
        !== '#/components/schemas/OffsetPagination'
    || ($itemMaster['required'] ?? null) !== $requiredItemMasterFields
    || ($itemMaster['properties']['aliases']['uniqueItems'] ?? null) !== true
    || ($itemMaster['properties']['packStatus']['enum'] ?? null) !== ['published', 'pending-normalization']
    || ($itemMaster['properties']['homeProductStatus']['enum'] ?? null) !== ['active', null]
    || ($createHomeProductSchema['$ref'] ?? null) !== '#/components/schemas/CreatedHomeProduct'
    || count($syncHomeProductCreate['anyOf'] ?? []) !== 3
) {
    throw new RuntimeException('The paged home item-master contract is incomplete.');
}

foreach ($nonDisclosingHomeDenialOperations as [$path, $method]) {
    $responses = $contract['paths'][$path][$method]['responses'] ?? [];
    if (($responses['404'] ?? null) !== ['$ref' => '#/components/responses/Problem']) {
        throw new RuntimeException(sprintf(
            'The non-disclosing home denial response is missing from %s %s.',
            strtoupper($method),
            $path,
        ));
    }
}

foreach ($homeAuthorizedAiAndShoppingOperations as [$path, $method]) {
    $responses = $contract['paths'][$path][$method]['responses'] ?? [];
    foreach (['403', '404'] as $status) {
        if (($responses[$status] ?? null) !== ['$ref' => '#/components/responses/Problem']) {
            throw new RuntimeException(sprintf(
                'The distinct %s home-authorization response is missing from %s %s.',
                $status,
                strtoupper($method),
                $path,
            ));
        }
    }
}

$unresolvedReceiptPath = $contract['paths'][
    '/api/v1/homes/{homeId}/receipts/{receiptId}/lines/{lineId}/unresolve'
]['post'] ?? [];
$receiptLineSchema = $contract['components']['schemas']['ReceiptLine'] ?? [];
$unresolvedDecisionSchema = $contract['components']['schemas']['ReceiptLineDecisionResult'] ?? [];
$unresolvedSyncPayload = $contract['components']['schemas']['SyncReceiptLineUnresolvePayload'] ?? [];
$syncPantryCommand = $contract['components']['schemas']['SyncPantryCommand'] ?? [];
$unresolvedCommandBranch = array_values(array_filter(
    $syncPantryCommand['allOf'] ?? [],
    static fn (mixed $branch): bool => is_array($branch)
        && ($branch['if']['properties']['commandType']['const'] ?? null)
            === 'purchasing.receipt-line.unresolve',
));
if (
    ($unresolvedReceiptPath['requestBody']['content']['application/json']['schema']['$ref'] ?? null)
        !== '#/components/schemas/ExpectedRevisionRequest'
    || ($unresolvedReceiptPath['responses']['200']['content']['application/json']['schema']['$ref'] ?? null)
        !== '#/components/schemas/ReceiptLineDecisionResult'
    || ($unresolvedReceiptPath['responses']['404']['$ref'] ?? null)
        !== '#/components/responses/Problem'
    || ($receiptLineSchema['properties']['approvalStatus']['enum'] ?? null)
        !== ['unreviewed', 'approved', 'unresolved', 'approved-catalog']
    || ($unresolvedDecisionSchema['additionalProperties'] ?? null) !== false
    || ($unresolvedDecisionSchema['properties']['approvalStatus']['const'] ?? null) !== 'unresolved'
    || ($unresolvedSyncPayload['required'] ?? null) !== ['receiptId']
    || ($unresolvedSyncPayload['properties']['receiptId']['format'] ?? null) !== 'uuid'
    || ! in_array(
        'purchasing.receipt-line.unresolve',
        $syncPantryCommand['properties']['commandType']['enum'] ?? [],
        true,
    )
    || count($unresolvedCommandBranch) !== 1
    || ($unresolvedCommandBranch[0]['then']['required'] ?? null) !== ['baseRevision']
    || ($unresolvedCommandBranch[0]['then']['properties']['payload']['$ref'] ?? null)
        !== '#/components/schemas/SyncReceiptLineUnresolvePayload'
) {
    throw new RuntimeException('The durable unresolved receipt-line contract is incomplete.');
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
    || ($contract['info']['version'] ?? '') !== '1.13.2'
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
