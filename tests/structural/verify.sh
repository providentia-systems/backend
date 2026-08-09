#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

modules=(
  SharedKernel Identity Home Catalog Inventory Purchasing Shopping
  Synchronization AiIntegration Billing DataGovernance Administration Reporting PublicSite
)
layers=(Domain Application Infrastructure Http)

fail() {
  printf 'STRUCTURAL ERROR: %s\n' "$*" >&2
  exit 1
}

assert_no_matches() {
  local description="$1"
  shift
  local output
  local status

  set +e
  output="$(rg "$@" 2>&1)"
  status=$?
  set -e

  if [ "$status" -eq 0 ]; then
    printf '%s\n' "$output" >&2
    fail "$description"
  fi
  if [ "$status" -ne 1 ]; then
    printf '%s\n' "$output" >&2
    fail "ripgrep failed while checking: $description"
  fi
}

for module in "${modules[@]}"; do
  test -f "src/${module}/ConfigProvider.php" || fail "${module} has no ConfigProvider"
  for layer in "${layers[@]}"; do
    test -d "src/${module}/${layer}" || fail "${module}/${layer} boundary is missing"
  done
  grep -Fq "Providentia\\${module}\\ConfigProvider::class" config/config.php \
    || fail "${module} ConfigProvider is not aggregated"
done

former_name='Stock''Home'
former_lower='stock''home'
assert_no_matches "former prototype name leaked outside historical evidence" \
  -n "${former_name}|${former_lower}" \
  --glob '!docs/product/phases/phase-00-evidence/**' \
  --glob '!docs/product/project-memory.md' \
  --glob '!docs/product/providentia_master_implementation_prompt_V1.md' \
  --glob '!tests/structural/verify.sh' \
  .

for file in composer.json compose.yaml contracts/openapi/providentia-v1.json \
  contracts/design-tokens/providentia-v1.json; do
  test -s "$file" || fail "$file is missing or empty"
done

grep -Fq '#approval=' src/Identity/Infrastructure/Notification/SmtpAccountNotificationSender.php \
  || fail "login-link email must keep the approval capability in a browser fragment"
assert_no_matches "login-link approval capability can leak through a query string" \
  -n -F '?approval=' src templates docs config
for caddyfile in infrastructure/caddy/Caddyfile infrastructure/caddy/Caddyfile.production; do
  for header in X-Content-Type-Options Referrer-Policy Permissions-Policy Content-Security-Policy; do
    grep -Fq "?$header" "$caddyfile" \
      || fail "$caddyfile must set $header only when the application omitted it"
  done
done
assert_no_matches "edge proxy must not overwrite application-owned dynamic security headers" \
  -n '^[[:space:]]+(Content-Security-Policy|Referrer-Policy|Permissions-Policy|X-Content-Type-Options)[[:space:]]' \
  infrastructure/caddy
legacy_login_segment='magic''-links'
assert_no_matches "legacy raw-token email login route remains reachable" \
  -n -F "/api/v1/auth/${legacy_login_segment}" config/routes.php
assert_no_matches "unverified login request IDs create persistent rate-limit buckets" \
  -n -F 'login-link-request-ip:' src/Identity

for executable in bin/doctrine-migrations bin/providentia \
  infrastructure/compose/entrypoint.sh tool/generate-dart-client.sh \
  tool/check-composer-licenses.php tests/structural/verify.sh \
  scripts/setup-development.sh scripts/provision-development-user.sh \
  scripts/reset-development.sh; do
  test -x "$executable" || fail "$executable must be executable"
done

for shell_script in infrastructure/compose/entrypoint.sh tool/generate-dart-client.sh \
  scripts/setup-development.sh scripts/provision-development-user.sh \
  scripts/reset-development.sh \
  tests/Acceptance/development-http-smoke.sh \
  tests/Acceptance/development-auth-http-smoke.sh; do
  bash -n "$shell_script" || fail "$shell_script has invalid shell syntax"
done

node <<'NODE'
const fs = require('node:fs');
const crypto = require('node:crypto');
const path = require('node:path');
const contractSource = fs.readFileSync('contracts/openapi/providentia-v1.json', 'utf8');
const contract = JSON.parse(contractSource);
const routeSource = fs.readFileSync('config/routes.php', 'utf8');
const expected = {
  '/health/live': {get: 'getLiveness'},
  '/health/ready': {get: 'getReadiness'},
  '/api/v1/system/info': {get: 'getSystemInfo'},
  '/metrics': {get: 'getMetrics'},
  '/api/v1/auth/register': {post: 'registerAccount'},
  '/api/v1/auth/login': {post: 'login'},
  '/api/v1/auth/login-links': {post: 'startLoginLink'},
  '/api/v1/auth/login-links/{requestId}/status': {post: 'getLoginLinkStatus'},
  '/api/v1/auth/login-links/{requestId}/exchange': {post: 'exchangeLoginLink'},
  '/api/v1/auth/login-links/{requestId}/cancel': {post: 'cancelLoginLink'},
  '/api/v1/me': {get: 'getCurrentUser'},
  '/api/v1/me/home-invitations': {get: 'listPendingHomeInvitations'},
  '/api/v1/me/home-invitations/{invitationId}/accept': {post: 'acceptHomeInvitationById'},
  '/api/v1/platform/administrators': {
    get: 'listPlatformAdministrators', post: 'grantPlatformAdministrator',
  },
  '/api/v1/platform/administrators/{administratorId}/revoke': {post: 'revokePlatformAdministrator'},
  '/api/v1/homes': {get: 'listHomes'},
  '/api/v1/homes/{homeId}': {get: 'getHome', patch: 'updateHome'},
  '/api/v1/homes/{homeId}/ownership-transfer': {post: 'transferHomeOwnership'},
  '/api/v1/catalog/products': {get: 'searchCatalogProducts'},
  '/api/v1/homes/{homeId}/sync/push': {post: 'pushHomeSynchronization'},
  '/api/v1/homes/{homeId}/sync/pull': {get: 'pullHomeSynchronization'},
  '/api/v1/homes/{homeId}/sync/bootstrap': {get: 'bootstrapHomeSynchronization'},
};
for (const [path, methods] of Object.entries(expected)) {
  for (const [method, operationId] of Object.entries(methods)) {
    const actual = contract.paths?.[path]?.[method]?.operationId;
    if (actual !== operationId) {
      throw new Error(`Missing ${operationId} on ${method.toUpperCase()} ${path}`);
    }
  }
}
for (const schema of [
  'HealthStatus', 'ReadinessStatus', 'SystemInfo', 'ProblemDetails',
  'RegisterRequest', 'LoginLinkStartRequest', 'LoginLinkStarted',
  'LoginLinkRequestProof', 'LoginLinkStatus', 'LoginLinkExchangeRequest',
  'SessionCredentials', 'DeviceSession', 'CurrentUserBootstrap',
  'PlatformAdministrator', 'RecipientHomeInvitation',
  'HomeInvitationAcceptance', 'Home', 'UpdateHomeRequest', 'HomeMembership', 'SyncPushRequest',
  'SyncPrivateNotePayload', 'SyncHomePreferencePayload', 'SyncPushResponse',
  'SyncPullResponse', 'SyncBootstrapResponse', 'ConsumptionEstimate',
  'ShoppingSuggestion', 'SuggestionExplanation', 'PriceComparison',
  'StockPreference', 'SuggestionBacktest', 'HomeReport',
]) {
  if (!contract.components?.schemas?.[schema]) {
    throw new Error(`Missing schema ${schema}`);
  }
}
const sessionProperties = contract.components?.schemas?.SessionCredentials?.properties ?? {};
for (const credential of ['accessToken', 'refreshToken', 'csrfToken']) {
  if (sessionProperties[credential]?.readOnly !== true || 'writeOnly' in (sessionProperties[credential] ?? {})) {
    throw new Error(`SessionCredentials.${credential} must be response-only`);
  }
}
for (const [schema, credential] of [
  ['RegisterResponse', 'developmentVerificationToken'],
  ['StepUpLinkAccepted', 'developmentStepUpToken'],
  ['InvitationCreated', 'developmentInvitationToken'],
]) {
  const property = contract.components?.schemas?.[schema]?.properties?.[credential] ?? {};
  if (property.readOnly !== true || 'writeOnly' in property) {
    throw new Error(`${schema}.${credential} must be response-only`);
  }
}
for (const [schema, credential] of [
  ['LoginLinkStartRequest', 'pollChallenge'],
  ['LoginLinkStartRequest', 'codeChallenge'],
  ['LoginLinkStartRequest', 'state'],
  ['LoginLinkRequestProof', 'pollToken'],
  ['LoginLinkExchangeRequest', 'pollToken'],
  ['LoginLinkExchangeRequest', 'codeVerifier'],
  ['LoginLinkExchangeRequest', 'state'],
]) {
  const property = contract.components?.schemas?.[schema]?.properties?.[credential] ?? {};
  if (property.writeOnly !== true || 'readOnly' in property) {
    throw new Error(`${schema}.${credential} must be request-only`);
  }
}
const loginLinkStarted = contract.components?.schemas?.LoginLinkStarted ?? {};
for (const secret of ['pollToken', 'pollSecret', 'codeVerifier', 'accessToken', 'refreshToken', 'csrfToken']) {
  if (secret in (loginLinkStarted.properties ?? {})) {
    throw new Error(`LoginLinkStarted must not return ${secret}`);
  }
}
const currentUserBootstrap = contract.components?.schemas?.CurrentUserBootstrap ?? {};
if (!(currentUserBootstrap.required ?? []).includes('pendingInvitations')
    || currentUserBootstrap.properties?.pendingInvitations?.items?.$ref
        !== '#/components/schemas/RecipientHomeInvitation') {
  throw new Error('CurrentUserBootstrap must include pending recipient invitations');
}
if (/magic-?link/i.test(contractSource)) {
  throw new Error('The authoritative contract must expose login-link terminology only');
}
const refreshRequestToken = contract.paths?.['/api/v1/auth/refresh']?.post?.requestBody
  ?.content?.['application/json']?.schema?.properties?.refreshToken ?? {};
if (refreshRequestToken.writeOnly !== true || 'readOnly' in refreshRequestToken) {
  throw new Error('The refresh request credential must remain request-only');
}
const registrationResponses = contract.paths?.['/api/v1/auth/register']?.post?.responses ?? {};
if (!registrationResponses['202'] || registrationResponses['409']) {
  throw new Error('Registration must return one generic 202 shape without an account-conflict response');
}
const runtimeRoutes = {};
const routePattern = /\$app->(get|post|put|patch|delete)\(\s*'([^']+)'/g;
let routeMatch;
while ((routeMatch = routePattern.exec(routeSource)) !== null) {
  (runtimeRoutes[routeMatch[2]] ??= []).push(routeMatch[1]);
}
const undocumentedCompatibilityRoutes = new Set([
  '/login-links/{requestId}',
  '/login-links/{requestId}/capture',
  '/login-links/{requestId}/review',
  '/login-links/{requestId}/approve',
  '/login-links/{requestId}/deny',
]);
for (const [runtimePath, methods] of Object.entries(runtimeRoutes)) {
  if (runtimePath === '/' || undocumentedCompatibilityRoutes.has(runtimePath)) continue;
  for (const method of methods) {
    if (!contract.paths?.[runtimePath]?.[method]) {
      throw new Error(`OpenAPI is missing ${method.toUpperCase()} ${runtimePath}`);
    }
  }
}
for (const [contractPath, pathItem] of Object.entries(contract.paths)) {
  for (const method of ['get', 'post', 'put', 'patch', 'delete']) {
    if (pathItem[method] && !runtimeRoutes[contractPath]?.includes(method)) {
      throw new Error(`Runtime is missing ${method.toUpperCase()} ${contractPath}`);
    }
  }
}
const tokens = JSON.parse(fs.readFileSync('contracts/design-tokens/providentia-v1.json', 'utf8'));
if (tokens.name.indexOf('Providentia') === -1 || tokens.version !== '1.0.0') {
  throw new Error('Design-token identity/version is incorrect');
}
const expectedColors = {
  canvas: '#FBF8EC', surface: '#FFFDF7', surfaceStrong: '#FFFFFF',
  forest: '#14551F', fresh: '#2F8A2A', greenDark: '#246F22',
  mint: '#E8F3DD', text: '#102714', muted: '#726E62',
  line: '#E8E1CE', warning: '#E76F00', warningSurface: '#FFF7E6',
};
for (const [name, value] of Object.entries(expectedColors)) {
  if (tokens.tokens?.color?.[name]?.value !== value) {
    throw new Error(`Design token ${name} does not match authoritative evidence`);
  }
}
for (const lockPath of [
  'contracts/openapi/contract.lock.json',
  'contracts/design-tokens/contract.lock.json',
]) {
  const lock = JSON.parse(fs.readFileSync(lockPath, 'utf8'));
  for (const [relative, metadata] of Object.entries(lock.artifacts)) {
    const artifact = path.resolve(path.dirname(lockPath), relative);
    const digest = crypto.createHash('sha256').update(fs.readFileSync(artifact)).digest('hex');
    if (digest !== metadata.sha256) {
      throw new Error(`${artifact} does not match ${lockPath}`);
    }
  }
}
NODE

grep -Fq "APP_ENV', 'development'" config/autoload/global.php \
  || fail "application environment must default to a non-production profile"
grep -Fq 'AUTH_PASSWORD_LOGIN_ENABLED: ${AUTH_PASSWORD_LOGIN_ENABLED:-0}' compose.production.yaml \
  || fail "production password login must remain disabled by default"
grep -Fq 'chmod 0600 "$handoff_file"' scripts/setup-development.sh \
  || fail "source setup must enforce protected handoff permissions after every write"
for setup_script in scripts/setup-development.sh scripts/setup-prebuilt.sh; do
  grep -Fq 'select(.role == "owner")' "$setup_script" \
    || fail "$setup_script must hand off a home owned by the bootstrap account"
done
grep -Fq 'default_image_namespace="providentia-systems/backend"' scripts/setup-prebuilt.sh \
  || fail "prebuilt setup must retain the canonical namespace fallback"
grep -Fq 'PROVIDENTIA_IMAGE_NAMESPACE' scripts/setup-prebuilt.sh \
  || fail "prebuilt setup must support repository-derived image namespaces"
grep -Fq 'agent/*)' scripts/setup-prebuilt.sh \
  || fail "prebuilt setup must select immutable candidates for trusted agent branches"
grep -Fq 'Pulled candidate image does not match this checkout' scripts/setup-prebuilt.sh \
  || fail "prebuilt setup must verify candidate revision labels"
grep -Fq -- '- "agent/**"' .github/workflows/production-image.yml \
  || fail "trusted agent branches must publish immutable pre-merge candidates"
for image in \
  'ghcr.io/providentia-systems/backend:edge' \
  'ghcr.io/providentia-systems/backend-web:edge' \
  'ghcr.io/providentia-systems/backend-media-worker:edge'; do
  grep -Fq "$image" compose.prebuilt.yaml \
    || fail "prebuilt Compose must default to $image"
done
for legacy_image in \
  'ghcr.io/vast-development-method/providentia-laminas:edge' \
  'ghcr.io/vast-development-method/providentia-laminas-web:edge' \
  'ghcr.io/vast-development-method/providentia-laminas-media-worker:edge'; do
  if grep -Fq "$legacy_image" compose.prebuilt.yaml; then
    fail "prebuilt Compose still references obsolete image $legacy_image"
  fi
done
grep -Fq "Production requires two independent, non-placeholder" config/autoload/global.php \
  || fail "production placeholder secrets do not fail closed"
grep -Fq "Production MAIL_DSN must use smtps://" config/autoload/global.php \
  || fail "production SMTP does not require authenticated TLS"
grep -Fq "'verify_peer' => true" src/Identity/Infrastructure/Notification/SmtpAccountNotificationSender.php \
  || fail "SMTPS peer verification is not explicit"
grep -Fq "'tombstone_retention_days'" config/autoload/global.php \
  || fail "tombstone retention must be explicitly configurable"
grep -Fq "'retain_until' => \$this->date" src/Synchronization/Infrastructure/Doctrine/DbalSyncStore.php \
  || fail "tombstones must retain an explicit synchronization safety boundary"
grep -Fq "['bypass_shell' => true]" src/AiIntegration/Infrastructure/Media/FfmpegVideoProcessor.php \
  || fail "the reviewed video process boundary must bypass the command shell"
grep -Fq 'private function run(' src/AiIntegration/Infrastructure/Media/FfmpegVideoProcessor.php \
  && grep -Fq 'array $command,' src/AiIntegration/Infrastructure/Media/FfmpegVideoProcessor.php \
  || fail "video processing must execute a typed argv list rather than a shell string"
grep -Fq 'FfmpegVideoProcessor.php' .semgrep.yml \
  || fail "the sole reviewed process-execution exception must remain explicit"

for expected_gate in \
  "'itemRows' => 292" \
  "'distinctProductNames' => 263" \
  "'distinctItemTuples' => 292" \
  "'categoryLabels' => 22" \
  "'aliasGroups' => 13" \
  "'aliases' => 19" \
  "'identityRules' => 19" \
  "'unresolved' => 8" \
  "'packSizePending' => 9"; do
  grep -Fq "$expected_gate" src/Catalog/Application/CatalogSeedService.php \
    || fail "catalog reconciliation gate is missing: $expected_gate"
done

assert_no_matches "catalog seed importer references private household lineage fields" \
  -ni --glob 'src/Catalog/Infrastructure/Doctrine/DbalCatalogStore.php' \
  'knownFrom|currentStock|stockLevel|receipt|medical|privateNote|mediaPath'

assert_no_matches "Domain layer imports infrastructure or transport code" \
  -n --glob 'src/*/Domain/**/*.php' \
  'Doctrine\\\\|Laminas\\\\|Mezzio\\\\|Enqueue\\\\|Interop\\\\Queue|Psr\\\\Http'

assert_no_matches "Application layer imports framework, persistence, queue transport, or HTTP code" \
  -n --glob 'src/*/Application/**/*.php' \
  'Doctrine\\\\|Laminas\\\\|Mezzio\\\\|Enqueue\\\\|Interop\\\\Queue|Psr\\\\Http|Providentia\\\\SharedKernel\\\\Http\\\\'

assert_no_matches "Application layer bypasses injected identifier or secure-token ports" \
  -n --glob 'src/*/Application/**/*.php' \
  'random_bytes\(|Ramsey\\\\Uuid|Uuid::'

assert_no_matches "HTTP layer imports persistence or queue implementation code" \
  -n --glob 'src/*/Http/**/*.php' \
  'Doctrine\\\\|Enqueue\\\\|Interop\\\\Queue|Redis\\\\|use Redis;'

assert_no_matches "web-capable runtime code depends on CLI-only stream constants" \
  -n --glob 'src/**/*.php' \
  '\bSTD(IN|OUT|ERR)\b'

assert_no_matches "A non-composition layer imports module Infrastructure" \
  -n --glob 'src/*/{Domain,Application,Http}/**/*.php' \
  'Providentia\\\\[A-Za-z]+\\\\Infrastructure\\\\'

assert_no_matches "AI proposals bypass reviewed Phase 5 inventory or purchasing commands" \
  -n --glob 'src/AiIntegration/**/*.php' \
  'Providentia\\\\(Inventory|Purchasing)\\\\|stock_movements|receipt_lines|count_session_lines'

assert_no_matches "AI persistence introduces a media payload column" \
  -ni --glob 'migrations/Version20260730000600.php' \
  "image_(data|bytes|blob|base64)|media_(data|bytes|blob)|original_image"

assert_no_matches "catalog governance transport imports household modules" \
  -n --glob 'src/Catalog/{Application,Http}/**/*.php' \
  'Providentia\\\\(Home|Inventory|Purchasing|Shopping|AiIntegration)\\\\'

assert_no_matches "catalog role command bypasses safeguarded platform-administrator governance" \
  -n --glob 'src/Catalog/Infrastructure/Cli/CatalogRoleCommand.php' \
  'PLATFORM_ADMINISTRATOR|platform_administrator'

assert_no_matches "catalog merge deletes canonical products or household history" \
  -ni --glob 'src/Catalog/Infrastructure/Doctrine/DbalCatalogGovernanceStore.php' \
  'DELETE FROM (products|home_products|stock_movements|receipts|receipt_lines|price_observations)'

for intelligence_gate in \
  "scs.scope_complete = :complete" \
  "scs.reliability = :reliability" \
  "sm.created_at <= :as_of" \
  "po.created_at <= :as_of" \
  "Prices in different currencies are shown separately and never compared." \
  "No fact after each cutoff is used to build its suggestion." \
  "'shopping.suggestion-run.completed'" \
  "'shopping.backtest.completed'" \
  "'report.generated'"; do
  rg -Fq "$intelligence_gate" src \
    || fail "Phase 8 intelligence safety gate is missing: $intelligence_gate"
done

assert_no_matches "Phase 8 intelligence reads a mutable balance projection instead of movement facts" \
  -n --glob 'src/Shopping/Infrastructure/Doctrine/DbalShoppingIntelligenceStore.php' \
  'inventory_balances'

assert_no_matches "Phase 8 deterministic domain introduces binary floating-point arithmetic" \
  -n --glob 'src/Shopping/Domain/{FixedDecimal,ConsumptionEstimator,SuggestionEngine,PackOptimizer}.php' \
  '\bfloat\b|\(float\)'

for sanitized_field in \
  "'product' => ['canonicalName', 'brand', 'categoryId']" \
  "'pack' => ['productId', 'originalPackText', 'unitId', 'amount', 'multiplicity']" \
  "'alias' => ['productId', 'variantId', 'packId', 'rawAlias']" \
  "'barcode' => ['packId', 'barcode', 'barcodeType']"; do
  grep -Fq "$sanitized_field" src/Catalog/Application/CatalogGovernanceService.php \
    || fail "catalog proposal contract is missing: $sanitized_field"
done

assert_no_matches "Migration contains a known non-portable SQL construct" \
  -n -F --glob 'migrations/*.php' \
  -e 'ENUM(' -e 'JSON_EXTRACT' -e 'ON DUPLICATE' -e 'UNSIGNED BIGINT' \
  -e 'COLLATE utf8' -e 'ENGINE='

assert_no_matches "login-link migration rollback deletes durable home permission policy" \
  -ni -F --glob 'migrations/Version20260809001600.php' \
  "DELETE FROM home_role_permission_grants"
grep -Fq "p.role IN ('owner', 'manager')" migrations/Version20260809001600.php \
  || fail "login-link migration must backfill home.manage for existing owner and manager policies"

assert_no_matches "change log uses a potentially reserved physical cursor column" \
  -n -F --glob 'migrations/*.php' --glob 'src/Synchronization/**/*.php' \
  -e 'MAX(cursor)' -e 'SELECT cursor,' -e 'AND cursor >' \
  -e 'ORDER BY cursor' -e "addColumn('cursor'"

printf 'Structural, namespace, module-boundary, token, migration, and contract checks passed.\n'
