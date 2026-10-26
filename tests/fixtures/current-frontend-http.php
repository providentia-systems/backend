<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Providentia\Purchasing\Application\PurchasingStore;
use Providentia\SharedKernel\Application\UuidGenerator;

// The shared fixture rejects nonempty accounts and issues real device sessions.
require __DIR__ . '/runtime-response-http.php';
$output = $_SERVER['argv'][1];
$fixture = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
$container = require dirname(__DIR__, 2) . '/config/container.php';
/** @var PurchasingStore $purchases */
$purchases = $container->get(PurchasingStore::class);
/** @var UuidGenerator $ids */
$ids = $container->get(UuidGenerator::class);
$at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$store = $ids->generate();
$receipt = $ids->generate();
$purchases->createStore(
    $store,
    $fixture['homeId'],
    'Synthetic completeness store',
    'synthetic completeness store',
    '',
    $at,
);
$purchases->createReceipt(
    $receipt,
    $fixture['homeId'],
    $store,
    $at->format('Y-m-d'),
    'NAD',
    null,
    'manual',
    null,
    '',
    $fixture['userId'],
    $at,
);
// Deliberately model legacy/imported unknown pricing. Ordinary capture still
// requires prices; a compatibility fixture must not weaken that application rule.
if (
    ! $purchases->addReceiptLine(
        $ids->generate(),
        $fixture['homeId'],
        $receipt,
        1,
        1,
        'Synthetic legacy unknown price',
        '1',
        null,
        null,
        null,
        $at,
    )
) {
    throw new RuntimeException('Unable to seed synthetic legacy amount evidence.');
}
/** @var Connection $db */
$db = $container->get(Connection::class);
$db->update(
    'receipts',
    ['status' => 'committed', 'committed_at' => $at->format('Y-m-d H:i:s')],
    ['id' => $receipt],
);
$db->update('receipt_lines', ['approval_status' => 'unresolved'], ['receipt_id' => $receipt]);
$fixture['compatibilityStoreId'] = $store;
if (file_put_contents($output, json_encode($fixture, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Unable to save synthetic compatibility identifiers.');
}
