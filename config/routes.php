<?php

declare(strict_types=1);

use Mezzio\Application;
use Providentia\Catalog\Http\CatalogSearchHandler;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Identity\Http\AuthenticationRateLimitMiddleware;
use Providentia\PublicSite\Http\HomePageHandler;
use Providentia\Reporting\Http\DashboardHandler;
use Providentia\SharedKernel\Http\Health\LivenessHandler;
use Providentia\SharedKernel\Http\Health\ReadinessHandler;
use Providentia\SharedKernel\Http\MetricsHandler;
use Providentia\SharedKernel\Http\SystemInfoHandler;

return static function (Application $app): void {
    $app->get('/', HomePageHandler::class, 'public.home');
    $app->get('/health/live', LivenessHandler::class, 'health.live');
    $app->get('/health/ready', ReadinessHandler::class, 'health.ready');
    $app->get('/api/v1/system/info', SystemInfoHandler::class, 'api.system.info');
    $app->get('/metrics', MetricsHandler::class, 'metrics');

    $app->post(
        '/api/v1/auth/register',
        [AuthenticationRateLimitMiddleware::class, 'identity.register'],
        'api.auth.register',
    );
    $app->post('/api/v1/auth/verify-email', 'identity.verify', 'api.auth.verify-email');
    $app->post(
        '/api/v1/auth/verify-email/resend',
        [AuthenticationRateLimitMiddleware::class, 'identity.resend-verification'],
        'api.auth.verify-email.resend',
    );
    $app->post(
        '/api/v1/auth/login',
        [AuthenticationRateLimitMiddleware::class, 'identity.login'],
        'api.auth.login',
    );
    $app->post('/api/v1/auth/refresh', 'identity.refresh', 'api.auth.refresh');
    $app->post(
        '/api/v1/auth/password-reset/request',
        [AuthenticationRateLimitMiddleware::class, 'identity.request-reset'],
        'api.auth.password-reset.request',
    );
    $app->post('/api/v1/auth/password-reset/complete', 'identity.reset', 'api.auth.password-reset.complete');
    $app->get(
        '/api/v1/auth/sessions',
        [BearerAuthenticationMiddleware::class, 'identity.sessions'],
        'api.auth.sessions',
    );
    $app->delete(
        '/api/v1/auth/sessions/{sessionId}',
        [BearerAuthenticationMiddleware::class, 'identity.revoke-session'],
        'api.auth.sessions.revoke',
    );
    $app->post(
        '/api/v1/homes/{homeId}/ownership-transfer',
        [BearerAuthenticationMiddleware::class, 'home.transfer-ownership'],
        'api.homes.transfer-ownership',
    );
    $app->post(
        '/api/v1/auth/logout',
        [BearerAuthenticationMiddleware::class, 'identity.logout'],
        'api.auth.logout',
    );

    $app->post('/api/v1/homes', [BearerAuthenticationMiddleware::class, 'home.create'], 'api.homes.create');
    $app->get('/api/v1/homes', [BearerAuthenticationMiddleware::class, 'home.list'], 'api.homes.list');
    $app->post(
        '/api/v1/home-invitations/accept',
        [BearerAuthenticationMiddleware::class, 'home.accept-invitation'],
        'api.home-invitations.accept',
    );
    $app->get(
        '/api/v1/homes/{homeId}',
        [BearerAuthenticationMiddleware::class, 'home.get'],
        'api.homes.get',
    );
    $app->post(
        '/api/v1/homes/{homeId}/switch',
        [BearerAuthenticationMiddleware::class, 'home.switch'],
        'api.homes.switch',
    );
    $app->get(
        '/api/v1/homes/{homeId}/memberships',
        [BearerAuthenticationMiddleware::class, 'home.memberships'],
        'api.home-memberships.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/invitations',
        [BearerAuthenticationMiddleware::class, 'home.invite'],
        'api.home-invitations.create',
    );
    $app->patch(
        '/api/v1/homes/{homeId}/memberships/{userId}',
        [BearerAuthenticationMiddleware::class, 'home.change-role'],
        'api.home-memberships.change-role',
    );
    $app->delete(
        '/api/v1/homes/{homeId}/memberships/me',
        [BearerAuthenticationMiddleware::class, 'home.leave'],
        'api.home-memberships.leave',
    );

    $app->get('/api/v1/catalog/products', CatalogSearchHandler::class, 'api.catalog.products.search');
    $app->get(
        '/api/v1/homes/{homeId}/dashboard',
        [BearerAuthenticationMiddleware::class, DashboardHandler::class],
        'api.dashboard',
    );
    $app->get(
        '/api/v1/homes/{homeId}/locations',
        [BearerAuthenticationMiddleware::class, 'inventory.locations.list'],
        'api.inventory.locations.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/locations',
        [BearerAuthenticationMiddleware::class, 'inventory.locations.create'],
        'api.inventory.locations.create',
    );
    $app->get(
        '/api/v1/homes/{homeId}/products',
        [BearerAuthenticationMiddleware::class, 'inventory.items.list'],
        'api.inventory.items.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/products',
        [BearerAuthenticationMiddleware::class, 'inventory.items.create'],
        'api.inventory.items.create',
    );
    $app->get(
        '/api/v1/homes/{homeId}/stock',
        [BearerAuthenticationMiddleware::class, 'inventory.stock.list'],
        'api.inventory.stock.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/stock-adjustments',
        [BearerAuthenticationMiddleware::class, 'inventory.adjustments.create'],
        'api.inventory.adjustments.create',
    );
    $app->get(
        '/api/v1/homes/{homeId}/stock-movements',
        [BearerAuthenticationMiddleware::class, 'inventory.movements.list'],
        'api.inventory.movements.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/inventory-balances/rebuild',
        [BearerAuthenticationMiddleware::class, 'inventory.balances.rebuild'],
        'api.inventory.balances.rebuild',
    );
    $app->get(
        '/api/v1/homes/{homeId}/stock-count-sessions',
        [BearerAuthenticationMiddleware::class, 'inventory.counts.list'],
        'api.inventory.counts.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/stock-count-sessions',
        [BearerAuthenticationMiddleware::class, 'inventory.counts.create'],
        'api.inventory.counts.create',
    );
    $app->get(
        '/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}',
        [BearerAuthenticationMiddleware::class, 'inventory.counts.get'],
        'api.inventory.counts.get',
    );
    $app->put(
        '/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}/lines/{lineId}',
        [BearerAuthenticationMiddleware::class, 'inventory.counts.line'],
        'api.inventory.counts.lines.put',
    );
    $app->post(
        '/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}/close',
        [BearerAuthenticationMiddleware::class, 'inventory.counts.close'],
        'api.inventory.counts.close',
    );
    $app->get(
        '/api/v1/homes/{homeId}/receipts',
        [BearerAuthenticationMiddleware::class, 'purchasing.history'],
        'api.purchasing.receipts.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/receipts',
        [BearerAuthenticationMiddleware::class, 'purchasing.create'],
        'api.purchasing.receipts.create',
    );
    $app->get(
        '/api/v1/homes/{homeId}/receipts/{receiptId}',
        [BearerAuthenticationMiddleware::class, 'purchasing.get'],
        'api.purchasing.receipts.get',
    );
    $app->post(
        '/api/v1/homes/{homeId}/stores',
        [BearerAuthenticationMiddleware::class, 'purchasing.stores.create'],
        'api.purchasing.stores.create',
    );
    $app->get(
        '/api/v1/homes/{homeId}/purchase-summary',
        [BearerAuthenticationMiddleware::class, 'purchasing.summary'],
        'api.purchasing.summary',
    );
    $app->post(
        '/api/v1/homes/{homeId}/receipts/{receiptId}/lines',
        [BearerAuthenticationMiddleware::class, 'purchasing.lines.create'],
        'api.purchasing.receipt-lines.create',
    );
    $app->post(
        '/api/v1/homes/{homeId}/receipts/{receiptId}/lines/{lineId}/approve',
        [BearerAuthenticationMiddleware::class, 'purchasing.lines.approve'],
        'api.purchasing.receipt-lines.approve',
    );
    $app->post(
        '/api/v1/homes/{homeId}/receipts/{receiptId}/commit',
        [BearerAuthenticationMiddleware::class, 'purchasing.commit'],
        'api.purchasing.receipts.commit',
    );
    $app->get(
        '/api/v1/homes/{homeId}/shopping-lists',
        [BearerAuthenticationMiddleware::class, 'shopping.lists.list'],
        'api.shopping.lists.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/shopping-lists',
        [BearerAuthenticationMiddleware::class, 'shopping.lists.create'],
        'api.shopping.lists.create',
    );
    $app->get(
        '/api/v1/homes/{homeId}/shopping-lists/{listId}',
        [BearerAuthenticationMiddleware::class, 'shopping.lists.get'],
        'api.shopping.lists.get',
    );
    $app->post(
        '/api/v1/homes/{homeId}/shopping-lists/{listId}/lines',
        [BearerAuthenticationMiddleware::class, 'shopping.lines.create'],
        'api.shopping.lines.create',
    );
    $app->put(
        '/api/v1/homes/{homeId}/shopping-lists/{listId}/lines/{lineId}/checked',
        [BearerAuthenticationMiddleware::class, 'shopping.lines.check'],
        'api.shopping.lines.checked',
    );
    $app->get(
        '/api/v1/homes/{homeId}/shopping-suggestions',
        [BearerAuthenticationMiddleware::class, 'shopping.suggestions'],
        'api.shopping.suggestions',
    );
    $app->post(
        '/api/v1/homes/{homeId}/sync/push',
        [BearerAuthenticationMiddleware::class, 'synchronization.push'],
        'api.synchronization.push',
    );
    $app->get(
        '/api/v1/homes/{homeId}/sync/pull',
        [BearerAuthenticationMiddleware::class, 'synchronization.pull'],
        'api.synchronization.pull',
    );
    $app->get(
        '/api/v1/homes/{homeId}/sync/bootstrap',
        [BearerAuthenticationMiddleware::class, 'synchronization.bootstrap'],
        'api.synchronization.bootstrap',
    );
};
