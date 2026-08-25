#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"
bash tool/materialize-openapi-contract.sh

modules=(
  SharedKernel Identity Home Catalog Inventory Purchasing Shopping
  Synchronization AiIntegration Billing DataGovernance Administration Reporting
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
  contracts/design-tokens/providentia-v1.json \
  tools/agent-requirements.json infrastructure/agent/Dockerfile \
  infrastructure/agent/Dockerfile.dockerignore \
  docs/deployment/agent-development.md AGENTS.md \
  tests/Acceptance/compose.headless-platform-acceptance.yaml \
  tests/Acceptance/headless-platform-acceptance.sh \
  tests/fixtures/ai-provider-router.php \
  .github/workflows/headless-platform-acceptance.yml; do
  test -s "$file" || fail "$file is missing or empty"
done

grep -Fq '#requestId=%s&approval=%s' src/Identity/Infrastructure/Notification/SmtpAccountNotificationSender.php \
  || fail "login-link email must keep the request and approval capability in an application fragment"
for application_fragment in \
  '#action=verify-email&token=' \
  '#action=password-reset&token=' \
  '#action=step-up&token='; do
  grep -Fq "$application_fragment" src/Identity/Infrastructure/Notification/SmtpAccountNotificationSender.php \
    || fail "account capability must use the configured application fragment: $application_fragment"
done
assert_no_matches "account capability can leak through a query string" \
  -n '\?(approval|token)=' src docs config
assert_no_matches "removed browser base URL remains configured" \
  -n 'PUBLIC_BASE_URL|public_base_url|publicBaseUrl' \
  --glob '!docs/product/phases/phase-00-evidence/**' \
  --glob '!tests/structural/verify.sh' .
grep -Fxq 'HOMEOWNER_APP_LINK_BASE=https://client.example.net/homeowner' .env.production.example \
  || fail 'production example must target the homeowner application route'
grep -Fxq 'ADMIN_APP_LINK_BASE=providentia-admin://login-link/admin' .env.production.example \
  || fail 'production example must target the Linux Admin application route'
[[ "$(grep -Fc 'HOMEOWNER_APP_LINK_BASE: https://app.example.invalid/homeowner' \
    .github/workflows/production-image.yml)" -eq 3 ]] \
  || fail 'production image validation must use the homeowner application route in every lane'
[[ "$(grep -Fc 'ADMIN_APP_LINK_BASE: providentia-admin://login-link/admin' \
    .github/workflows/production-image.yml)" -eq 3 ]] \
  || fail 'production image validation must use the Linux Admin application route in every lane'
[[ "$(grep -Fc 'AUTH_APP_LINK_ALLOWED_HOSTS: app.example.invalid,login-link' \
    .github/workflows/production-image.yml)" -eq 3 ]] \
  || fail 'production image validation must allow exactly the configured application-link hosts'
assert_no_matches "stale generic auth application-link path remains configured" \
  -n '(HOMEOWNER|ADMIN)_APP_LINK_BASE[=:][^#[:space:]]*/auth([[:space:]]|$)' \
  .env.production.example .github/workflows/production-image.yml \
  tests/Acceptance/compose.headless-platform-acceptance.yaml
for provisioning_script in \
  scripts/setup-prebuilt.sh \
  scripts/setup-development.sh \
  scripts/provision-development-user.sh; do
  grep -Fq '{applicationKind:"homeowner",token:$token}' "$provisioning_script" \
    || fail "$provisioning_script must bind email verification to the homeowner application"
  grep -Fq '{applicationKind:"homeowner",email:$email}' "$provisioning_script" \
    || fail "$provisioning_script must bind verification resend to the homeowner application"
done
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
grep -Fq '$this->requests->find($requestId) !== null' src/Identity/Http/LoginLinkProofRateLimitMiddleware.php \
  || fail "request-scoped login-link rate limits must be created only for existing requests"
assert_no_matches "interactive backend UI files remain reachable or configured" \
  -n 'PublicSite|login-link-browser|TemplateRendererInterface|public-site::' src config
node <<'NODE'
const fs = require('node:fs');
const root = JSON.parse(fs.readFileSync('composer.json', 'utf8'));
const lock = JSON.parse(fs.readFileSync('composer.lock', 'utf8'));
const locked = new Set([...(lock.packages ?? []), ...(lock['packages-dev'] ?? [])].map(({name}) => name));
for (const packageName of ['laminas/laminas-view', 'mezzio/mezzio-laminasviewrenderer']) {
  if (root.require?.[packageName] || locked.has(packageName)) {
    throw new Error(`${packageName} must not ship in the headless backend`);
  }
}
NODE

for executable in bin/doctrine-migrations bin/providentia \
  infrastructure/compose/entrypoint.sh tool/generate-dart-client.sh \
  tool/materialize-openapi-contract.sh \
  tool/check-composer-licenses.php tests/structural/verify.sh \
  scripts/create-development-handover.sh scripts/setup-development.sh \
  scripts/provision-development-user.sh \
  scripts/reset-development.sh tools/agent-setup.sh \
  tests/Acceptance/headless-platform-acceptance.sh; do
  test -x "$executable" || fail "$executable must be executable"
done

for shell_script in infrastructure/compose/entrypoint.sh tool/generate-dart-client.sh \
  tool/materialize-openapi-contract.sh \
  scripts/create-development-handover.sh scripts/setup-development.sh \
  scripts/provision-development-user.sh \
  scripts/reset-development.sh \
  tools/agent-setup.sh \
  tests/Acceptance/development-http-smoke.sh \
  tests/Acceptance/development-auth-http-smoke.sh \
  tests/Acceptance/headless-platform-acceptance.sh; do
  bash -n "$shell_script" || fail "$shell_script has invalid shell syntax"
done

grep -Fxq '/.agent-env' .gitignore \
  || fail '.agent-env must remain ignored'
grep -Fxq '/.agent-tools/' .gitignore \
  || fail '.agent-tools must remain ignored'
grep -Fq 'bash tools/agent-setup.sh --check' .github/workflows/quality.yml \
  || fail 'quality workflow must verify the agent environment contract'
setup_function="$(sed -n '/^setup() {/,/^}/p' tools/agent-setup.sh)"
install_line="$(grep -n 'install_system_packages' <<<"$setup_function" | cut -d: -f1)"
validate_line="$(grep -n 'validate_contract' <<<"$setup_function" | cut -d: -f1)"
[[ -n "$install_line" && -n "$validate_line" && "$install_line" -lt "$validate_line" ]] \
  || fail 'agent setup must install prerequisites before Node-based validation'
grep -Fq -- "--check) validate_contract" tools/agent-setup.sh \
  || fail 'agent --check must remain a non-mutating contract validation'
grep -Fq -- "--matrix) matrix" tools/agent-setup.sh \
  || fail 'agent setup must expose the executable compatibility matrix'
doctor_function="$(sed -n '/^doctor() {/,/^}/p' tools/agent-setup.sh)"
grep -Fq 'run_compatibility_matrix' <<<"$doctor_function" \
  || fail 'agent doctor must execute the local compatibility matrix'

node <<'NODE'
const fs = require('node:fs');
const manifest = JSON.parse(fs.readFileSync('tools/agent-requirements.json', 'utf8'));
if (manifest.schemaVersion !== 1 || manifest.repositoryRole !== 'backend') {
  throw new Error('Unexpected backend agent environment schema.');
}
const pins = {
  php: '8.5.9',
  composer: '2.10.2',
  gdWebp: 'bundled-php-8.5.9',
  redisExtension: '6.3.0',
  xdebug: '3.5.3',
  node: '22.14.0',
  nodeMinimum: '18.19.0',
};
for (const [name, value] of Object.entries(pins)) {
  if (manifest.runtime?.[name] !== value) {
    throw new Error(`Agent environment ${name} pin is not ${value}.`);
  }
}
for (const domain of ['nodejs.org', 'repo.packagist.org', 'pecl.php.net', 'registry-1.docker.io', 'ghcr.io']) {
  if (!manifest.networkAllowlist?.includes(domain)) {
    throw new Error(`Agent environment network allowlist is missing ${domain}.`);
  }
}
for (const [architecture, checksum] of Object.entries({
  x64: '69b09dba5c8dcb05c4e4273a4340db1005abeafe3927efda2bc5b249e80437ec',
  arm64: '08bfbf538bad0e8cbb0269f0173cca28d705874a67a22f60b57d99dc99e30050',
})) {
  if (manifest.nodeDownloads?.linux?.[architecture]?.sha256 !== checksum) {
    throw new Error(`Agent environment Node.js ${architecture} checksum is not pinned.`);
  }
  const expectedUrl = `https://nodejs.org/download/release/v22.14.0/node-v22.14.0-linux-${architecture}.tar.xz`;
  if (manifest.nodeDownloads.linux[architecture].url !== expectedUrl) {
    throw new Error(`Agent environment Node.js ${architecture} URL is not pinned.`);
  }
}
for (const command of [
  'composer check',
  'composer test:coverage && composer coverage:check',
  'composer test:mutation',
  'composer audit --locked',
  'bash tools/agent-setup.sh --matrix',
]) {
  if (!manifest.validation?.includes(command)) {
    throw new Error(`Agent environment validation is missing ${command}.`);
  }
}
NODE

for php_image in Dockerfile Dockerfile.production infrastructure/agent/Dockerfile; do
  grep -Fq 'docker-php-ext-configure gd --with-jpeg --with-webp' "$php_image" \
    || fail "$php_image must configure GD with JPEG and WebP support"
  grep -Fq 'docker-php-ext-install -j"$(nproc)" gd' "$php_image" \
    || fail "$php_image must install the GD extension"
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
  '/api/v1/auth/login-links/{requestId}/proof': {post: 'proveLoginLinkApproval'},
  '/api/v1/auth/login-links/{requestId}/review': {post: 'reviewLoginLinkApproval'},
  '/api/v1/auth/login-links/{requestId}/decision': {post: 'decideLoginLinkApproval'},
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
  '/api/v1/homes/{homeId}/stock-count-sessions': {
    get: 'listStockCountSessions', post: 'startStockCountSession',
  },
  '/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}': {get: 'getStockCountSession'},
  '/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}/close': {
    post: 'closeStockCountSession',
  },
  '/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}/cancel': {
    post: 'cancelStockCountSession',
  },
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
  'LoginApplicationKind', 'LoginLinkApprovalProof', 'LoginLinkApprovalValidity',
  'LoginLinkApprovalReview', 'LoginLinkDecisionRequest', 'LoginLinkDecisionReceived',
  'LoginLinkRequestProof', 'LoginLinkStatus', 'LoginLinkExchangeRequest',
  'SessionCredentials', 'DeviceSession', 'CurrentUserBootstrap',
  'PlatformAdministrator', 'RecipientHomeInvitation',
  'HomeInvitationAcceptance', 'Home', 'UpdateHomeRequest', 'HomeMembership', 'SyncPushRequest',
  'SyncPrivateNotePayload', 'SyncHomePreferencePayload', 'SyncPushResponse',
  'SyncPullResponse', 'SyncBootstrapResponse', 'ConsumptionEstimate',
  'ShoppingSuggestion', 'SuggestionExplanation', 'PriceComparison',
  'StockPreference', 'StockCountSession', 'StockCountLine', 'SuggestionBacktest', 'HomeReport',
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
  ['LoginLinkApprovalProof', 'approvalToken'],
  ['LoginLinkDecisionRequest', 'approvalToken'],
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
const stockCountSessionRef = '#/components/schemas/StockCountSession';
for (const [path, method] of [
  ['/api/v1/homes/{homeId}/stock-count-sessions', 'post'],
  ['/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}', 'get'],
  ['/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}/close', 'post'],
]) {
  const ref = contract.paths?.[path]?.[method]?.responses?.['200']
    ?.content?.['application/json']?.schema?.$ref
    ?? contract.paths?.[path]?.[method]?.responses?.['201']
      ?.content?.['application/json']?.schema?.$ref;
  if (ref !== stockCountSessionRef) {
    throw new Error(`${method.toUpperCase()} ${path} must return StockCountSession`);
  }
}
const stockCountListItem = contract.paths?.['/api/v1/homes/{homeId}/stock-count-sessions']?.get
  ?.responses?.['200']?.content?.['application/json']?.schema?.properties?.data?.items?.$ref;
if (stockCountListItem !== stockCountSessionRef) {
  throw new Error('The stock-count session list must return StockCountSession items');
}
const runtimeRoutes = {};
const routePattern = /\$app->(get|post|put|patch|delete)\(\s*'([^']+)'/g;
let routeMatch;
while ((routeMatch = routePattern.exec(routeSource)) !== null) {
  (runtimeRoutes[routeMatch[2]] ??= []).push(routeMatch[1]);
}
for (const [runtimePath, methods] of Object.entries(runtimeRoutes)) {
  for (const method of methods) {
    if (!contract.paths?.[runtimePath]?.[method]) {
      throw new Error(`OpenAPI is missing ${method.toUpperCase()} ${runtimePath}`);
    }
  }
}
for (const forbiddenPath of ['/', '/login-links/{requestId}', '/login-links/{requestId}/review']) {
  if (runtimeRoutes[forbiddenPath]) {
    throw new Error(`Interactive backend route remains reachable: ${forbiddenPath}`);
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
grep -Fq -- '--project-directory "$repo_root"' tests/Acceptance/headless-platform-acceptance.sh \
  || fail 'headless acceptance must resolve every Compose path from the repository root'
grep -Fq 'bash tests/Acceptance/headless-platform-acceptance.sh' \
  .github/workflows/headless-platform-acceptance.yml \
  || fail 'the deployed headless acceptance workflow must run its canonical harness'
for acceptance_route in \
  '/api/v1/homes/${home_id}/billing' \
  '/api/v1/operator/billing/plans' \
  '/api/v1/catalog-contributions/${contribution_id}/proposal' \
  '/api/v1/catalog-admin/proposals/${product_proposal_id}/decision' \
  '/api/v1/catalog/products/${published_product_id}'; do
  grep -Fq "$acceptance_route" tests/Acceptance/headless-platform-acceptance.sh \
    || fail "headless acceptance is missing the business path: $acceptance_route"
done
grep -Fq "'billing.enforced' => false" src/Billing/Application/BillingService.php \
  || fail 'free-phase household billing must remain explicitly non-enforcing'
grep -Fq 'tool/materialize-openapi-contract.sh' tests/Acceptance/headless-platform-acceptance.sh \
  || fail 'headless acceptance must materialize and verify the pinned API contract'
grep -Fq 'context: tests/fixtures' tests/Acceptance/compose.headless-platform-acceptance.yaml \
  || fail 'the AI fixture must build from its isolated context instead of the test-excluding app context'
grep -Fq 'dockerfile: Dockerfile.ai-provider' tests/Acceptance/compose.headless-platform-acceptance.yaml \
  || fail 'the AI fixture must use its dedicated minimal image'
grep -Fq "@file_get_contents('http://127.0.0.1:8090/health')" \
  tests/Acceptance/compose.headless-platform-acceptance.yaml \
  || fail 'the AI fixture healthcheck must exercise its JSON router, not only an open TCP port'
grep -Fq "JSON_THROW_ON_ERROR" tests/Acceptance/compose.headless-platform-acceptance.yaml \
  || fail 'the AI fixture healthcheck must reject malformed non-JSON responses'
grep -Fq "=== ['status' => 'ready']" tests/Acceptance/compose.headless-platform-acceptance.yaml \
  || fail 'the AI fixture healthcheck must require its exact readiness document'
grep -Fq 'COPY ai-provider-router.php /fixture/router.php' tests/fixtures/Dockerfile.ai-provider \
  || fail 'the dedicated AI fixture image must contain its router'
grep -Fq '"tests/fixtures/**"' .github/workflows/headless-platform-acceptance.yml \
  || fail 'every deterministic AI fixture change must trigger deployed headless acceptance'
grep -Fq 'test -r /app/var/providentia.sqlite' tests/Acceptance/compose.headless-platform-acceptance.yaml \
  || fail 'the notification worker must expose a database-readiness healthcheck to Compose'
grep -Fq "header('Content-Length: ' . strlen(\$encoded));" tests/fixtures/ai-provider-router.php \
  || fail 'the deterministic AI fixture must frame its strict JSON response completely'
grep -Fq "header('X-Acceptance-Body-Sha256: ' . hash('sha256', \$encoded));" \
  tests/fixtures/ai-provider-router.php \
  || fail 'the deterministic AI fixture must expose only bounded response-integrity evidence'
grep -Fq "\$method === 'GET' && \$path === '/self-test'" tests/fixtures/ai-provider-router.php \
  || fail 'the deterministic AI fixture must expose its network-local framing self-test'
grep -Fq '"http://ai-fixture:8090/self-test"' tests/Acceptance/headless-platform-acceptance.sh \
  || fail 'headless acceptance must preflight fixture framing independently of request validation'
grep -Fq 'ProviderJsonDecoder::httpResponse' tests/Acceptance/headless-platform-acceptance.sh \
  || fail 'headless acceptance must exercise the production provider JSON decoder'
sed -n '/"http:\/\/ai-fixture:8090\/self-test"/,+5p' \
  tests/Acceptance/headless-platform-acceptance.sh | grep -Fq '            false,' \
  || fail 'the provider self-test stream must pass its context as fopen argument four'
grep -Fq 'json_error=%d json_error_code=%s expected_length=%s expected_sha256=%s' \
  tests/Acceptance/headless-platform-acceptance.sh \
  || fail 'AI fixture failures must emit bounded framing evidence without response contents'
grep -Fq 'preflight_ai_fixture' tests/Acceptance/headless-platform-acceptance.sh \
  || fail 'headless acceptance must distinguish provider transport JSON from structured output'
dockerfile_copy_line="$(grep -n '^COPY \. \.$' Dockerfile | cut -d: -f1)"
dockerfile_autoload_line="$(grep -nF 'composer dump-autoload --no-dev --classmap-authoritative --no-interaction' Dockerfile | cut -d: -f1)"
[[ -n "$dockerfile_copy_line" && -n "$dockerfile_autoload_line" \
    && "$dockerfile_autoload_line" -gt "$dockerfile_copy_line" ]] \
  || fail "source image must rebuild its authoritative classmap after copying application source"
for compose_file in compose.yaml compose.prebuilt.yaml compose.production.yaml; do
  grep -Fq 'MYSQL_PWD="$${MYSQL_PASSWORD}"' "$compose_file" \
    || fail "$compose_file must authenticate the application user in its MySQL health check"
  grep -Fq -- "--execute='SELECT 1'" "$compose_file" \
    || fail "$compose_file MySQL health check must execute a real query"
done
grep -Fq 'label=com.docker.compose.project=providentia' scripts/setup-development.sh \
  || fail "source setup must detect volumes whose matching secrets file is missing"
grep -Fq 'label=com.docker.compose.project=providentia-prebuilt' scripts/setup-prebuilt.sh \
  || fail "prebuilt setup must detect volumes whose matching secrets file is missing"
for setup_script in scripts/setup-development.sh scripts/setup-prebuilt.sh; do
  grep -Fq -- '--reset-data' "$setup_script" \
    || fail "$setup_script must expose an explicit local-data reset"
done
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

assert_no_matches "Catalog persistence reaches into Inventory-owned home products" \
  -ni --glob 'src/Catalog/Infrastructure/Doctrine/*.php' \
  'home_products|stock_movements|inventory_balances|stock_count'
grep -Fq 'implements CatalogContributionSourceReader' \
  src/Inventory/Infrastructure/Doctrine/DbalCatalogContributionSourceReader.php \
  || fail 'Inventory must implement the narrow Catalog contribution-source reader'
grep -Fq 'implements CatalogImportHomeProductGateway' \
  src/Inventory/Infrastructure/Doctrine/DbalCatalogImportHomeProductGateway.php \
  || fail 'Inventory must implement the narrow Catalog import-home-product gateway'
grep -Fq 'implements CatalogMergeHomeProductGateway' \
  src/Inventory/Infrastructure/Doctrine/DbalCatalogMergeHomeProductGateway.php \
  || fail 'Inventory must implement the narrow Catalog merge-home-product gateway'
grep -Fq 'implements CatalogHomeAccess' \
  src/Home/Infrastructure/Adapter/CatalogHomeAccessAdapter.php \
  || fail 'Home must implement the narrow Catalog access port'
grep -Fq 'implements CatalogAuditRecorder' \
  src/Home/Infrastructure/Adapter/CatalogAuditRecorderAdapter.php \
  || fail 'Home must implement the narrow Catalog audit port'
assert_no_matches "Catalog contribution images reuse AI private-media infrastructure" \
  -n --glob 'src/Catalog/**/*.php' \
  'Providentia\\AiIntegration\\|AI_MEDIA_ROOT|ai_media_assets'

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
