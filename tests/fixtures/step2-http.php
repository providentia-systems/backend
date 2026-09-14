<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Providentia\Access\Application\AccessStore;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\Catalog\Application\CatalogStore;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\IdentityStore;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

// Isolated, migrated CI databases only. Never resets or touches an existing account.
if (getenv('APP_ENV') !== 'test' || getenv('PROVIDENTIA_STEP2_CONFORMANCE') !== '1') {
    throw new RuntimeException('Explicit isolated Step 2 test environment required.');
}
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$container = require $root . '/config/container.php';
/** @var Connection $db */
$db = $container->get(Connection::class);
if ((int) $db->fetchOne('SELECT COUNT(*) FROM users') !== 0) {
    throw new RuntimeException('Refusing to seed a nonempty account database.');
}
/** @var IdentityStore $identity */
$identity = $container->get(IdentityStore::class);
/** @var HomeStore $homes */
$homes = $container->get(HomeStore::class);
/** @var AccessStore $access */
$access = $container->get(AccessStore::class);
/** @var UuidGenerator $ids */
$ids = $container->get(UuidGenerator::class);
/** @var CatalogStore $catalog */
$catalog = $container->get(CatalogStore::class);
/** @var AuthenticationService $authentication */
$authentication = $container->get(AuthenticationService::class);
/** @var TransactionManager $transactions */
$transactions = $container->get(TransactionManager::class);
$at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$fixture = $transactions->transactional(static function () use (
    $db,
    $identity,
    $homes,
    $access,
    $ids,
    $catalog,
    $authentication,
    $at,
): array {
    foreach (FeatureCatalog::defaults() as $group) {
        if ($access->group((string) $group['id']) === null && !$access->saveGroup($group, 0)) {
            throw new RuntimeException('Unable to seed the default authorization groups.');
        }
    }
    $alice = $ids->generate();
    $bob = $ids->generate();
    $home = $ids->generate();
    $other = $ids->generate();
    foreach ([$alice => 'alice', $bob => 'bob'] as $id => $name) {
        $identity->createUser($id, $name . '@step2.example.test', $name, 'en', 'UTC', $at);
        $identity->markEmailVerified($id, $at);
        if (!$access->assign('account', $id, FeatureCatalog::STARTER_ACCOUNT, 0)) {
            throw new RuntimeException('Unable to seed account authorization.');
        }
    }
    $homes->createHome($home, $alice, 'Synthetic conformance home', 'en', 'NAD', 'UTC', $at);
    $homes->createHome($other, $bob, 'Synthetic isolated home', 'en', 'NAD', 'UTC', $at);
    foreach ([$home, $other] as $homeId) {
        if (!$access->assign('home', $homeId, FeatureCatalog::STARTER_HOME, 0)) {
            throw new RuntimeException('Unable to seed home authorization.');
        }
    }
    $db->insert('home_memberships', [
        'home_id' => $home,
        'user_id' => $bob,
        'role' => 'manager',
        'status' => 'active',
        'revision' => 1,
        'joined_at' => $at->format('Y-m-d H:i:s'),
        'left_at' => null,
        'updated_at' => $at->format('Y-m-d H:i:s'),
    ]);
    $items = [];
    foreach (['name', 'product', 'pack', 'barcode'] as $kind) {
        foreach ([1, 2] as $size) {
            $items[] = [
                'category' => 'Step 2 synthetic category',
                'product' => 'Step 2 ' . $kind . ' family',
                'brand' => '',
                'sourceId' => 'step2-' . $kind . '-' . $size,
                'packSize' => $size . ' kg',
            ];
        }
    }
    $catalog->importSeed(['items' => $items, 'aliases' => [], 'identityRules' => []], $at);
    $families = [];
    foreach (['name', 'product', 'pack', 'barcode'] as $kind) {
        $row = $db->fetchAssociative(
            'SELECT p.id AS productId, p.canonical_name AS name, pk.id AS packId
             FROM products p INNER JOIN product_packs pk ON pk.product_id = p.id WHERE pk.source_key = ?',
            ['step2-' . $kind . '-2'],
        );
        if ($row === false) {
            throw new RuntimeException('Synthetic product seed is incomplete.');
        }
        $families[$kind] = $row;
    }
    $db->insert('product_barcodes', [
        'id' => $ids->generate(),
        'pack_id' => $families['barcode']['packId'],
        'barcode' => 'STEP2-EXACT-PACK',
        'barcode_type' => 'internal',
        'verification_status' => 'verified',
        'status' => 'published',
        'revision' => 1,
        'created_at' => $at->format('Y-m-d H:i:s'),
        'updated_at' => $at->format('Y-m-d H:i:s'),
    ]);
    $sessions = [];
    foreach (['alice' => $alice, 'bob' => $bob] as $name => $id) {
        // Only session issuance is seeded. Every tested request uses the real
        // bearer middleware, authorization, services, database and serializers.
        $sessions[$name] = $authentication->issueVerifiedSession(
            $id,
            $ids->generate(),
            'Synthetic conformance device',
            'linux',
            'native',
            2592000,
            $home,
        );
    }

    return ['homeId' => $home, 'otherHomeId' => $other, 'catalog' => $families, 'sessions' => $sessions];
});
$output = $argv[1] ?? '';
if ($output === '' || file_put_contents($output, json_encode($fixture, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('An ephemeral fixture output path is required.');
}
chmod($output, 0600);
