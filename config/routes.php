<?php

declare(strict_types=1);

use Mezzio\Application;
use Providentia\Catalog\Http\CatalogCategoryHandler;
use Providentia\Catalog\Http\CatalogContributionPromotionHandler;
use Providentia\Catalog\Http\CatalogSearchHandler;
use Providentia\Catalog\Http\CatalogProductHandler;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Identity\Http\AuthenticationRateLimitMiddleware;
use Providentia\Identity\Http\LoginLinkProofRateLimitMiddleware;
use Providentia\Reporting\Http\DashboardHandler;
use Providentia\SharedKernel\Http\Health\LivenessHandler;
use Providentia\SharedKernel\Http\Health\ReadinessHandler;
use Providentia\SharedKernel\Http\MetricsHandler;
use Providentia\SharedKernel\Http\SystemInfoHandler;

return static function (Application $app): void {
    $app->get('/health/live', LivenessHandler::class, 'health.live');
    $app->get('/health/ready', ReadinessHandler::class, 'health.ready');
    $app->get('/api/v1/system/info', SystemInfoHandler::class, 'api.system.info');
    $app->get('/metrics', MetricsHandler::class, 'metrics');

    $app->post(
        '/api/v1/auth/register',
        [AuthenticationRateLimitMiddleware::class, 'identity.register'],
        'api.auth.register',
    );
    $app->post(
        '/api/v1/auth/login-links',
        [AuthenticationRateLimitMiddleware::class, 'identity.login-link-start'],
        'api.auth.login-links.start',
    );
    $app->post(
        '/api/v1/auth/login-links/{requestId}/status',
        [LoginLinkProofRateLimitMiddleware::class, 'identity.login-link-status'],
        'api.auth.login-links.status',
    );
    $app->post(
        '/api/v1/auth/login-links/{requestId}/proof',
        [LoginLinkProofRateLimitMiddleware::class, 'identity.login-link-proof'],
        'api.auth.login-links.proof',
    );
    $app->post(
        '/api/v1/auth/login-links/{requestId}/review',
        [LoginLinkProofRateLimitMiddleware::class, 'identity.login-link-review'],
        'api.auth.login-links.review',
    );
    $app->post(
        '/api/v1/auth/login-links/{requestId}/decision',
        [LoginLinkProofRateLimitMiddleware::class, 'identity.login-link-decision'],
        'api.auth.login-links.decision',
    );
    $app->post(
        '/api/v1/auth/login-links/{requestId}/exchange',
        [LoginLinkProofRateLimitMiddleware::class, 'identity.login-link-exchange'],
        'api.auth.login-links.exchange',
    );
    $app->post(
        '/api/v1/auth/login-links/{requestId}/cancel',
        [LoginLinkProofRateLimitMiddleware::class, 'identity.login-link-cancel'],
        'api.auth.login-links.cancel',
    );
    $app->post(
        '/api/v1/auth/step-up-links',
        [BearerAuthenticationMiddleware::class, 'identity.step-up-request'],
        'api.auth.step-up-links.request',
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
        'identity.logout',
        'api.auth.logout',
    );
    $app->get(
        '/api/v1/me',
        [BearerAuthenticationMiddleware::class, 'identity.me'],
        'api.me',
    );
    $app->get(
        '/api/v1/platform/administrators',
        [BearerAuthenticationMiddleware::class, 'identity.platform-administrators-list'],
        'api.platform-administrators.list',
    );
    $app->post(
        '/api/v1/platform/administrators',
        [BearerAuthenticationMiddleware::class, 'identity.platform-administrators-grant'],
        'api.platform-administrators.grant',
    );
    $app->post(
        '/api/v1/platform/administrators/{administratorId}/revoke',
        [BearerAuthenticationMiddleware::class, 'identity.platform-administrators-revoke'],
        'api.platform-administrators.revoke',
    );
    $app->get(
        '/api/v1/admin/accounts',
        [BearerAuthenticationMiddleware::class, 'administration.operator-accounts-list'],
        'api.admin.accounts.list',
    );
    $app->get(
        '/api/v1/admin/accounts/{userId}',
        [BearerAuthenticationMiddleware::class, 'administration.operator-accounts-get'],
        'api.admin.accounts.get',
    );
    $app->patch(
        '/api/v1/admin/accounts/{userId}/status',
        [BearerAuthenticationMiddleware::class, 'administration.operator-accounts-status'],
        'api.admin.accounts.status',
    );
    $app->put(
        '/api/v1/admin/accounts/{userId}/roles/{role}',
        [BearerAuthenticationMiddleware::class, 'administration.operator-accounts-role-grant'],
        'api.admin.accounts.roles.grant',
    );
    $app->delete(
        '/api/v1/admin/accounts/{userId}/roles/{role}',
        [BearerAuthenticationMiddleware::class, 'administration.operator-accounts-role-revoke'],
        'api.admin.accounts.roles.revoke',
    );

    $app->post('/api/v1/homes', [BearerAuthenticationMiddleware::class, 'home.create'], 'api.homes.create');
    $app->get('/api/v1/homes', [BearerAuthenticationMiddleware::class, 'home.list'], 'api.homes.list');
    $app->get(
        '/api/v1/me/home-invitations',
        [BearerAuthenticationMiddleware::class, 'home.pending-invitations'],
        'api.me.home-invitations.list',
    );
    $app->post(
        '/api/v1/me/home-invitations/{invitationId}/accept',
        [BearerAuthenticationMiddleware::class, 'home.accept-invitation-by-id'],
        'api.me.home-invitations.accept',
    );
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
    $app->patch(
        '/api/v1/homes/{homeId}',
        [BearerAuthenticationMiddleware::class, 'home.update'],
        'api.homes.update',
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
    $app->get(
        '/api/v1/homes/{homeId}/permission-policies',
        [BearerAuthenticationMiddleware::class, 'home.permission-policies'],
        'api.home-permission-policies.list',
    );
    $app->put(
        '/api/v1/homes/{homeId}/permission-policies/{role}',
        [BearerAuthenticationMiddleware::class, 'home.configure-permissions'],
        'api.home-permission-policies.put',
    );
    $app->get(
        '/api/v1/homes/{homeId}/invitations',
        [BearerAuthenticationMiddleware::class, 'home.invitations'],
        'api.home-invitations.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/invitations',
        [BearerAuthenticationMiddleware::class, 'home.invite'],
        'api.home-invitations.create',
    );
    $app->post(
        '/api/v1/homes/{homeId}/invitations/{invitationId}/revoke',
        [BearerAuthenticationMiddleware::class, 'home.revoke-invitation'],
        'api.home-invitations.revoke',
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
    $app->get(
        '/api/v1/homes/{homeId}/ownership-transfers',
        [BearerAuthenticationMiddleware::class, 'home.ownership-transfers'],
        'api.home-ownership-transfers.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/ownership-transfers',
        [BearerAuthenticationMiddleware::class, 'home.propose-ownership-transfer'],
        'api.home-ownership-transfers.create',
    );
    $app->post(
        '/api/v1/homes/{homeId}/ownership-transfers/{transferId}/accept',
        [BearerAuthenticationMiddleware::class, 'home.accept-ownership-transfer'],
        'api.home-ownership-transfers.accept',
    );
    $app->post(
        '/api/v1/homes/{homeId}/ownership-transfers/{transferId}/reject',
        [BearerAuthenticationMiddleware::class, 'home.reject-ownership-transfer'],
        'api.home-ownership-transfers.reject',
    );
    $app->post(
        '/api/v1/homes/{homeId}/ownership-transfers/{transferId}/revoke',
        [BearerAuthenticationMiddleware::class, 'home.revoke-ownership-transfer'],
        'api.home-ownership-transfers.revoke',
    );

    $app->get('/api/v1/catalog/products', CatalogSearchHandler::class, 'api.catalog.products.search');
    $app->get(
        '/api/v1/catalog/products/{productId}',
        CatalogProductHandler::class,
        'api.catalog.products.get',
    );
    $app->post(
        '/api/v1/catalog/proposals',
        [BearerAuthenticationMiddleware::class, 'catalog.governance.proposals.submit'],
        'api.catalog.proposals.submit',
    );
    $app->get(
        '/api/v1/catalog-admin/workbench',
        [BearerAuthenticationMiddleware::class, 'catalog.governance.workbench'],
        'api.catalog-admin.workbench',
    );
    $app->post(
        '/api/v1/catalog-admin/proposals/{proposalId}/decision',
        [BearerAuthenticationMiddleware::class, 'catalog.governance.proposals.decision'],
        'api.catalog-admin.proposals.decision',
    );
    $app->post(
        '/api/v1/catalog-admin/conflicts/{conflictId}/keep-existing',
        [BearerAuthenticationMiddleware::class, 'catalog.governance.conflicts.keep'],
        'api.catalog-admin.conflicts.keep-existing',
    );
    $app->put(
        '/api/v1/catalog-admin/icons/{targetType}/{targetId}',
        [BearerAuthenticationMiddleware::class, 'catalog.governance.icons.put'],
        'api.catalog-admin.icons.put',
    );
    $app->post(
        '/api/v1/catalog-admin/merges/preview',
        [BearerAuthenticationMiddleware::class, 'catalog.governance.merges.preview'],
        'api.catalog-admin.merges.preview',
    );
    $app->post(
        '/api/v1/catalog-admin/merges',
        [BearerAuthenticationMiddleware::class, 'catalog.governance.merges.apply'],
        'api.catalog-admin.merges.apply',
    );
    $app->post(
        '/api/v1/catalog-admin/merges/{mergeId}/reverse',
        [BearerAuthenticationMiddleware::class, 'catalog.governance.merges.reverse'],
        'api.catalog-admin.merges.reverse',
    );
    $app->get(
        '/api/v1/homes/{homeId}/dashboard',
        [BearerAuthenticationMiddleware::class, DashboardHandler::class],
        'api.dashboard',
    );
    $app->get(
        '/api/v1/homes/{homeId}/reports/inventory',
        [BearerAuthenticationMiddleware::class, 'reporting.home.inventory'],
        'api.reporting.inventory',
    );
    $app->get(
        '/api/v1/homes/{homeId}/reports/purchases',
        [BearerAuthenticationMiddleware::class, 'reporting.home.purchases'],
        'api.reporting.purchases',
    );
    $app->get(
        '/api/v1/homes/{homeId}/reports/consumption',
        [BearerAuthenticationMiddleware::class, 'reporting.home.consumption'],
        'api.reporting.consumption',
    );
    $app->get(
        '/api/v1/homes/{homeId}/reports/suggestions',
        [BearerAuthenticationMiddleware::class, 'reporting.home.suggestions'],
        'api.reporting.suggestions',
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
        '/api/v1/homes/{homeId}/categories',
        [BearerAuthenticationMiddleware::class, 'inventory.categories.list'],
        'api.inventory.categories.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/categories',
        [BearerAuthenticationMiddleware::class, 'inventory.categories.create'],
        'api.inventory.categories.create',
    );
    $app->patch(
        '/api/v1/homes/{homeId}/categories/{homeCategoryId}',
        [BearerAuthenticationMiddleware::class, 'inventory.categories.update'],
        'api.inventory.categories.update',
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
    $app->patch(
        '/api/v1/homes/{homeId}/products/{homeProductId}',
        [BearerAuthenticationMiddleware::class, 'inventory.items.update'],
        'api.inventory.items.update',
    );
    $app->get(
        '/api/v1/homes/{homeId}/stock',
        [BearerAuthenticationMiddleware::class, 'inventory.stock.list'],
        'api.inventory.stock.list',
    );
    $app->get(
        '/api/v1/homes/{homeId}/inventory-balances',
        [BearerAuthenticationMiddleware::class, 'inventory.balances.list'],
        'api.inventory.balances.list',
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
    $app->post(
        '/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}/cancel',
        [BearerAuthenticationMiddleware::class, 'inventory.counts.cancel'],
        'api.inventory.counts.cancel',
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
        '/api/v1/homes/{homeId}/receipts/{receiptId}/lines/{lineId}/unresolve',
        [BearerAuthenticationMiddleware::class, 'purchasing.lines.unresolve'],
        'api.purchasing.receipt-lines.unresolve',
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
        [BearerAuthenticationMiddleware::class, 'shopping.intelligence.suggestions.list'],
        'api.shopping.suggestions',
    );
    $app->post(
        '/api/v1/homes/{homeId}/shopping-suggestion-runs',
        [BearerAuthenticationMiddleware::class, 'shopping.intelligence.runs.create'],
        'api.shopping.suggestion-runs.create',
    );
    $app->get(
        '/api/v1/homes/{homeId}/shopping-suggestions/{suggestionId}/explanation',
        [BearerAuthenticationMiddleware::class, 'shopping.intelligence.explanation.get'],
        'api.shopping.suggestions.explanation',
    );
    $app->post(
        '/api/v1/homes/{homeId}/shopping-suggestions/{suggestionId}/feedback',
        [BearerAuthenticationMiddleware::class, 'shopping.intelligence.feedback.create'],
        'api.shopping.suggestions.feedback',
    );
    $app->get(
        '/api/v1/homes/{homeId}/consumption-estimates',
        [BearerAuthenticationMiddleware::class, 'shopping.intelligence.estimates.list'],
        'api.shopping.consumption-estimates.list',
    );
    $app->get(
        '/api/v1/homes/{homeId}/stock-preferences/{homeProductId}',
        [BearerAuthenticationMiddleware::class, 'shopping.intelligence.preferences.get'],
        'api.shopping.preferences.get',
    );
    $app->put(
        '/api/v1/homes/{homeId}/stock-preferences/{homeProductId}',
        [BearerAuthenticationMiddleware::class, 'shopping.intelligence.preferences.put'],
        'api.shopping.preferences.put',
    );
    $app->get(
        '/api/v1/homes/{homeId}/price-comparisons',
        [BearerAuthenticationMiddleware::class, 'shopping.intelligence.prices.list'],
        'api.shopping.price-comparisons.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/suggestion-backtests',
        [BearerAuthenticationMiddleware::class, 'shopping.intelligence.backtests.create'],
        'api.shopping.backtests.create',
    );
    $app->get(
        '/api/v1/homes/{homeId}/suggestion-backtests/{backtestId}',
        [BearerAuthenticationMiddleware::class, 'shopping.intelligence.backtests.get'],
        'api.shopping.backtests.get',
    );
    $app->get(
        '/api/v1/homes/{homeId}/ai/settings',
        [BearerAuthenticationMiddleware::class, 'ai.settings.get'],
        'api.ai.settings.get',
    );
    $app->put(
        '/api/v1/homes/{homeId}/ai/settings',
        [BearerAuthenticationMiddleware::class, 'ai.settings.put'],
        'api.ai.settings.put',
    );
    $app->put(
        '/api/v1/homes/{homeId}/ai/credentials/{providerId}',
        [BearerAuthenticationMiddleware::class, 'ai.credentials.put'],
        'api.ai.credentials.put',
    );
    $app->delete(
        '/api/v1/homes/{homeId}/ai/credentials/{providerId}',
        [BearerAuthenticationMiddleware::class, 'ai.credentials.delete'],
        'api.ai.credentials.delete',
    );
    $app->get(
        '/api/v1/homes/{homeId}/ai/profiles',
        [BearerAuthenticationMiddleware::class, 'ai.profiles.list'],
        'api.ai.profiles.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/ai/profiles',
        [BearerAuthenticationMiddleware::class, 'ai.profiles.put'],
        'api.ai.profiles.create',
    );
    $app->put(
        '/api/v1/homes/{homeId}/ai/profiles/{profileId}',
        [BearerAuthenticationMiddleware::class, 'ai.profiles.put'],
        'api.ai.profiles.update',
    );
    $app->delete(
        '/api/v1/homes/{homeId}/ai/profiles/{profileId}',
        [BearerAuthenticationMiddleware::class, 'ai.profiles.delete'],
        'api.ai.profiles.delete',
    );
    $app->delete(
        '/api/v1/homes/{homeId}/ai/profiles/{profileId}/credential',
        [BearerAuthenticationMiddleware::class, 'ai.profiles.credential.delete'],
        'api.ai.profiles.credential.delete',
    );
    $app->get(
        '/api/v1/homes/{homeId}/ai/policy',
        [BearerAuthenticationMiddleware::class, 'ai.policy.get'],
        'api.ai.policy.get',
    );
    $app->put(
        '/api/v1/homes/{homeId}/ai/policy',
        [BearerAuthenticationMiddleware::class, 'ai.policy.put'],
        'api.ai.policy.put',
    );
    $app->post(
        '/api/v1/homes/{homeId}/ai/extractions',
        [BearerAuthenticationMiddleware::class, 'ai.extractions.create'],
        'api.ai.extractions.create',
    );
    $app->post(
        '/api/v1/homes/{homeId}/ai/extractions/stored-media',
        [BearerAuthenticationMiddleware::class, 'ai.extractions.create-stored'],
        'api.ai.extractions.create-stored',
    );
    $app->get(
        '/api/v1/homes/{homeId}/ai/extractions/{extractionId}',
        [BearerAuthenticationMiddleware::class, 'ai.extractions.get'],
        'api.ai.extractions.get',
    );
    $app->put(
        '/api/v1/homes/{homeId}/ai/extractions/{extractionId}/candidates/{position}',
        [BearerAuthenticationMiddleware::class, 'ai.candidates.review'],
        'api.ai.candidates.review',
    );
    $app->put(
        '/api/v1/homes/{homeId}/ai/extractions/{extractionId}/observations/{decisionId}',
        [BearerAuthenticationMiddleware::class, 'ai.observations.review'],
        'api.ai.observations.review',
    );
    $app->put(
        '/api/v1/homes/{homeId}/ai/extractions/{extractionId}/discrepancies/{position}',
        [BearerAuthenticationMiddleware::class, 'ai.discrepancies.review'],
        'api.ai.discrepancies.review',
    );
    $app->post(
        '/api/v1/homes/{homeId}/ai/media',
        [BearerAuthenticationMiddleware::class, 'ai.media.upload'],
        'api.ai.media.upload',
    );
    $app->get(
        '/api/v1/homes/{homeId}/ai/media',
        [BearerAuthenticationMiddleware::class, 'ai.media.list'],
        'api.ai.media.list',
    );
    $app->get(
        '/api/v1/homes/{homeId}/ai/media/export',
        [BearerAuthenticationMiddleware::class, 'ai.media.export'],
        'api.ai.media.export',
    );
    $app->get(
        '/api/v1/homes/{homeId}/ai/media/{assetId}',
        [BearerAuthenticationMiddleware::class, 'ai.media.download'],
        'api.ai.media.download',
    );
    $app->delete(
        '/api/v1/homes/{homeId}/ai/media/{assetId}',
        [BearerAuthenticationMiddleware::class, 'ai.media.delete'],
        'api.ai.media.delete',
    );
    $app->put(
        '/api/v1/homes/{homeId}/ai/media/{assetId}/retention',
        [BearerAuthenticationMiddleware::class, 'ai.media.retention'],
        'api.ai.media.retention',
    );
    $app->get(
        '/api/v1/homes/{homeId}/catalog-contributions/consent',
        [BearerAuthenticationMiddleware::class, 'catalog.contributions.consent.get'],
        'api.catalog.contributions.consent.get',
    );
    $app->put(
        '/api/v1/homes/{homeId}/catalog-contributions/consent',
        [BearerAuthenticationMiddleware::class, 'catalog.contributions.consent.put'],
        'api.catalog.contributions.consent.put',
    );
    $app->get(
        '/api/v1/homes/{homeId}/catalog-contributions',
        [BearerAuthenticationMiddleware::class, 'catalog.contributions.list'],
        'api.catalog.contributions.list',
    );
    $app->post(
        '/api/v1/homes/{homeId}/catalog-contributions',
        [BearerAuthenticationMiddleware::class, 'catalog.contributions.submit'],
        'api.catalog.contributions.submit',
    );
    $app->post(
        '/api/v1/homes/{homeId}/catalog-contributions/images',
        [BearerAuthenticationMiddleware::class, 'catalog.contribution-images.upload'],
        'api.catalog.contribution-images.upload',
    );
    $app->get(
        '/api/v1/catalog/categories',
        CatalogCategoryHandler::class,
        'api.catalog.categories.list',
    );
    $app->get(
        '/api/v1/catalog-contributions',
        'catalog.contributions.published.list',
        'api.catalog.contributions.published.list',
    );
    $app->get(
        '/api/v1/catalog-contributions/review',
        [BearerAuthenticationMiddleware::class, 'catalog.contributions.review.list'],
        'api.catalog.contributions.review.list',
    );
    $app->put(
        '/api/v1/catalog-contributions/{contributionId}/decision',
        [BearerAuthenticationMiddleware::class, 'catalog.contributions.review.decide'],
        'api.catalog.contributions.review.decide',
    );
    $app->put(
        '/api/v1/catalog-contributions/{contributionId}/proposal',
        [BearerAuthenticationMiddleware::class, CatalogContributionPromotionHandler::class],
        'api.catalog.contributions.proposal.put',
    );
    $app->get(
        '/api/v1/catalog-contributions/{contributionId}/image-preview',
        [BearerAuthenticationMiddleware::class, 'catalog.contribution-images.preview'],
        'api.catalog.contribution-images.preview',
    );
    $app->put(
        '/api/v1/catalog-contributions/{contributionId}/image-publication',
        [BearerAuthenticationMiddleware::class, 'catalog.contribution-images.publication'],
        'api.catalog.contribution-images.publication',
    );
    $app->get(
        '/api/v1/catalog/assets/{assetDigest}',
        'catalog.contribution-images.content',
        'api.catalog.assets.get',
    );
    $app->post(
        '/api/v1/homes/{homeId}/catalog-imports',
        [BearerAuthenticationMiddleware::class, 'catalog.imports.stage'],
        'api.catalog.imports.stage',
    );
    $app->get(
        '/api/v1/homes/{homeId}/catalog-imports/{importId}',
        [BearerAuthenticationMiddleware::class, 'catalog.imports.get'],
        'api.catalog.imports.get',
    );
    $app->post(
        '/api/v1/homes/{homeId}/catalog-imports/{importId}/confirm',
        [BearerAuthenticationMiddleware::class, 'catalog.imports.confirm'],
        'api.catalog.imports.confirm',
    );
    $app->post(
        '/api/v1/account/data-exports',
        [BearerAuthenticationMiddleware::class, 'data-governance.account.export'],
        'api.data-governance.account.export',
    );
    $app->post(
        '/api/v1/account/erasure-requests',
        [BearerAuthenticationMiddleware::class, 'data-governance.account.erasure'],
        'api.data-governance.account.erasure',
    );
    $app->get(
        '/api/v1/account/data-governance-requests',
        [BearerAuthenticationMiddleware::class, 'data-governance.account.requests'],
        'api.data-governance.account.requests',
    );
    $app->post(
        '/api/v1/homes/{homeId}/data-exports',
        [BearerAuthenticationMiddleware::class, 'data-governance.home.export'],
        'api.data-governance.home.export',
    );
    $app->post(
        '/api/v1/homes/{homeId}/erasure-requests',
        [BearerAuthenticationMiddleware::class, 'data-governance.home.erasure'],
        'api.data-governance.home.erasure',
    );
    $app->get(
        '/api/v1/homes/{homeId}/data-governance-requests',
        [BearerAuthenticationMiddleware::class, 'data-governance.home.requests'],
        'api.data-governance.home.requests',
    );
    $app->post(
        '/api/v1/data-governance-requests/{requestId}/cancel',
        [BearerAuthenticationMiddleware::class, 'data-governance.request.cancel'],
        'api.data-governance.requests.cancel',
    );
    $app->post(
        '/api/v1/data-governance-requests/{requestId}/download-token',
        [BearerAuthenticationMiddleware::class, 'data-governance.request.download-token'],
        'api.data-governance.requests.download-token',
    );
    $app->post(
        '/api/v1/data-governance-requests/{requestId}/download',
        [BearerAuthenticationMiddleware::class, 'data-governance.request.download'],
        'api.data-governance.requests.download',
    );
    $app->get(
        '/api/v1/billing/plans',
        'billing.plans.available',
        'api.billing.plans.available',
    );
    $app->get(
        '/api/v1/operator/billing/plans',
        [BearerAuthenticationMiddleware::class, 'billing.operator.plans.list'],
        'api.billing.operator.plans.list',
    );
    $app->post(
        '/api/v1/operator/billing/plans',
        [BearerAuthenticationMiddleware::class, 'billing.operator.plans.create'],
        'api.billing.operator.plans.create',
    );
    $app->put(
        '/api/v1/operator/billing/plans/{planId}',
        [BearerAuthenticationMiddleware::class, 'billing.operator.plans.update'],
        'api.billing.operator.plans.update',
    );
    $app->post(
        '/api/v1/operator/billing/plans/{planId}/prices',
        [BearerAuthenticationMiddleware::class, 'billing.operator.prices.create'],
        'api.billing.operator.prices.create',
    );
    $app->put(
        '/api/v1/operator/billing/prices/{priceId}/status',
        [BearerAuthenticationMiddleware::class, 'billing.operator.prices.status'],
        'api.billing.operator.prices.status',
    );
    $app->put(
        '/api/v1/operator/billing/prices/{priceId}/providers/{provider}',
        [BearerAuthenticationMiddleware::class, 'billing.operator.provider-prices.put'],
        'api.billing.operator.provider-prices.put',
    );
    $app->put(
        '/api/v1/operator/billing/plans/{planId}/entitlements/{featureKey}',
        [BearerAuthenticationMiddleware::class, 'billing.operator.entitlements.put'],
        'api.billing.operator.entitlements.put',
    );
    $app->post(
        '/api/v1/operator/billing/promotions',
        [BearerAuthenticationMiddleware::class, 'billing.operator.promotions.create'],
        'api.billing.operator.promotions.create',
    );
    $app->post(
        '/api/v1/operator/billing/homes/{homeId}/overrides',
        [BearerAuthenticationMiddleware::class, 'billing.operator.overrides.put'],
        'api.billing.operator.overrides.put',
    );
    $app->delete(
        '/api/v1/operator/billing/overrides/{overrideId}',
        [BearerAuthenticationMiddleware::class, 'billing.operator.overrides.revoke'],
        'api.billing.operator.overrides.revoke',
    );
    $app->get(
        '/api/v1/homes/{homeId}/billing',
        [BearerAuthenticationMiddleware::class, 'billing.home.summary'],
        'api.billing.home.summary',
    );
    $app->post(
        '/api/v1/homes/{homeId}/billing/checkouts',
        [BearerAuthenticationMiddleware::class, 'billing.checkout.create'],
        'api.billing.checkouts.create',
    );
    $app->post(
        '/api/v1/billing/webhooks/{provider}',
        'billing.webhook',
        'api.billing.webhooks.accept',
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
    $app->post(
        '/api/v1/homes/{homeId}/sync/operation-status',
        [BearerAuthenticationMiddleware::class, 'synchronization.operation-status'],
        'api.synchronization.operation-status',
    );
};
