<?php

declare(strict_types=1);

$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
};

$environment = mb_strtolower($env('APP_ENV', 'development'));
$tokenPepper = $env('AUTH_TOKEN_PEPPER', 'development-only-change-me');
$cursorSecret = $env('SYNC_CURSOR_SECRET', 'development-sync-secret-change-me');
$mailDsn = $env('MAIL_DSN', 'smtp://127.0.0.1:1025');
$applicationLinkAllowedHosts = array_values(array_unique(array_filter(array_map(
    static fn (string $host): string => mb_strtolower(trim($host)),
    explode(',', $env('AUTH_APP_LINK_ALLOWED_HOSTS', 'login-link,localhost,127.0.0.1')),
))));
$applicationLink = static function (
    string $environmentName,
    string $value,
    string $name,
    string $nativeScheme,
    array $allowedHosts,
): string {
    $value = rtrim(trim($value), '/');
    $parts = parse_url($value);
    $allowedSchemes = ['https', $nativeScheme];
    if ($environmentName !== 'production') {
        $allowedSchemes[] = 'http';
    }
    $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
    $host = mb_strtolower((string) ($parts['host'] ?? ''));
    if (
        $value === ''
        || $parts === false
        || $scheme === ''
        || $host === ''
        || ! in_array($scheme, $allowedSchemes, true)
        || ! in_array($host, $allowedHosts, true)
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
        || str_contains($value, "\r")
        || str_contains($value, "\n")
    ) {
        throw new RuntimeException(
            $name . ' must be an absolute application URL with an allowlisted scheme and host, '
            . 'without credentials, query, or fragment.',
        );
    }

    return $value;
};
$homeownerAppLinkBase = $applicationLink(
    $environment,
    $env('HOMEOWNER_APP_LINK_BASE', 'providentia://login-link/homeowner'),
    'HOMEOWNER_APP_LINK_BASE',
    'providentia',
    $applicationLinkAllowedHosts,
);
$adminAppLinkBase = $applicationLink(
    $environment,
    $env('ADMIN_APP_LINK_BASE', 'providentia-admin://login-link/admin'),
    'ADMIN_APP_LINK_BASE',
    'providentia-admin',
    $applicationLinkAllowedHosts,
);
if (hash_equals($homeownerAppLinkBase, $adminAppLinkBase)) {
    throw new RuntimeException('Homeowner and administrator application-link bases must be distinct.');
}
$corsAllowedOrigins = array_values(array_unique(array_filter(array_map(
    'trim',
    explode(',', $env(
        'CORS_ALLOWED_ORIGINS',
        'http://127.0.0.1:3000,http://localhost:3000,http://127.0.0.1:8081,http://localhost:8081',
    )),
))));
$exposeDevelopmentTokens = filter_var(
    $env('EXPOSE_DEVELOPMENT_TOKENS', '0'),
    FILTER_VALIDATE_BOOL,
);
$metricsEnabled = filter_var($env('METRICS_ENABLED', '0'), FILTER_VALIDATE_BOOL);
$metricsBearerToken = $env('METRICS_BEARER_TOKEN', '');
if ($metricsEnabled && strlen($metricsBearerToken) < 32) {
    throw new RuntimeException('Enabled metrics require METRICS_BEARER_TOKEN with at least 32 characters.');
}
$aiServerProxyEnabled = filter_var($env('AI_SERVER_PROXY_ENABLED', '0'), FILTER_VALIDATE_BOOL);
$aiCredentialKek = $env('AI_CREDENTIAL_KEK', '');
$aiMediaKek = $env('AI_MEDIA_KEK', '');
$catalogImageKek = $env('CATALOG_IMAGE_KEK', '');
$catalogImageKeyVersionValue = $env('CATALOG_IMAGE_KEY_VERSION', '1');
if (
    preg_match('/^[1-9][0-9]*$/', $catalogImageKeyVersionValue) !== 1
    || (int) $catalogImageKeyVersionValue > 2147483647
) {
    throw new RuntimeException('CATALOG_IMAGE_KEY_VERSION must be a positive 32-bit integer.');
}
$catalogImageKeyVersion = (int) $catalogImageKeyVersionValue;
$aiCompatibleEndpoint = rtrim($env('AI_COMPATIBLE_ENDPOINT', ''), '/');
$aiOllamaEndpoint = rtrim($env('AI_OLLAMA_ENDPOINT', ''), '/');
$aiAllowPrivateEndpoints = filter_var(
    $env('AI_ALLOW_PRIVATE_ENDPOINTS', '0'),
    FILTER_VALIDATE_BOOL,
);
// Deliberately separate LAN policy for user-owned profile endpoints: it only
// widens what an Ollama profile endpoint may point at (plain HTTP and
// private/loopback hosts); every other profile endpoint stays HTTPS + public.
$aiAllowPrivateNetworkEndpoints = filter_var(
    $env('AI_ALLOW_PRIVATE_NETWORK_ENDPOINTS', '0'),
    FILTER_VALIDATE_BOOL,
);
$cookieSecure = filter_var(
    $env('AUTH_COOKIE_SECURE', $environment === 'production' ? '1' : '0'),
    FILTER_VALIDATE_BOOL,
);
$bootstrapAdministratorEmails = array_values(array_unique(array_filter(array_map(
    static fn (string $email): string => mb_strtolower(trim($email)),
    explode(',', $env('PLATFORM_BOOTSTRAP_ADMIN_EMAILS', '')),
))));
foreach ($bootstrapAdministratorEmails as $bootstrapAdministratorEmail) {
    if (
        filter_var($bootstrapAdministratorEmail, FILTER_VALIDATE_EMAIL) === false
        || mb_strlen($bootstrapAdministratorEmail) > 254
    ) {
        throw new RuntimeException('PLATFORM_BOOTSTRAP_ADMIN_EMAILS contains an invalid email address.');
    }
}
$onboardingHomeName = trim($env('ONBOARDING_HOME_NAME', 'My home'));
$onboardingHomeLocale = trim($env('ONBOARDING_HOME_LOCALE', 'en-NA'));
$onboardingHomeCurrency = strtoupper(trim($env('ONBOARDING_HOME_CURRENCY', 'NAD')));
$onboardingHomeTimezone = trim($env('ONBOARDING_HOME_TIMEZONE', 'Africa/Windhoek'));
if ($onboardingHomeName === '' || mb_strlen($onboardingHomeName) > 120) {
    throw new RuntimeException('ONBOARDING_HOME_NAME must contain 1 to 120 characters.');
}
if ($onboardingHomeLocale === '' || mb_strlen($onboardingHomeLocale) > 16) {
    throw new RuntimeException('ONBOARDING_HOME_LOCALE must contain 1 to 16 characters.');
}
if (preg_match('/^[A-Z]{3}$/', $onboardingHomeCurrency) !== 1) {
    throw new RuntimeException('ONBOARDING_HOME_CURRENCY must be a three-letter ISO currency code.');
}
if (! in_array($onboardingHomeTimezone, DateTimeZone::listIdentifiers(), true)) {
    throw new RuntimeException('ONBOARDING_HOME_TIMEZONE must be a recognized IANA timezone.');
}
$notificationPayloadKek = $env('NOTIFICATION_PAYLOAD_KEK', '');
$billingEnabled = filter_var($env('BILLING_ENABLED', '0'), FILTER_VALIDATE_BOOL);
$billingAllowPrivateEndpoints = filter_var(
    $env('BILLING_ALLOW_PRIVATE_ENDPOINTS', '0'),
    FILTER_VALIDATE_BOOL,
);
$paypalEnabled = filter_var($env('PAYPAL_ENABLED', '0'), FILTER_VALIDATE_BOOL);
$paypalEnvironment = mb_strtolower($env('PAYPAL_ENVIRONMENT', 'sandbox'));
if (! in_array($paypalEnvironment, ['sandbox', 'live'], true)) {
    throw new RuntimeException('PAYPAL_ENVIRONMENT must be sandbox or live.');
}
$paypalApiBase = $paypalEnvironment === 'live'
    ? 'https://api-m.paypal.com'
    : 'https://api-m.sandbox.paypal.com';
$hostedCardEnabled = filter_var($env('HOSTED_CARD_ENABLED', '0'), FILTER_VALIDATE_BOOL);
$hostedCardApiBase = rtrim($env('HOSTED_CARD_API_BASE', ''), '/');
$hostedCardRedirectHosts = array_values(array_filter(array_map(
    static fn (string $host): string => mb_strtolower(trim($host)),
    explode(',', $env('HOSTED_CARD_REDIRECT_HOSTS', '')),
)));
$dataExportKek = $env('DATA_EXPORT_KEK', '');
if ($notificationPayloadKek === '' && $environment !== 'production') {
    $notificationPayloadKek = base64_encode(hash('sha256', $tokenPepper . ':notification', true));
}
if ($dataExportKek === '' && $environment !== 'production') {
    $dataExportKek = base64_encode(hash('sha256', $tokenPepper . ':data-export', true));
}
if ($aiMediaKek === '' && $environment !== 'production') {
    $aiMediaKek = base64_encode(hash('sha256', $tokenPepper . ':private-media', true));
}
if ($catalogImageKek === '' && $environment !== 'production') {
    $catalogImageKek = base64_encode(hash('sha256', $tokenPepper . ':catalog-images', true));
}
$catalogImagePreviousKeysJson = $env('CATALOG_IMAGE_PREVIOUS_KEYS_JSON', '[]');
try {
    $catalogImagePreviousKeys = json_decode(
        $catalogImagePreviousKeysJson,
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
} catch (JsonException) {
    throw new RuntimeException('CATALOG_IMAGE_PREVIOUS_KEYS_JSON must be valid JSON.');
}
if (! is_array($catalogImagePreviousKeys) || ! array_is_list($catalogImagePreviousKeys)) {
    throw new RuntimeException('CATALOG_IMAGE_PREVIOUS_KEYS_JSON must be a JSON list.');
}
$catalogImageKeyVersions = [$catalogImageKeyVersion => true];
foreach ($catalogImagePreviousKeys as &$catalogImagePreviousKey) {
    if (! is_array($catalogImagePreviousKey)) {
        throw new RuntimeException('Every previous catalog image key must be an object.');
    }
    $keys = array_keys($catalogImagePreviousKey);
    sort($keys);
    $version = $catalogImagePreviousKey['version'] ?? null;
    $kek = $catalogImagePreviousKey['kek'] ?? null;
    $decoded = is_string($kek) ? base64_decode(trim($kek), true) : false;
    if (
        $keys !== ['kek', 'version']
        || ! is_int($version)
        || $version < 1
        || isset($catalogImageKeyVersions[$version])
        || ! is_string($decoded)
        || strlen($decoded) !== 32
    ) {
        throw new RuntimeException(
            'Previous catalog image keys need unique positive versions and 32-byte base64 keys.',
        );
    }
    $catalogImageKeyVersions[$version] = true;
    $catalogImagePreviousKey = ['version' => $version, 'kek' => trim($kek)];
}
unset($catalogImagePreviousKey);

if ($environment === 'production') {
    $placeholderSecrets = [
        'development-only-change-me',
        'development-sync-secret-change-me',
        'providentia-development-token-pepper-change-me',
        'providentia-development-cursor-secret-change-me',
        'replace-with-a-secret-from-your-secret-manager',
        'CHANGE_TO_AT_LEAST_32_RANDOM_BYTES',
        'CHANGE_TO_AN_INDEPENDENT_32_BYTE_SECRET',
    ];
    if (
        strlen($tokenPepper) < 32
        || strlen($cursorSecret) < 32
        || in_array($tokenPepper, $placeholderSecrets, true)
        || in_array($cursorSecret, $placeholderSecrets, true)
        || hash_equals($tokenPepper, $cursorSecret)
    ) {
        throw new RuntimeException(
            'Production requires two independent, non-placeholder authentication and cursor secrets.',
        );
    }
    if ($exposeDevelopmentTokens) {
        throw new RuntimeException('EXPOSE_DEVELOPMENT_TOKENS cannot be enabled in production.');
    }
    if (! $cookieSecure) {
        throw new RuntimeException('AUTH_COOKIE_SECURE must be enabled in production.');
    }
    $mail = parse_url($mailDsn);
    if ($mail === false || ($mail['scheme'] ?? null) !== 'smtps') {
        throw new RuntimeException(
            'Production MAIL_DSN must use smtps:// because this transport does not implement verified STARTTLS.',
        );
    }
    $decodedNotificationKey = base64_decode($notificationPayloadKek, true);
    if (! is_string($decodedNotificationKey) || strlen($decodedNotificationKey) !== 32) {
        throw new RuntimeException(
            'Production NOTIFICATION_PAYLOAD_KEK must contain exactly 32 base64-encoded bytes.',
        );
    }
    $decodedDataExportKey = base64_decode($dataExportKek, true);
    if (! is_string($decodedDataExportKey) || strlen($decodedDataExportKey) !== 32) {
        throw new RuntimeException('Production DATA_EXPORT_KEK must contain exactly 32 base64-encoded bytes.');
    }
    if ($aiServerProxyEnabled) {
        $decodedAiKey = base64_decode($aiCredentialKek, true);
        if (! is_string($decodedAiKey) || strlen($decodedAiKey) !== 32) {
            throw new RuntimeException(
                'Production AI server proxy requires AI_CREDENTIAL_KEK as exactly 32 base64-encoded bytes.',
            );
        }
        if (
            $aiCompatibleEndpoint !== ''
            && ! str_starts_with($aiCompatibleEndpoint, 'https://')
        ) {
            throw new RuntimeException('Production AI-compatible endpoints must use HTTPS.');
        }
    }
    $decodedMediaKey = base64_decode($aiMediaKek, true);
    if (! is_string($decodedMediaKey) || strlen($decodedMediaKey) !== 32) {
        throw new RuntimeException('Production AI_MEDIA_KEK must contain exactly 32 base64-encoded bytes.');
    }
    $decodedCatalogImageKey = base64_decode($catalogImageKek, true);
    if (! is_string($decodedCatalogImageKey) || strlen($decodedCatalogImageKey) !== 32) {
        throw new RuntimeException('Production CATALOG_IMAGE_KEK must contain exactly 32 base64-encoded bytes.');
    }
    if ($billingAllowPrivateEndpoints) {
        throw new RuntimeException('BILLING_ALLOW_PRIVATE_ENDPOINTS cannot be enabled in production.');
    }
    if ($billingEnabled && ! $paypalEnabled && ! $hostedCardEnabled) {
        throw new RuntimeException('Production billing requires at least one enabled checkout provider.');
    }
    if ($paypalEnabled) {
        if (
            trim($env('PAYPAL_CLIENT_ID', '')) === ''
            || trim($env('PAYPAL_CLIENT_SECRET', '')) === ''
            || preg_match('/^[A-Za-z0-9]{1,50}$/', $env('PAYPAL_WEBHOOK_ID', '')) !== 1
        ) {
            throw new RuntimeException(
                'Enabled PayPal billing requires client ID, client secret, and webhook ID.',
            );
        }
    }
    if ($hostedCardEnabled) {
        if (
            ! str_starts_with($hostedCardApiBase, 'https://')
            || $hostedCardRedirectHosts === []
            || trim($env('HOSTED_CARD_API_KEY', '')) === ''
            || strlen($env('HOSTED_CARD_WEBHOOK_SECRET', '')) < 32
        ) {
            throw new RuntimeException(
                'Enabled hosted-card billing requires HTTPS, an API key, and a 32-byte webhook secret.',
            );
        }
    }
}

return [
    'app' => [
        'environment' => $environment,
        'version' => $env('APP_VERSION', '0.1.0'),
        'debug' => filter_var($env('APP_DEBUG', '0'), FILTER_VALIDATE_BOOL),
    ],
    'database' => [
        'url' => $env('DATABASE_URL', 'sqlite:///var/providentia.sqlite'),
        'preferred_production_driver' => 'mysql',
    ],
    'identity' => [
        'access_ttl_seconds' => max(300, (int) $env('AUTH_ACCESS_TTL_SECONDS', '900')),
        'refresh_ttl_seconds' => max(3600, (int) $env('AUTH_REFRESH_TTL_SECONDS', '2592000')),
        // 0 means no idle ceiling: a trusted installation stays signed in until
        // sign-out, device/session revocation, account disablement, rotation
        // failure, or a named security invalidation. A positive value is a
        // deliberate finite ceiling with a 15-minute floor.
        'web_idle_ttl_seconds' => (static fn (int $seconds): int => $seconds <= 0
            ? 0
            : max(900, $seconds))((int) $env('AUTH_WEB_IDLE_TTL_SECONDS', '0')),
        'native_idle_ttl_seconds' => (static fn (int $seconds): int => $seconds <= 0
            ? 0
            : max(900, $seconds))((int) $env('AUTH_NATIVE_IDLE_TTL_SECONDS', '0')),
        'login_link_ttl_seconds' => min(3600, max(300, (int) $env(
            'AUTH_LOGIN_LINK_TTL_SECONDS',
            '900',
        ))),
        'login_link_exchange_ttl_seconds' => min(600, max(30, (int) $env(
            'AUTH_LOGIN_LINK_EXCHANGE_TTL_SECONDS',
            '120',
        ))),
        'login_link_poll_interval_seconds' => min(30, max(1, (int) $env(
            'AUTH_LOGIN_LINK_POLL_INTERVAL_SECONDS',
            '3',
        ))),
        'login_link_retention_days' => min(365, max(1, (int) $env(
            'AUTH_LOGIN_LINK_RETENTION_DAYS',
            '30',
        ))),
        'rate_limit_retention_days' => min(30, max(1, (int) $env(
            'AUTH_RATE_LIMIT_RETENTION_DAYS',
            '2',
        ))),
        'bootstrap_administrator_emails' => $bootstrapAdministratorEmails,
        'application_links' => [
            'homeowner' => $homeownerAppLinkBase,
            'admin' => $adminAppLinkBase,
        ],
        'onboarding_home' => [
            'name' => $onboardingHomeName,
            'locale' => $onboardingHomeLocale,
            'currency' => $onboardingHomeCurrency,
            'timezone' => $onboardingHomeTimezone,
        ],
        'token_pepper' => $tokenPepper,
        'expose_development_tokens' => $exposeDevelopmentTokens,
        'cookie_secure' => $cookieSecure,
    ],
    'mail' => [
        'dsn' => $mailDsn,
        'from' => $env('MAIL_FROM', 'no-reply@providentia.local'),
        'notification_payload_kek' => $notificationPayloadKek,
        'notification_key_version' => max(1, (int) $env('NOTIFICATION_KEY_VERSION', '1')),
        'batch_size' => max(1, min(500, (int) $env('NOTIFICATION_BATCH_SIZE', '100'))),
        'max_attempts' => max(1, min(50, (int) $env('NOTIFICATION_MAX_ATTEMPTS', '10'))),
    ],
    'synchronization' => [
        'cursor_secret' => $cursorSecret,
        'cursor_ttl_seconds' => max(3600, (int) $env('SYNC_CURSOR_TTL_SECONDS', '2592000')),
        'max_batch_operations' => 100,
        'max_payload_bytes' => 65536,
        'page_size' => 250,
        'offline_window_days' => max(1, (int) $env('SYNC_OFFLINE_WINDOW_DAYS', '90')),
        'tombstone_retention_days' => max(1, (int) $env('SYNC_TOMBSTONE_RETENTION_DAYS', '120')),
    ],
    'ai' => [
        'server_proxy_enabled' => $aiServerProxyEnabled,
        'credential_kek' => $aiCredentialKek,
        'credential_key_version' => max(1, (int) $env('AI_CREDENTIAL_KEY_VERSION', '1')),
        'orchestration_max_attempts' => max(2, min(8, (int) $env('AI_ORCHESTRATION_MAX_ATTEMPTS', '8'))),
        'openai_endpoint' => 'https://api.openai.com/v1/responses',
        'anthropic_endpoint' => 'https://api.anthropic.com/v1/messages',
        'gemini_endpoint_template' =>
            'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
        'xai_endpoint' => 'https://api.x.ai/v1/chat/completions',
        'compatible_endpoint' => $aiCompatibleEndpoint === ''
            ? ''
            : $aiCompatibleEndpoint . '/v1/chat/completions',
        'ollama_endpoint' => $aiOllamaEndpoint === '' ? '' : $aiOllamaEndpoint . '/api/chat',
        'allow_private_endpoints' => $aiAllowPrivateEndpoints,
        'allow_private_network_endpoints' => $aiAllowPrivateNetworkEndpoints,
        'max_image_bytes' => max(
            1048576,
            min(16777216, (int) $env('AI_MAX_IMAGE_BYTES', '8388608')),
        ),
        'max_images' => max(1, min(8, (int) $env('AI_MAX_IMAGES', '8'))),
        'media_root' => $env('AI_MEDIA_ROOT', 'var/private-media'),
        'media_kek' => $aiMediaKek,
        'media_key_version' => max(1, (int) $env('AI_MEDIA_KEY_VERSION', '1')),
        'media_default_quota_bytes' => max(
            1048576,
            (int) $env('AI_MEDIA_DEFAULT_QUOTA_BYTES', '2147483648'),
        ),
        'media_transient_ttl_seconds' => max(
            3600,
            (int) $env('AI_MEDIA_TRANSIENT_TTL_SECONDS', '86400'),
        ),
        'media_max_export_bytes' => max(
            1048576,
            (int) $env('AI_MEDIA_MAX_EXPORT_BYTES', '67108864'),
        ),
        'max_video_bytes' => max(
            1048576,
            min(536870912, (int) $env('AI_MAX_VIDEO_BYTES', '134217728')),
        ),
        'max_video_duration_seconds' => max(
            1,
            min(3600, (int) $env('AI_MAX_VIDEO_DURATION_SECONDS', '300')),
        ),
        'max_video_frames' => max(1, min(60, (int) $env('AI_MAX_VIDEO_FRAMES', '12'))),
        'video_processing_timeout_seconds' => max(
            30,
            min(900, (int) $env('AI_VIDEO_PROCESS_TIMEOUT_SECONDS', '180')),
        ),
        'ffprobe_binary' => $env('AI_FFPROBE_BINARY', '/usr/bin/ffprobe'),
        'ffmpeg_binary' => $env('AI_FFMPEG_BINARY', '/usr/bin/ffmpeg'),
    ],
    'catalog_contribution_images' => [
        'kek' => $catalogImageKek,
        'key_version' => $catalogImageKeyVersion,
        'previous_keys' => $catalogImagePreviousKeys,
        'max_upload_bytes' => 5242880,
        'max_dimension' => 4096,
        'max_pixels' => 16777216,
    ],
    'billing' => [
        'enabled' => $billingEnabled,
        'allow_private_endpoints' => $billingAllowPrivateEndpoints,
        'http_timeout_seconds' => max(
            2,
            min(30, (int) $env('BILLING_HTTP_TIMEOUT_SECONDS', '10')),
        ),
        'maximum_response_bytes' => max(
            65536,
            min(4194304, (int) $env('BILLING_MAXIMUM_RESPONSE_BYTES', '1048576')),
        ),
        'providers' => [
            'paypal' => [
                'enabled' => $paypalEnabled,
                'api_base' => $paypalApiBase,
                'client_id' => $env('PAYPAL_CLIENT_ID', ''),
                'client_secret' => $env('PAYPAL_CLIENT_SECRET', ''),
                'webhook_id' => $env('PAYPAL_WEBHOOK_ID', ''),
            ],
            'hosted_card' => [
                'enabled' => $hostedCardEnabled,
                'api_base' => $hostedCardApiBase,
                'checkout_path' => $env(
                    'HOSTED_CARD_CHECKOUT_PATH',
                    '/v1/checkout/sessions',
                ),
                'allowed_redirect_hosts' => $hostedCardRedirectHosts,
                'api_key' => $env('HOSTED_CARD_API_KEY', ''),
                'webhook_secret' => $env('HOSTED_CARD_WEBHOOK_SECRET', ''),
                'webhook_signature_header' => $env(
                    'HOSTED_CARD_WEBHOOK_SIGNATURE_HEADER',
                    'X-Webhook-Signature',
                ),
                'webhook_timestamp_header' => $env(
                    'HOSTED_CARD_WEBHOOK_TIMESTAMP_HEADER',
                    'X-Webhook-Timestamp',
                ),
                'webhook_tolerance_seconds' => max(
                    30,
                    min(900, (int) $env('HOSTED_CARD_WEBHOOK_TOLERANCE_SECONDS', '300')),
                ),
            ],
        ],
    ],
    'http' => [
        'allowed_origins' => $corsAllowedOrigins,
    ],
    'metrics' => [
        'enabled' => $metricsEnabled,
        'credential_hash' => hash('sha256', $metricsBearerToken),
    ],
    'queue' => [
        'dsn' => $env('QUEUE_DSN', 'redis+phpredis://127.0.0.1:6379'),
        'name' => $env('QUEUE_NAME', 'providentia.default'),
        'required' => filter_var($env('QUEUE_REQUIRED', '0'), FILTER_VALIDATE_BOOL),
        'outbox_batch_size' => max(1, (int) $env('OUTBOX_BATCH_SIZE', '100')),
        'outbox_max_attempts' => max(1, (int) $env('OUTBOX_MAX_ATTEMPTS', '10')),
    ],
    'data_governance' => [
        'artifact_root' => $env('DATA_EXPORT_ROOT', 'var/data-exports'),
        'artifact_kek' => $dataExportKek,
        'page_size' => max(25, min(1000, (int) $env('DATA_EXPORT_PAGE_SIZE', '250'))),
    ],
];
