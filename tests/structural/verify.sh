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
  --glob '!docs/phase0/**' \
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
  tool/check-composer-licenses.php tests/structural/verify.sh; do
  test -x "$executable" || fail "$executable must be executable"
done

node <<'NODE'
const fs = require('node:fs');
const crypto = require('node:crypto');
const path = require('node:path');
const contract = JSON.parse(fs.readFileSync('contracts/openapi/providentia-v1.json', 'utf8'));
const expected = {
  '/health/live': 'getLiveness',
  '/health/ready': 'getReadiness',
  '/api/v1/system/info': 'getSystemInfo',
  '/metrics': 'getMetrics',
};
for (const [path, operationId] of Object.entries(expected)) {
  if (contract.paths?.[path]?.get?.operationId !== operationId) {
    throw new Error(`Missing ${operationId} on ${path}`);
  }
}
for (const schema of ['HealthStatus', 'ReadinessStatus', 'SystemInfo', 'ProblemDetails']) {
  if (!contract.components?.schemas?.[schema]) {
    throw new Error(`Missing schema ${schema}`);
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

assert_no_matches "Domain layer imports infrastructure or transport code" \
  -n --glob 'src/*/Domain/**/*.php' \
  'Doctrine\\\\|Laminas\\\\|Mezzio\\\\|Enqueue\\\\|Interop\\\\Queue|Psr\\\\Http'

assert_no_matches "Application layer imports framework, persistence, queue transport, or HTTP code" \
  -n --glob 'src/*/Application/**/*.php' \
  'Doctrine\\\\|Laminas\\\\|Mezzio\\\\|Enqueue\\\\|Interop\\\\Queue|Psr\\\\Http'

assert_no_matches "HTTP layer imports persistence or queue implementation code" \
  -n --glob 'src/*/Http/**/*.php' \
  'Doctrine\\\\|Enqueue\\\\|Interop\\\\Queue|Redis\\\\|use Redis;'

assert_no_matches "A non-composition layer imports module Infrastructure" \
  -n --glob 'src/*/{Domain,Application,Http}/**/*.php' \
  'Providentia\\\\[A-Za-z]+\\\\Infrastructure\\\\'

assert_no_matches "Migration contains a known non-portable SQL construct" \
  -n -F --glob 'migrations/*.php' \
  -e 'ENUM(' -e 'JSON_EXTRACT' -e 'ON DUPLICATE' -e 'UNSIGNED BIGINT' \
  -e 'COLLATE utf8' -e 'ENGINE='

printf 'Structural, namespace, module-boundary, token, migration, and contract checks passed.\n'
