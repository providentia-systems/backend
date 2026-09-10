#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
port="${PROVIDENTIA_TEST_AUTH_HTTP_PORT:-18082}"
base_url="http://127.0.0.1:${port}"
stdout_log="${repo_root}/var/development-auth-http-smoke.stdout.log"
stderr_log="${repo_root}/var/development-auth-http-smoke.stderr.log"
server_pid=''
smtp_pid=''
email="development-auth-smoke-$(date +%s)-$$@example.test"
smtp_dir="$(mktemp -d)"
chmod 700 "$smtp_dir"

cleanup() {
    if [[ -n "$smtp_pid" ]]; then
        kill "$smtp_pid" >/dev/null 2>&1 || true
        wait "$smtp_pid" >/dev/null 2>&1 || true
    fi
    rm -rf "$smtp_dir"
    if [[ -n "$server_pid" ]]; then
        kill "$server_pid" >/dev/null 2>&1 || true
        wait "$server_pid" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

mkdir -p "${repo_root}/var"
: >"$stdout_log"
: >"$stderr_log"

python3 "${repo_root}/tests/fixtures/email-code-smtp.py" "$smtp_dir" "$email" &
smtp_pid=$!
for attempt in $(seq 1 50); do
    [[ -s "$smtp_dir/port" ]] && break
    sleep 0.1
done
[[ -s "$smtp_dir/port" ]] || { printf 'The local SMTP fixture did not start.\n' >&2; exit 1; }
export APP_ENV=development APP_DEBUG=1
export AUTH_TOKEN_PEPPER=acceptance-authentication-pepper-at-least-32-bytes
export NOTIFICATION_PAYLOAD_KEK=Y2ktbm90aWZpY2F0aW9uLWtleS0zMi1ieXRlcy1vayE=
export MAIL_DSN="smtp://127.0.0.1:$(cat "$smtp_dir/port")"
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
        jq -c '{status,title,detail}' <<<"$reply_body" >&2 || true
    fi
    cat "$stderr_log" >&2
    exit 1
}

post_json() {
    local response
    local -a headers=(-H 'Content-Type: application/json')
    if [[ -n "${3:-}" ]]; then
        headers+=(-H "Authorization: Bearer $3")
    fi
    response="$(curl --silent --show-error --write-out $'\n%{http_code}' \
        "${headers[@]}" \
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

installation_id="$(uuid4)"
start_payload="$(jq -cn --arg email "$email" --arg installationId "$installation_id" '
    {email:$email,applicationKind:"homeowner",installationId:$installationId,
     deviceName:"Acceptance",platform:"linux",transport:"native"}')"
post_json '/api/v1/auth/email-codes' "$start_payload"
[[ "$reply_status" == '202' ]] || fail "Requesting an email code failed (HTTP ${reply_status})."
jq -e '
    (.challengeId | type == "string") and (.bindingToken | type == "string" and length >= 40)
    and .resendAfterSeconds == 60 and (has("code") | not) and (has("accessToken") | not)
' <<<"$reply_body" >/dev/null
challenge_id="$(jq -er '.challengeId' <<<"$reply_body")"
binding_token="$(jq -er '.bindingToken' <<<"$reply_body")"
post_json '/api/v1/auth/email-codes' "$start_payload"
[[ "$reply_status" == '429' ]] || fail 'The immediate resend was not rate limited.'
php "${repo_root}/bin/providentia" notification:deliver --once >/dev/null
[[ -s "$smtp_dir/code" ]] || fail 'Real SMTP delivery did not contain one numeric verification code.'
email_code="$(cat "$smtp_dir/code")"
verify_payload="$(jq -cn --arg challengeId "$challenge_id" --arg bindingToken "$binding_token" \
    --arg code "$email_code" '{challengeId:$challengeId,bindingToken:$bindingToken,code:$code}')"
post_json '/api/v1/auth/email-codes/verify' \
    "$(jq '.bindingToken = "wrong-requesting-installation"' <<<"$verify_payload")"
[[ "$reply_status" == '422' ]] || fail 'An incorrect installation binding was accepted.'
post_json '/api/v1/auth/email-codes/verify' "$verify_payload"
[[ "$reply_status" == '200' ]] || fail "Email code verification failed (HTTP ${reply_status})."
jq -e --arg installationId "$installation_id" '
    .transport == "native" and .installationId == $installationId
    and (.accessToken | type == "string" and length >= 40)
    and (.refreshToken | type == "string" and length >= 40)
    and (.csrfToken | type == "string" and length >= 40)
    and .idleExpiresAt == null and .refreshIdleTtlSeconds == null
    and (.sessionId | type == "string") and (.userId | type == "string")
    and .activeHomeId == null
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

account_group_id="$(uuid4)"
php "${repo_root}/tests/Acceptance/preassign-account-group.php" \
    "$account_group_id" "$user_id"
get_bearer '/api/v1/me/profile' "$access_token"
[[ "$reply_status" == '200' ]] \
    || fail "Reading the unregistered profile failed (HTTP ${reply_status})."
jq -e --arg groupId "$account_group_id" '
    .onboardingComplete == false
    and .revision == 1
    and .accountAccess.groupId == $groupId
    and .accountAccess.revision == 2
' <<<"$reply_body" >/dev/null
get_bearer '/api/v1/countries/NA/policy' "$access_token"
[[ "$reply_status" == '200' ]] \
    || fail "Reading the registration policy failed (HTTP ${reply_status})."
policy_id="$(jq -er '.id' <<<"$reply_body")"
policy_revision="$(jq -er '.revision' <<<"$reply_body")"
onboarding_payload="$(jq -cn --arg policyId "$policy_id" \
    --argjson policyRevision "$policy_revision" '
    {displayName:"HTTP smoke user",countryCode:"NA",expectedRevision:0,
     policyAccepted:true,policyId:$policyId,policyRevision:$policyRevision}')"
post_json '/api/v1/me/onboarding' "$onboarding_payload" "$access_token"
[[ "$reply_status" == '409' ]] || fail 'A stale onboarding revision was accepted.'
get_bearer '/api/v1/me/profile' "$access_token"
[[ "$reply_status" == '200' ]] \
    || fail "Reloading the profile after a conflict failed (HTTP ${reply_status})."
profile_revision="$(jq -er '.revision' <<<"$reply_body")"
jq -e --arg groupId "$account_group_id" '
    .onboardingComplete == false
    and .accountAccess.groupId == $groupId
    and .accountAccess.revision == 2
' <<<"$reply_body" >/dev/null
post_json '/api/v1/me/onboarding' \
    "$(jq --argjson expectedRevision "$profile_revision" \
        '.expectedRevision = $expectedRevision' <<<"$onboarding_payload")" \
    "$access_token"
[[ "$reply_status" == '200' ]] \
    || fail "Retrying account setup failed (HTTP ${reply_status})."
jq -e --arg groupId "$account_group_id" '
    .onboardingComplete == true
    and .accountAccess.groupId == $groupId
    and .accountAccess.revision == 2
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
    /api/v1/auth/login-links \
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

post_json '/api/v1/auth/email-codes/verify' "$verify_payload"
[[ "$reply_status" == '422' ]] || fail 'A consumed verification code was reusable.'
if grep -Fq "$email_code" "$stdout_log" "$stderr_log"; then
    fail 'A numeric verification code leaked to application logs.'
fi
unset email_code binding_token verify_payload

printf 'Development authentication HTTP smoke passed.\n'
