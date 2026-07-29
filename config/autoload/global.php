<?php

declare(strict_types=1);

$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
};

return [
    'app' => [
        'environment' => $env('APP_ENV', 'production'),
        'version' => $env('APP_VERSION', '0.1.0'),
        'debug' => filter_var($env('APP_DEBUG', '0'), FILTER_VALIDATE_BOOL),
    ],
    'database' => [
        'url' => $env('DATABASE_URL', 'sqlite:///var/providentia.sqlite'),
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
