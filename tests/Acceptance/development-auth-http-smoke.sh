#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
port="${PROVIDENTIA_TEST_AUTH_HTTP_PORT:-18082}"
stdout_log="${repo_root}/var/development-auth-http-smoke.stdout.log"
stderr_log="${repo_root}/var/development-auth-http-smoke.stderr.log"
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

APP_ENV=development \
APP_DEBUG=1 \
PUBLIC_BASE_URL="http://127.0.0.1:${port}" \
AUTH_PASSWORD_LOGIN_ENABLED=1 \
EXPOSE_DEVELOPMENT_TOKENS=1 \
AUTH_TOKEN_PEPPER=acceptance-authentication-pepper-at-least-32-bytes \
NOTIFICATION_PAYLOAD_KEK=Y2ktbm90aWZpY2F0aW9uLWtleS0zMi1ieXRlcy1vayE= \
php -S "127.0.0.1:${port}" -t "${repo_root}/public" "${repo_root}/public/index.php" \
    >"$stdout_log" 2>"$stderr_log" &
server_pid=$!

ready=0
for attempt in $(seq 1 30); do
    if curl --fail-with-body --silent --show-error \
        "http://127.0.0.1:${port}/health/ready" >/dev/null; then
        ready=1
        break
    fi
    sleep 0.2
done
if [[ "$ready" != '1' ]]; then
    printf 'The development HTTP server did not become ready.\n' >&2
    cat "$stderr_log" >&2
    exit 1
fi

root_body="${repo_root}/var/development-auth-root.json"
root_status="$(curl --silent --show-error --output "$root_body" \
    --write-out '%{http_code}' "http://127.0.0.1:${port}/")"
if [[ "$root_status" != '404' ]]; then
    printf 'The headless root must be unavailable (HTTP %s).\n' "$root_status" >&2
    cat "$root_body" >&2
    cat "$stderr_log" >&2
    exit 1
fi
jq -e '
    .status == 404
    and .title == "Not Found"
    and .instance == "/"
    and (.requestId | type == "string" and length == 32)
' <"$root_body" >/dev/null
if grep -Eiq '<(!doctype|html|form|script)' "$root_body"; then
    printf 'The headless root returned an interactive document.\n' >&2
    cat "$root_body" >&2
    exit 1
fi

login_response="$(curl --silent --show-error --write-out $'\n%{http_code}' \
    -H 'Content-Type: application/json' \
    -X POST "http://127.0.0.1:${port}/api/v1/auth/login" \
    --data '{"email":"missing@example.test","password":"not-the-right-password-1","deviceId":"01912345-6789-7abc-8def-0123456789ab","deviceName":"Acceptance","platform":"linux","transport":"native"}')"
login_status="${login_response##*$'\n'}"
login_body="${login_response%$'\n'*}"
if [[ "$login_status" != '401' ]]; then
    printf 'A first development login should reach authentication and return 401, got HTTP %s.\n' \
        "$login_status" >&2
    printf '%s\n' "$login_body" >&2
    cat "$stderr_log" >&2
    exit 1
fi

jq -e '.status == 401 and .title == "Authentication failed"' <<<"$login_body" >/dev/null

email='development-auth-smoke@example.test'
password='development-auth-smoke-password-1'
registration_response="$(curl --silent --show-error --write-out $'\n%{http_code}' \
    -H 'Content-Type: application/json' \
    -X POST "http://127.0.0.1:${port}/api/v1/auth/register" \
    --data "$(jq -n --arg email "$email" --arg password "$password" \
        '{email:$email,password:$password,displayName:"Authentication smoke"}')")"
registration_status="${registration_response##*$'\n'}"
registration_body="${registration_response%$'\n'*}"
if [[ "$registration_status" != '202' ]]; then
    printf 'Development registration failed (HTTP %s).\n%s\n' \
        "$registration_status" "$registration_body" >&2
    cat "$stderr_log" >&2
    exit 1
fi
verification_token="$(jq -er '.developmentVerificationToken' <<<"$registration_body")"

verification_status="$(curl --silent --show-error --output "${repo_root}/var/development-auth-verify.json" \
    --write-out '%{http_code}' -H 'Content-Type: application/json' \
    -X POST "http://127.0.0.1:${port}/api/v1/auth/verify-email" \
    --data "$(jq -n --arg token "$verification_token" '{token:$token}')")"
if [[ "$verification_status" != '204' ]]; then
    printf 'Development email verification failed (HTTP %s).\n' "$verification_status" >&2
    cat "${repo_root}/var/development-auth-verify.json" >&2
    cat "$stderr_log" >&2
    exit 1
fi

session_response="$(curl --silent --show-error --write-out $'\n%{http_code}' \
    -H 'Content-Type: application/json' \
    -X POST "http://127.0.0.1:${port}/api/v1/auth/login" \
    --data "$(jq -n --arg email "$email" --arg password "$password" \
        '{email:$email,password:$password,deviceId:"01912345-6789-7abc-8def-0123456789ab",deviceName:"Acceptance",platform:"linux",transport:"native"}')")"
session_status="${session_response##*$'\n'}"
session_body="${session_response%$'\n'*}"
if [[ "$session_status" != '200' ]]; then
    printf 'Verified development login failed (HTTP %s).\n%s\n' \
        "$session_status" "$session_body" >&2
    cat "$stderr_log" >&2
    exit 1
fi
jq -e '
    .transport == "native"
    and (.accessToken | type == "string" and length >= 40)
    and (.refreshToken | type == "string" and length >= 40)
    and (.sessionId | type == "string")
    and (.userId | type == "string")
' <<<"$session_body" >/dev/null

printf 'Development authentication HTTP smoke passed.\n'
