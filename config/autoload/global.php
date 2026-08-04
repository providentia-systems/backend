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
$publicBaseUrl = rtrim($env('PUBLIC_BASE_URL', 'http://127.0.0.1:8080'), '/');
$exposeDevelopmentTokens = filter_var(
    $env('EXPOSE_DEVELOPMENT_TOKENS', '0'),
    FILTER_VALIDATE_BOOL,
);
$aiServerProxyEnabled = filter_var($env('AI_SERVER_PROXY_ENABLED', '0'), FILTER_VALIDATE_BOOL);
$aiCredentialKek = $env('AI_CREDENTIAL_KEK', '');
$aiCompatibleEndpoint = rtrim($env('AI_COMPATIBLE_ENDPOINT', ''), '/');
$aiOllamaEndpoint = rtrim($env('AI_OLLAMA_ENDPOINT', ''), '/');
$aiAllowPrivateEndpoints = filter_var(
    $env('AI_ALLOW_PRIVATE_ENDPOINTS', '0'),
    FILTER_VALIDATE_BOOL,
);
$passwordLoginEnabled = filter_var($env('AUTH_PASSWORD_LOGIN_ENABLED', '0'), FILTER_VALIDATE_BOOL);
$notificationPayloadKek = $env('NOTIFICATION_PAYLOAD_KEK', '');
if ($notificationPayloadKek === '' && $environment !== 'production') {
    $notificationPayloadKek = base64_encode(hash('sha256', $tokenPepper . ':notification', true));
}

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
    $mail = parse_url($mailDsn);
    if ($mail === false || ($mail['scheme'] ?? null) !== 'smtps') {
        throw new RuntimeException(
            'Production MAIL_DSN must use smtps:// because this transport does not implement verified STARTTLS.',
        );
    }
    if (! str_starts_with($publicBaseUrl, 'https://')) {
        throw new RuntimeException('Production PUBLIC_BASE_URL must use HTTPS.');
    }
    $decodedNotificationKey = base64_decode($notificationPayloadKek, true);
    if (! is_string($decodedNotificationKey) || strlen($decodedNotificationKey) !== 32) {
        throw new RuntimeException(
            'Production NOTIFICATION_PAYLOAD_KEK must contain exactly 32 base64-encoded bytes.',
        );
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
        'token_pepper' => $tokenPepper,
        'expose_development_tokens' => $exposeDevelopmentTokens,
        'password_login_enabled' => $passwordLoginEnabled,
    ],
    'mail' => [
        'dsn' => $mailDsn,
        'from' => $env('MAIL_FROM', 'no-reply@providentia.local'),
        'public_base_url' => $publicBaseUrl,
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
    ],
    'ai' => [
        'server_proxy_enabled' => $aiServerProxyEnabled,
        'credential_kek' => $aiCredentialKek,
        'credential_key_version' => max(1, (int) $env('AI_CREDENTIAL_KEY_VERSION', '1')),
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
        'max_image_bytes' => max(
            1048576,
            min(16777216, (int) $env('AI_MAX_IMAGE_BYTES', '8388608')),
        ),
    ],
    'http' => [
        'allowed_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', $env(
                'CORS_ALLOWED_ORIGINS',
                'http://127.0.0.1:3000,http://localhost:3000,http://127.0.0.1:8081,http://localhost:8081',
            )),
        ))),
    ],
    'queue' => [
        'dsn' => $env('QUEUE_DSN', 'redis+phpredis://127.0.0.1:6379'),
        'name' => $env('QUEUE_NAME', 'providentia.default'),
        'required' => filter_var($env('QUEUE_REQUIRED', '0'), FILTER_VALIDATE_BOOL),
        'outbox_batch_size' => max(1, (int) $env('OUTBOX_BATCH_SIZE', '100')),
        'outbox_max_attempts' => max(1, (int) $env('OUTBOX_MAX_ATTEMPTS', '10')),
    ],
    'templates' => [
        'paths' => [
            'public-site' => ['templates/public-site'],
            'error' => ['templates/error'],
        ],
    ],
];
