#!/usr/bin/env bash
set -Eeuo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
client="${1:?Pass the pinned client checkout path}"
fixture="$(mktemp)"
pid=''
cleanup() {
  if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  rm -f "$fixture" "$fixture.state.json" "$fixture.device.sqlite"*
}
trap cleanup EXIT
export APP_ENV=test PROVIDENTIA_STEP2_CONFORMANCE=1 QUEUE_REQUIRED=0
export AUTH_TOKEN_PEPPER=step2-isolated-authentication-pepper-at-least-32-bytes
export AI_ALLOW_PRIVATE_NETWORK_ENDPOINTS=1 AI_ALLOW_PRIVATE_ENDPOINTS=1
export AI_OLLAMA_ENDPOINT=http://127.0.0.1:19999
export AI_COMPATIBLE_ENDPOINT=http://127.0.0.1:19998
export PROVIDENTIA_STEP2_FIXTURE="$fixture" PROVIDENTIA_STEP2_URL=http://127.0.0.1:18083
cd "$root"
php bin/doctrine-migrations migrations:migrate --no-interaction
php tests/fixtures/step2-http.php "$fixture"
cp tests/Acceptance/step2-live-http.dart "$client/test/integration/step2_live_backend_test.dart"
for phase in empty write read unavailable; do
  export PROVIDENTIA_STEP2_PHASE="$phase"
  export AI_SERVER_PROXY_ENABLED=1
  export AI_CREDENTIAL_KEK=Y2ktbm90aWZpY2F0aW9uLWtleS0zMi1ieXRlcy1vayE=
  if [[ "$phase" == empty || "$phase" == unavailable ]]; then
    export AI_SERVER_PROXY_ENABLED=0 AI_CREDENTIAL_KEK=''
  fi
  php -S 127.0.0.1:18083 -t public public/index.php >"/tmp/step2-$phase-http.log" 2>&1 &
  pid=$!
  ready=0
  for attempt in $(seq 1 50); do
    if curl --fail --silent http://127.0.0.1:18083/health/live >/dev/null; then ready=1; break; fi
    sleep 0.1
  done
  test "$ready" = 1
  (cd "$client" && flutter test test/integration/step2_live_backend_test.dart --reporter json) \
    >"/tmp/step2-$phase-dart.jsonl" 2>&1
  kill "$pid"; wait "$pid" 2>/dev/null || true; pid=''
done
printf 'Step 2 live HTTP, saved-state restart, disabled-adapter and Dart/Drift conformance passed.\n'
