#!/usr/bin/env bash
set -Eeuo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
client="${1:?Pass the pinned household client checkout}"
fixture="$(mktemp)"
pid=''
cleanup() {
  if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  rm -f "$fixture" "$fixture.state.json"
}
trap cleanup EXIT
export APP_ENV=test PROVIDENTIA_STEP2_CONFORMANCE=1 QUEUE_REQUIRED=0
export AUTH_TOKEN_PEPPER=step3-isolated-authentication-pepper-at-least-32-bytes
export DATA_EXPORT_ROOT="$RUNNER_TEMP/step3-private-exports"
export PROVIDENTIA_STEP2_FIXTURE="$fixture" PROVIDENTIA_STEP2_URL=http://127.0.0.1:18083
cd "$root"
php bin/doctrine-migrations migrations:migrate --no-interaction
php tests/fixtures/step2-http.php "$fixture"
cp tests/Acceptance/step3-data-live-http.dart "$client/test/integration/step3_data_live_backend_test.dart"
for phase in request retrieve; do
  export PROVIDENTIA_STEP3_PHASE="$phase"
  if [[ "$phase" == retrieve ]]; then
    php bin/providentia data-governance:process --once
    php bin/providentia data-governance:process --once
  fi
  php -S 127.0.0.1:18083 -t public public/index.php >/tmp/step3-private-http.log 2>&1 &
  pid=$!
  ready=0
  for attempt in $(seq 1 50); do
    if curl --fail --silent http://127.0.0.1:18083/health/live >/dev/null; then ready=1; break; fi
    sleep 0.1
  done
  test "$ready" = 1
  (cd "$client" && flutter test test/integration/step3_data_live_backend_test.dart --reporter expanded) >"/tmp/step3-data-$phase.log" 2>&1
  kill "$pid"; wait "$pid" 2>/dev/null || true; pid=''
done
printf 'Step 3 real export request, encrypted worker, restarted API, production Dart retrieval, one-use race, cancellation and authorization passed.\n'
