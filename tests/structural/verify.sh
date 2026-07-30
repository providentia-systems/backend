#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

modules=(
  SharedKernel Identity Home Catalog Inventory Purchasing Shopping
  Synchronization AiIntegration Administration Reporting PublicSite
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

for executable in bin/doctrine-migrations bin/providentia \
  infrastructure/compose/entrypoint.sh tool/generate-dart-client.sh \
  tool/check-composer-licenses.php tests/structural/verify.sh \
  scripts/setup-development.sh scripts/reset-development.sh; do
  test -x "$executable" || fail "$executable must be executable"
done

for shell_script in infrastructure/compose/entrypoint.sh tool/generate-dart-client.sh \
  scripts/setup-development.sh scripts/reset-development.sh; do
  bash -n "$shell_script" || fail "$shell_script has invalid shell syntax"
done

node <<'NODE'
const fs = require('node:fs');
const crypto = require('node:crypto');
const path = require('node:path');
const contract = JSON.parse(fs.readFileSync('contracts/openapi/providentia-v1.json', 'utf8'));
const routeSource = fs.readFileSync('config/routes.php', 'utf8');
const expected = {
  '/health/live': 'getLiveness',
  '/health/ready': 'getReadiness',
  '/api/v1/system/info': 'getSystemInfo',
  '/metrics': 'getMetrics',
  '/api/v1/auth/register': 'registerAccount',
  '/api/v1/auth/login': 'login',
  '/api/v1/homes': 'listHomes',
  '/api/v1/homes/{homeId}/ownership-transfer': 'transferHomeOwnership',
  '/api/v1/catalog/products': 'searchCatalogProducts',
  '/api/v1/homes/{homeId}/sync/push': 'pushHomeSynchronization',
  '/api/v1/homes/{homeId}/sync/pull': 'pullHomeSynchronization',
  '/api/v1/homes/{homeId}/sync/bootstrap': 'bootstrapHomeSynchronization',
};
for (const [path, operationId] of Object.entries(expected)) {
  const operations = contract.paths?.[path] ?? {};
  const actual = ['get', 'post', 'put', 'patch', 'delete']
    .map((method) => operations[method]?.operationId)
    .find(Boolean);
  if (actual !== operationId) {
    throw new Error(`Missing ${operationId} on ${path}`);
  }
}
for (const schema of [
  'HealthStatus', 'ReadinessStatus', 'SystemInfo', 'ProblemDetails',
  'RegisterRequest', 'Home', 'HomeMembership', 'SyncPushRequest',
  'SyncPrivateNotePayload', 'SyncHomePreferencePayload', 'SyncPushResponse',
  'SyncPullResponse', 'SyncBootstrapResponse', 'ConsumptionEstimate',
  'ShoppingSuggestion', 'SuggestionExplanation', 'PriceComparison',
  'StockPreference', 'SuggestionBacktest', 'HomeReport',
]) {
  if (!contract.components?.schemas?.[schema]) {
    throw new Error(`Missing schema ${schema}`);
  }
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
for (const [runtimePath, methods] of Object.entries(runtimeRoutes)) {
  if (runtimePath === '/') continue;
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
grep -Fq "Production requires two independent, non-placeholder" config/autoload/global.php \
  || fail "production placeholder secrets do not fail closed"
grep -Fq "Production MAIL_DSN must use smtps://" config/autoload/global.php \
  || fail "production SMTP does not require authenticated TLS"
grep -Fq "'verify_peer' => true" src/Identity/Infrastructure/Notification/SmtpAccountNotificationSender.php \
  || fail "SMTPS peer verification is not explicit"
grep -Fq "'retain_until' => null" src/Synchronization/Infrastructure/Doctrine/DbalSyncStore.php \
  || fail "tombstone retention was invented before the offline-window decision"

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
  -ni --glob 'src/Catalog/Infrastructure/Doctrine/*.php' \
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

assert_no_matches "change log uses a potentially reserved physical cursor column" \
  -n -F --glob 'migrations/*.php' --glob 'src/Synchronization/**/*.php' \
  -e 'MAX(cursor)' -e 'SELECT cursor,' -e 'AND cursor >' \
  -e 'ORDER BY cursor' -e "addColumn('cursor'"

printf 'Structural, namespace, module-boundary, token, migration, and contract checks passed.\n'
