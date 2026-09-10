<?php

declare(strict_types=1);

use Providentia\Access\Application\AccessStore;
use Providentia\Access\Domain\FeatureCatalog;
use Psr\Container\ContainerInterface;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (
    !is_array($arguments)
    || count($arguments) !== 3
    || !isset($arguments[1], $arguments[2])
    || !is_string($arguments[1])
    || !is_string($arguments[2])
) {
    throw new RuntimeException('Expected a group ID and user ID.');
}

$groupId = $arguments[1];
$userId = $arguments[2];

/** @var ContainerInterface $container */
$container = require $root . '/config/container.php';
/** @var AccessStore $store */
$store = $container->get(AccessStore::class);
$group = $store->group(FeatureCatalog::STARTER_ACCOUNT)
    ?? throw new RuntimeException('Starter account group is unavailable.');
$group['id'] = $groupId;
$group['name'] = 'HTTP smoke preassigned account';
$group['protected'] = false;

if (
    !$store->saveGroup($group, 0)
    || !$store->assign(FeatureCatalog::ACCOUNT, $userId, $groupId, 0)
    || !$store->assign(FeatureCatalog::ACCOUNT, $userId, $groupId, 1)
) {
    throw new RuntimeException('Could not arrange the preassigned account group.');
}

$assignment = $store->assignment(FeatureCatalog::ACCOUNT, $userId);
if (
    ($assignment['groupId'] ?? null) !== $groupId
    || ($assignment['revision'] ?? null) !== 2
) {
    throw new RuntimeException('The preassigned account group was not revisioned.');
}
