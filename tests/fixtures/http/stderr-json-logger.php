<?php

declare(strict_types=1);

use Providentia\SharedKernel\Infrastructure\Logging\StderrJsonLogger;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$logger = new StderrJsonLogger();
$logger->error('Development HTTP logger smoke.', [
    'request_id' => 'development-http-smoke',
    'authorization' => 'Bearer must-not-leak',
]);

header('Content-Type: application/json');
echo '{"status":"ok"}';
