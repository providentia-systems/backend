<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Providentia\SharedKernel\Application\Async\AsyncMessageBus;
use Providentia\SharedKernel\Infrastructure\Queue\FailedSynchronizationReplay;

$usage = "Usage: php tool/replay-failed-synchronization.php [--limit=100] [--apply]\n"
    . "Default: read-only validation of retained v2 notifications rejected by the old consumer.\n"
    . "--apply publishes original notification IDs; it never repeats household mutations.\n";
$apply = false;
$limit = 100;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
    if ($argument === '--help') {
        fwrite(STDOUT, $usage);
        exit(0);
    }
    if ($argument === '--apply') {
        $apply = true;
        continue;
    }
    if (preg_match('/^--limit=([1-9][0-9]{0,3})$/D', $argument, $match) === 1 && (int) $match[1] <= 1000) {
        $limit = (int) $match[1];
        continue;
    }
    fwrite(STDERR, $usage);
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';
try {
    $container = require dirname(__DIR__) . '/config/container.php';
    /** @var Connection $connection */
    $connection = $container->get(Connection::class);
    /** @var AsyncMessageBus|null $bus */
    $bus = $apply ? $container->get(AsyncMessageBus::class) : null;
    $result = (new FailedSynchronizationReplay($connection, $bus))->replay($limit, $apply);
    // IDs and fixed statuses only: do not print household payloads, credentials,
    // transport exceptions or connection strings into operator evidence.
    fwrite(STDOUT, json_encode([
        'mode' => $apply ? 'apply' : 'dry-run',
        ...$result,
        'resolution' => 'Failure records are resolved only after successful consumer processing.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit($result['blocked'] === 0 ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "Recovery could not complete. Check the configured database and message broker.\n");
    exit(1);
}
