#!/usr/bin/env bash
set -Eeuo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
temporary="$(mktemp -d)"
fixture="$temporary/fixture.json"
port="${PROVIDENTIA_RESPONSE_HTTP_PORT:-18084}"
pid=''
client=''
client_test=''
if [[ -n "${1:-}" ]]; then
  client="$(cd -- "$1" && pwd)"
  command -v flutter >/dev/null
  if [[ -e "$client/test/integration/runtime_receipt_live_test.dart" ]]; then
    echo 'Refusing to replace an existing Client integration test.' >&2
    exit 2
  fi
fi
cleanup() {
  if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  if [[ -n "$client_test" ]]; then rm -f "$client_test"; fi
  rm -rf "$temporary"
}
trap cleanup EXIT
if [[ -n "${DATABASE_URL:-}" && "${PROVIDENTIA_RESPONSE_ISOLATED_DATABASE:-}" != 1 ]]; then
  echo 'An external test database requires PROVIDENTIA_RESPONSE_ISOLATED_DATABASE=1.' >&2
  exit 2
fi
export DATABASE_URL="${DATABASE_URL:-sqlite:///$temporary/response.sqlite}"
export APP_ENV=test PROVIDENTIA_STEP2_CONFORMANCE=1 QUEUE_REQUIRED=0
export AUTH_TOKEN_PEPPER=runtime-response-isolated-pepper-at-least-32-bytes
export PROVIDENTIA_RESPONSE_FIXTURE="$fixture" PROVIDENTIA_RESPONSE_URL="http://127.0.0.1:$port"
cd "$root"
bash tool/materialize-openapi-contract.sh
if [[ -z "$client" ]]; then python3 tests/Acceptance/runtime-response-contracts.py self-test; fi
php -r '
require "vendor/autoload.php";
$container = require "config/container.php";
$db = $container->get(Doctrine\DBAL\Connection::class);
if ($db->createSchemaManager()->tablesExist(["users"]) && (int) $db->fetchOne("SELECT COUNT(*) FROM users") !== 0) {
    throw new RuntimeException("Refusing to migrate or seed a nonempty account database.");
}
'
php bin/doctrine-migrations migrations:migrate --no-interaction
php tests/fixtures/runtime-response-http.php "$fixture"
phases=(write read boundary)
if [[ -n "$client" ]]; then phases=(receipt); fi
for phase in "${phases[@]}"; do
  php -S "127.0.0.1:$port" -t public public/index.php >"var/runtime-response-$phase-http.log" 2>&1 &
  pid=$!
  ready=0
  for attempt in $(seq 1 50); do
    if ! kill -0 "$pid" 2>/dev/null; then
      echo 'The owned test server exited before becoming ready.' >&2
      cat "var/runtime-response-$phase-http.log" >&2
      exit 1
    fi
    if grep -Fq "Development Server (http://127.0.0.1:$port) started" "var/runtime-response-$phase-http.log" \
      && curl --fail --silent "http://127.0.0.1:$port/health/live" >/dev/null; then
      kill -0 "$pid" 2>/dev/null || { echo 'The owned test server failed to bind.' >&2; exit 1; }
      ready=1
      break
    fi
    sleep 0.1
  done
  test "$ready" = 1
  if [[ "$phase" == receipt ]]; then
    client_test="$client/test/integration/runtime_receipt_live_test.dart"
    cp tests/Acceptance/runtime-receipt-live.dart "$client_test"
    (cd "$client" && flutter test --no-pub test/integration/runtime_receipt_live_test.dart --reporter expanded)
  else
    python3 tests/Acceptance/runtime-response-contracts.py "$phase"
  fi
  kill "$pid"; wait "$pid" 2>/dev/null || true; pid=''
done
if [[ -n "$client" ]]; then
  printf 'Live PHP-to-Dart receipt recovery, database reopen and conflict checks passed.\n'
else
  printf 'Runtime HTTP response contracts, replay and server restart passed.\n'
fi
