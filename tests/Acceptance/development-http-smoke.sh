#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
port="${PROVIDENTIA_TEST_HTTP_PORT:-18081}"
stdout_log="${repo_root}/var/development-http-smoke.stdout.log"
stderr_log="${repo_root}/var/development-http-smoke.stderr.log"
server_pid=''

cleanup() {
    if [[ -n "$server_pid" ]]; then
        kill "$server_pid" >/dev/null 2>&1 || true
        wait "$server_pid" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

mkdir -p "${repo_root}/var"
: >"$stdout_log"
: >"$stderr_log"

php -S "127.0.0.1:${port}" "${repo_root}/tests/fixtures/http/stderr-json-logger.php" \
    >"$stdout_log" 2>"$stderr_log" &
server_pid=$!

response=''
for attempt in $(seq 1 30); do
    if response="$(curl --fail-with-body --silent --show-error "http://127.0.0.1:${port}/")"; then
        break
    fi
    sleep 0.2
done

[[ "$response" == '{"status":"ok"}' ]]
grep -Fq '"message":"Development HTTP logger smoke."' "$stderr_log"
grep -Fq '"authorization":"[redacted]"' "$stderr_log"
if grep -Fq 'must-not-leak' "$stderr_log"; then
    printf 'The development HTTP logger leaked authorization data.\n' >&2
    exit 1
fi
if grep -Fq 'Fatal error' "$stderr_log"; then
    printf 'The development HTTP logger triggered a fatal error.\n' >&2
    exit 1
fi

printf 'Development HTTP logging smoke passed.\n'
