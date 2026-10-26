#!/usr/bin/env bash
set -Eeuo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
admin="$(cd -- "${1:?Pass the pinned Admin checkout}" && pwd)"
temporary="$(mktemp -d)"
fixture="$temporary/fixture.json"
port="${PROVIDENTIA_ADMIN_HTTP_PORT:-18085}"
pid=''
target="$admin/test/integration/runtime_admin_live_test.dart"
if [[ -e "$target" ]]; then
  echo 'Refusing to overwrite an Admin integration test.' >&2
  exit 2
fi
cleanup() {
  if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  rm -f "$target"
  rm -rf "$temporary"
}
trap cleanup EXIT
# This wrapper never accepts an ambient database connection.
export DATABASE_URL="sqlite:///$temporary/admin.sqlite"
export APP_ENV=test PROVIDENTIA_STEP2_CONFORMANCE=1 QUEUE_REQUIRED=0
export AUTH_TOKEN_PEPPER=runtime-admin-isolated-pepper-at-least-32-bytes
export PROVIDENTIA_RESPONSE_FIXTURE="$fixture" PROVIDENTIA_RESPONSE_URL="http://127.0.0.1:$port"
cd "$root"
php bin/doctrine-migrations migrations:migrate --no-interaction
php tests/fixtures/runtime-response-http.php "$fixture"
mkdir -p "$admin/test/integration"
cp tests/Acceptance/runtime-admin-live.dart "$target"
php -S "127.0.0.1:$port" -t public public/index.php >var/runtime-admin-http.log 2>&1 &
pid=$!
ready=0
for attempt in $(seq 1 50); do
  kill -0 "$pid" 2>/dev/null || { echo 'Owned Admin fixture server exited.' >&2; exit 1; }
  if grep -Fq "Development Server (http://127.0.0.1:$port) started" var/runtime-admin-http.log \
    && curl --fail --silent "http://127.0.0.1:$port/health/live" >/dev/null; then
    ready=1
    break
  fi
  sleep 0.1
done
test "$ready" = 1
(cd "$admin" && flutter test --no-pub test/integration/runtime_admin_live_test.dart --reporter expanded)
printf 'Native Admin production transport and revision-bound records acceptance passed.\n'
