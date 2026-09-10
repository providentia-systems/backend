<?php

declare(strict_types=1);

use Providentia\Access\Application\AccessStore;
use Providentia\Access\Domain\FeatureCatalog;
use Psr\Container\ContainerInterface;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

if (count($argv) !== 3) {
    throw new RuntimeException('Expected a group ID and user ID.');
}

/** @var ContainerInterface $container */
$container = require $root . '/config/container.php';
/** @var AccessStore $store */
$store = $container->get(AccessStore::class);
$group = $store->group(FeatureCatalog::STARTER_ACCOUNT)
    ?? throw new RuntimeException('Starter account group is unavailable.');
$group['id'] = $argv[1];
$group['name'] = 'HTTP smoke preassigned account';
$group['protected'] = false;

if (
    !$store->saveGroup($group, 0)
    || !$store->assign(FeatureCatalog::ACCOUNT, $argv[2], $argv[1], 0)
    || !$store->assign(FeatureCatalog::ACCOUNT, $argv[2], $argv[1], 1)
) {
    throw new RuntimeException('Could not arrange the preassigned account group.');
}

$assignment = $store->assignment(FeatureCatalog::ACCOUNT, $argv[2]);
if (
    ($assignment['groupId'] ?? null) !== $argv[1]
    || ($assignment['revision'] ?? null) !== 2
) {
    throw new RuntimeException('The preassigned account group was not revisioned.');
}
