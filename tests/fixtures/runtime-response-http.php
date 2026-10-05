<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Providentia\Access\Application\AccessStore;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\Geography\Application\CountryService;
use Providentia\Identity\Application\AuthenticationService;

// Reuse the guarded, empty-database-only fixture and real session issuance.
require __DIR__ . '/step2-http.php';
$output = $argv[1];
$json = file_get_contents($output);
if ($json === false) {
    throw new RuntimeException('The ephemeral fixture is unavailable.');
}
$fixture = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$container = require dirname(__DIR__, 2) . '/config/container.php';
/** @var Connection $db */
$db = $container->get(Connection::class);
/** @var AuthenticationService $authentication */
$authentication = $container->get(AuthenticationService::class);
/** @var AccessStore $access */
$access = $container->get(AccessStore::class);
$alice = $authentication->authenticate($fixture['sessions']['alice']['accessToken']);
if (! $access->assign('admin', $alice->userId, FeatureCatalog::SYSTEM_OWNER, 0)) {
    throw new RuntimeException('Unable to seed synthetic operator authorization.');
}
$db->insert('system_owner_bootstrap', [
    'singleton_id' => 1,
    'email' => 'alice@step2.example.test',
    'user_id' => $alice->userId,
    'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
]);
$fixture['userId'] = $alice->userId;
$fixture['policy'] = $container->get(CountryService::class)->registrationPolicy('NA');
if (file_put_contents($output, json_encode($fixture, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Unable to update the ephemeral fixture.');
}
