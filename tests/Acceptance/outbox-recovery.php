<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 3 || ! in_array($arguments[1], ['prepare-redelivery', 'verify'], true)) {
    fwrite(STDERR, "Usage: php tests/Acceptance/outbox-recovery.php <prepare-redelivery|verify> <proof-name>\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$container = require dirname(__DIR__, 2) . '/config/container.php';
/** @var Connection $connection */
$connection = $container->get(Connection::class);
$needle = '%"label":"' . $arguments[2] . '"%';

if ($arguments[1] === 'prepare-redelivery') {
    $updated = $connection->executeStatement(
        "UPDATE outbox_messages
         SET status = 'pending', published_at = NULL, available_at = :available
         WHERE payload LIKE :needle",
        ['available' => '2000-01-01 00:00:00', 'needle' => $needle],
    );
    if ($updated !== 1) {
        fwrite(STDERR, sprintf("Expected one proof outbox row; updated %d.\n", $updated));
        exit(1);
    }
    fwrite(STDOUT, "Prepared one committed outbox row for duplicate delivery.\n");
    exit(0);
}

$published = (int) $connection->fetchOne(
    "SELECT COUNT(*) FROM outbox_messages WHERE payload LIKE :needle AND status = 'published'",
    ['needle' => $needle],
);
$processed = (int) $connection->fetchOne(
    "SELECT COUNT(*) FROM outbox_messages o
     INNER JOIN async_processed_messages p ON p.message_id = o.id
     WHERE o.payload LIKE :needle",
    ['needle' => $needle],
);
$failed = (int) $connection->fetchOne('SELECT COUNT(*) FROM async_failed_messages');

if ($published !== 1 || $processed !== 1 || $failed !== 0) {
    fwrite(STDERR, sprintf(
        "Recovery invariant failed: published=%d processed=%d failed=%d.\n",
        $published,
        $processed,
        $failed,
    ));
    exit(1);
}

fwrite(STDOUT, "Recovery invariant passed: one publication, one observable processing, no failure.\n");
