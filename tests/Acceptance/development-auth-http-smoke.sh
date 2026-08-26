#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
port="${PROVIDENTIA_TEST_AUTH_HTTP_PORT:-18082}"
base_url="http://127.0.0.1:${port}"
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
EXPOSE_DEVELOPMENT_TOKENS=1 \
AUTH_TOKEN_PEPPER=acceptance-authentication-pepper-at-least-32-bytes \
NOTIFICATION_PAYLOAD_KEK=Y2ktbm90aWZpY2F0aW9uLWtleS0zMi1ieXRlcy1vayE= \
php -S "127.0.0.1:${port}" -t "${repo_root}/public" "${repo_root}/public/index.php" \
    >"$stdout_log" 2>"$stderr_log" &
server_pid=$!

ready=0
for attempt in $(seq 1 30); do
    if curl --fail-with-body --silent --show-error \
        "${base_url}/health/ready" >/dev/null; then
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

fail() {
    printf '%s\n' "$1" >&2
    if [[ -n "${reply_body:-}" ]]; then
        printf '%s\n' "$reply_body" >&2
    fi
    cat "$stderr_log" >&2
    exit 1
}

post_json() {
    local response
    response="$(curl --silent --show-error --write-out $'\n%{http_code}' \
        -H 'Content-Type: application/json' \
        -X POST "${base_url}$1" --data "$2")"
    reply_status="${response##*$'\n'}"
    reply_body="${response%$'\n'*}"
}

get_bearer() {
    local response
    response="$(curl --silent --show-error --write-out $'\n%{http_code}' \
        -H "Authorization: Bearer $2" "${base_url}$1")"
    reply_status="${response##*$'\n'}"
    reply_body="${response%$'\n'*}"
}

uuid4() {
    python3 -c 'import uuid; print(uuid.uuid4())'
}

url_token() {
    python3 -c 'import secrets; print(secrets.token_urlsafe(32))'
}

s256() {
    python3 -c 'import base64, hashlib, sys
digest = hashlib.sha256(sys.argv[1].encode("ascii")).digest()
print(base64.urlsafe_b64encode(digest).rstrip(b"=").decode("ascii"))' "$1"
}

start_body() {
    jq -n \
        --arg requestId "$1" \
        --arg email "$2" \
        --arg pollChallenge "$3" \
        --arg codeChallenge "$4" \
        --arg state "$5" \
        --arg installationId "$6" \
        '{
            requestId: $requestId,
            email: $email,
            applicationKind: "homeowner",
            pollChallenge: $pollChallenge,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: "S256",
            state: $state,
            installationId: $installationId,
            deviceName: "Acceptance",
            platform: "linux",
            transport: "native"
        }'
}

root_body="${repo_root}/var/development-auth-root.json"
root_status="$(curl --silent --show-error --output "$root_body" \
    --write-out '%{http_code}' "${base_url}/")"
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

email='development-auth-smoke@example.test'
request_id="$(uuid4)"
installation_id="$(uuid4)"
poll_token="$(url_token)"
code_verifier="$(url_token)"
state="$(url_token)"

post_json '/api/v1/auth/login-links' \
    "$(start_body "$request_id" "$email" "$(s256 "$poll_token")" \
        "$(s256 "$code_verifier")" "$state" "$installation_id")"
if [[ "$reply_status" != '202' ]]; then
    fail "Starting the login link failed (HTTP ${reply_status})."
fi
jq -e --arg requestId "$request_id" '
    .accepted == true
    and .requestId == $requestId
    and (.pollIntervalSeconds | type == "number")
    and (.developmentApprovalToken | type == "string" and (length >= 40 and length <= 128))
' <<<"$reply_body" >/dev/null
approval_token="$(jq -er '.developmentApprovalToken' <<<"$reply_body")"

post_json "/api/v1/auth/login-links/${request_id}/proof" \
    "$(jq -n --arg token "$approval_token" \
        '{applicationKind: "homeowner", approvalToken: $token}')"
if [[ "$reply_status" != '200' ]]; then
    fail "The login-link proof failed (HTTP ${reply_status})."
fi
jq -e --arg requestId "$request_id" '
    .valid == true and .requestId == $requestId and .applicationKind == "homeowner"
' <<<"$reply_body" >/dev/null

post_json "/api/v1/auth/login-links/${request_id}/review" \
    "$(jq -n --arg token "$approval_token" \
        '{applicationKind: "homeowner", approvalToken: $token}')"
if [[ "$reply_status" != '200' ]]; then
    fail "The login-link review failed (HTTP ${reply_status})."
fi
jq -e --arg requestId "$request_id" '
    .requestId == $requestId and .deviceName == "Acceptance" and .platform == "linux"
' <<<"$reply_body" >/dev/null

post_json "/api/v1/auth/login-links/${request_id}/decision" \
    "$(jq -n --arg token "$approval_token" \
        '{applicationKind: "homeowner", approvalToken: $token, decision: "approve"}')"
if [[ "$reply_status" != '202' ]]; then
    fail "The login-link approval failed (HTTP ${reply_status})."
fi
jq -e --arg requestId "$request_id" '
    .requestId == $requestId and .status == "received"
' <<<"$reply_body" >/dev/null

post_json "/api/v1/auth/login-links/${request_id}/status" \
    "$(jq -n --arg pollToken "$poll_token" '{pollToken: $pollToken}')"
if [[ "$reply_status" != '200' ]]; then
    fail "The login-link status poll failed (HTTP ${reply_status})."
fi
jq -e --arg requestId "$request_id" '
    .requestId == $requestId and .status == "approved" and (.approvedAt | type == "string")
' <<<"$reply_body" >/dev/null

post_json "/api/v1/auth/login-links/${request_id}/exchange" \
    "$(jq -n --arg pollToken "$poll_token" --arg codeVerifier "$code_verifier" \
        --arg state "$state" \
        '{pollToken: $pollToken, codeVerifier: $codeVerifier, state: $state}')"
if [[ "$reply_status" != '200' ]]; then
    fail "The login-link exchange failed (HTTP ${reply_status})."
fi
jq -e '
    .transport == "native"
    and (.accessToken | type == "string" and length >= 40)
    and (.refreshToken | type == "string" and length >= 40)
    and (.csrfToken | type == "string" and length >= 40)
    and (.idleExpiresAt | type == "string")
    and (.sessionId | type == "string")
    and (.userId | type == "string")
' <<<"$reply_body" >/dev/null
access_token="$(jq -er '.accessToken' <<<"$reply_body")"
refresh_token="$(jq -er '.refreshToken' <<<"$reply_body")"
session_id="$(jq -er '.sessionId' <<<"$reply_body")"
user_id="$(jq -er '.userId' <<<"$reply_body")"

get_bearer '/api/v1/me' "$access_token"
if [[ "$reply_status" != '200' ]]; then
    fail "Reading the authenticated identity failed (HTTP ${reply_status})."
fi
jq -e --arg userId "$user_id" --arg email "$email" --arg sessionId "$session_id" '
    .userId == $userId
    and .email == $email
    and .emailVerified == true
    and .currentSession.id == $sessionId
' <<<"$reply_body" >/dev/null

get_bearer '/api/v1/auth/sessions' "$access_token"
if [[ "$reply_status" != '200' ]]; then
    fail "Listing device sessions failed (HTTP ${reply_status})."
fi
jq -e --arg sessionId "$session_id" '
    [.data[] | select(.id == $sessionId and .current == true and .deviceName == "Acceptance")]
        | length == 1
' <<<"$reply_body" >/dev/null

post_json '/api/v1/auth/refresh' \
    "$(jq -n --arg refreshToken "$refresh_token" '{refreshToken: $refreshToken}')"
if [[ "$reply_status" != '200' ]]; then
    fail "Rotating the session credentials failed (HTTP ${reply_status})."
fi
jq -e --arg sessionId "$session_id" --arg userId "$user_id" --arg old "$refresh_token" '
    .sessionId == $sessionId
    and .userId == $userId
    and (.accessToken | type == "string" and length >= 40)
    and (.refreshToken | type == "string" and length >= 40 and . != $old)
    and (.csrfToken | type == "string" and length >= 40)
' <<<"$reply_body" >/dev/null
rotated_refresh_token="$(jq -er '.refreshToken' <<<"$reply_body")"

post_json '/api/v1/auth/logout' \
    "$(jq -n --arg refreshToken "$rotated_refresh_token" '{refreshToken: $refreshToken}')"
if [[ "$reply_status" != '204' ]]; then
    fail "Logging out with the rotated credential failed (HTTP ${reply_status})."
fi

for removed_path in \
    /api/v1/auth/register \
    /api/v1/auth/login \
    /api/v1/auth/password-reset/request \
    /api/v1/auth/verify-email; do
    removed_body="${repo_root}/var/development-auth-removed.json"
    removed_reply="$(curl --silent --show-error --output "$removed_body" \
        --write-out '%{http_code} %{content_type}' \
        -H 'Content-Type: application/json' \
        -X POST "${base_url}${removed_path}" --data '{}')"
    removed_status="${removed_reply%% *}"
    removed_type="${removed_reply#* }"
    if [[ "$removed_status" != '404' ]]; then
        printf 'The removed route %s must return 404, got HTTP %s.\n' \
            "$removed_path" "$removed_status" >&2
        cat "$removed_body" >&2
        cat "$stderr_log" >&2
        exit 1
    fi
    case "$removed_type" in
        application/problem+json*) ;;
        *)
            printf 'The removed route %s must answer with problem+json, got %s.\n' \
                "$removed_path" "$removed_type" >&2
            cat "$removed_body" >&2
            exit 1
            ;;
    esac
    jq -e --arg path "$removed_path" '
        .status == 404 and .title == "Not Found" and .instance == $path
    ' <"$removed_body" >/dev/null
done

second_request_id="$(uuid4)"
second_installation_id="$(uuid4)"
second_poll_token="$(url_token)"
second_code_verifier="$(url_token)"
second_state="$(url_token)"

post_json '/api/v1/auth/login-links' \
    "$(start_body "$second_request_id" "$email" "$(s256 "$second_poll_token")" \
        "$(s256 "$second_code_verifier")" "$second_state" "$second_installation_id")"
if [[ "$reply_status" != '202' ]]; then
    fail "Starting the second login link failed (HTTP ${reply_status})."
fi
second_approval_token="$(jq -er '.developmentApprovalToken' <<<"$reply_body")"

post_json "/api/v1/auth/login-links/${second_request_id}/decision" \
    "$(jq -n --arg token "$second_approval_token" \
        '{applicationKind: "homeowner", approvalToken: $token, decision: "approve"}')"
if [[ "$reply_status" != '202' ]]; then
    fail "Approving the second login link failed (HTTP ${reply_status})."
fi

post_json "/api/v1/auth/login-links/${second_request_id}/exchange" \
    "$(jq -n --arg pollToken "$second_poll_token" \
        --arg codeVerifier "$second_code_verifier" --arg state "$second_state" \
        '{pollToken: $pollToken, codeVerifier: $codeVerifier, state: $state}')"
if [[ "$reply_status" != '200' ]]; then
    fail "The second login-link exchange failed (HTTP ${reply_status})."
fi
jq -e --arg userId "$user_id" '
    .userId == $userId and (.accessToken | type == "string" and length >= 40)
' <<<"$reply_body" >/dev/null

printf 'Development authentication HTTP smoke passed.\n'
