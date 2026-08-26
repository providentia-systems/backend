#!/usr/bin/env bash

set -Eeuo pipefail

root_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
handoff_file="${PROVIDENTIA_DEVELOPMENT_HANDOFF:-${root_dir}/.providentia-development.json}"
test_email="${PROVIDENTIA_TEST_EMAIL:-test-user@providentia.local}"
display_name="${PROVIDENTIA_TEST_DISPLAY_NAME:-Providentia Test User}"
requested_role="${PROVIDENTIA_TEST_HOME_ROLE:-member}"

usage() {
    cat <<'EOF'
Usage: bash scripts/provision-development-user.sh [options]

Create or reuse a verified passwordless account on the loopback development
API. By default the account is invited into the bootstrap home as a member.

Options:
  --handoff FILE       Development handoff (default: .providentia-development.json)
  --email EMAIL        Test account email (default: test-user@providentia.local)
  --display-name NAME  Test account display name
  --role ROLE          manager, member, viewer, or none (default: member)
  --help               Show this help

Accounts are provisioned through development login links that the script
approves itself, so the API must expose development tokens
(EXPOSE_DEVELOPMENT_TOKENS=1; the loopback development profiles enable this).

The "none" role provisions the account without creating or changing a home
membership. It does not remove a membership created by an earlier run.
EOF
}

while (($#)); do
    case "$1" in
        --handoff) handoff_file="${2:?--handoff requires a file path}"; shift 2 ;;
        --email) test_email="${2:?--email requires a value}"; shift 2 ;;
        --display-name) display_name="${2:?--display-name requires a value}"; shift 2 ;;
        --role) requested_role="${2:?--role requires a value}"; shift 2 ;;
        --help|-h) usage; exit 0 ;;
        *) printf 'Unknown argument: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done

fail() {
    printf 'Development user provisioning failed: %s\n' "$*" >&2
    exit 1
}

for command_name in curl jq openssl; do
    command -v "$command_name" >/dev/null 2>&1 \
        || fail "required command is unavailable: ${command_name}"
done
command -v uuidgen >/dev/null 2>&1 || command -v python3 >/dev/null 2>&1 \
    || fail 'required command is unavailable: uuidgen (or python3)'

base64url_encode() {
    tr -d '=\n' | tr '+/' '-_'
}

generate_uuid() {
    if command -v uuidgen >/dev/null 2>&1; then
        uuidgen | tr '[:upper:]' '[:lower:]'
    else
        python3 -c 'import uuid; print(uuid.uuid4())'
    fi
}

generate_login_secret() {
    openssl rand -base64 32 | base64url_encode
}

s256_challenge() {
    printf '%s' "$1" | openssl dgst -sha256 -binary | openssl base64 | base64url_encode
}

[[ -f "$handoff_file" ]] \
    || fail "handoff not found: ${handoff_file}. Run setup-development.sh or setup-prebuilt.sh first."
jq -e 'type == "object"' "$handoff_file" >/dev/null 2>&1 \
    || fail "handoff is not a valid JSON object: ${handoff_file}"

api_base="$(jq -er '.apiBaseUrl | select(type == "string" and length > 0)' "$handoff_file")" \
    || fail 'handoff is missing apiBaseUrl.'
bootstrap_home_id="$(jq -er '.homeId | select(type == "string" and length > 0)' "$handoff_file")" \
    || fail 'handoff is missing homeId.'
bootstrap_email="$(jq -er '.email | select(type == "string" and length > 0)' "$handoff_file")" \
    || fail 'handoff is missing the bootstrap email.'
bootstrap_installation_id="$(jq -er '.installationId | select(type == "string" and length > 0)' "$handoff_file")" \
    || fail 'handoff is missing the bootstrap installationId. Re-run setup-development.sh or setup-prebuilt.sh to refresh it.'

if [[ ! "$api_base" =~ ^https?://(127\.0\.0\.1|localhost|\[::1\])(:([0-9]{1,5}))?$ ]]; then
    fail "refusing non-loopback API URL: ${api_base}"
fi
if [[ -n "${BASH_REMATCH[3]:-}" ]] \
    && ((10#${BASH_REMATCH[3]} < 1 || 10#${BASH_REMATCH[3]} > 65535)); then
    fail "API URL contains an invalid port: ${api_base}"
fi
uuid_pattern='^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$'
[[ "$bootstrap_home_id" =~ $uuid_pattern ]] || fail 'handoff homeId is not a UUID.'
[[ "$bootstrap_installation_id" =~ $uuid_pattern ]] || fail 'handoff installationId is not a UUID.'

test_email="$(printf '%s' "$test_email" | tr '[:upper:]' '[:lower:]')"
requested_role="$(printf '%s' "$requested_role" | tr '[:upper:]' '[:lower:]')"
case "$requested_role" in
    manager|member|viewer|none) ;;
    *) fail '--role must be manager, member, viewer, or none.' ;;
esac
[[ -n "$display_name" ]] || fail '--display-name must not be empty.'

stored_user="$(jq -c --arg email "$test_email" '
    (.testUsers // [])
    | map(select((.email | ascii_downcase) == ($email | ascii_downcase)))
    | first // {}
' "$handoff_file")"
test_installation_id="$(jq -r '.installationId // empty' <<<"$stored_user")"
if [[ -z "$test_installation_id" ]]; then
    test_installation_id="$(generate_uuid)"
fi
[[ "$test_installation_id" =~ $uuid_pattern ]] || fail 'stored test-user installationId is not a UUID.'

post_json_exchange() {
    local url="$1"
    local payload="$2"
    curl --silent --show-error --noproxy '*' --connect-timeout 5 --max-time 30 \
        --write-out $'\n%{http_code}' \
        -H 'Content-Type: application/json' \
        -X POST "$url" \
        --data "$payload" \
        || fail "could not reach ${url}"
}

authorized_exchange() {
    local method="$1"
    local url="$2"
    local token="$3"
    local payload="${4:-}"
    local arguments=(
        --silent --show-error --noproxy '*' --connect-timeout 5 --max-time 30
        --write-out $'\n%{http_code}'
        -H "Authorization: Bearer ${token}"
        -X "$method"
    )
    if [[ -n "$payload" ]]; then
        arguments+=(-H 'Content-Type: application/json' --data "$payload")
    fi
    curl "${arguments[@]}" "$url" || fail "could not reach ${url}"
}

problem_summary() {
    local response="$1"
    jq -r '.detail // .title // "No problem detail was returned."' <<<"$response" 2>/dev/null \
        || printf 'The response was not valid problem JSON.'
}

login_link_session() {
    local label="$1"
    local email="$2"
    local installation_id="$3"
    local device_name="$4"
    local request_id poll_token code_verifier state_value
    local exchange status response approval_token
    request_id="$(generate_uuid)"
    poll_token="$(generate_login_secret)"
    code_verifier="$(generate_login_secret)"
    state_value="$(generate_login_secret)"
    exchange="$(post_json_exchange \
        "${api_base}/api/v1/auth/login-links" \
        "$(jq -n \
            --arg requestId "$request_id" \
            --arg email "$email" \
            --arg pollChallenge "$(s256_challenge "$poll_token")" \
            --arg codeChallenge "$(s256_challenge "$code_verifier")" \
            --arg state "$state_value" \
            --arg installationId "$installation_id" \
            --arg deviceName "$device_name" \
            '{
                requestId:$requestId,
                email:$email,
                applicationKind:"homeowner",
                pollChallenge:$pollChallenge,
                codeChallenge:$codeChallenge,
                codeChallengeMethod:"S256",
                state:$state,
                installationId:$installationId,
                deviceName:$deviceName,
                platform:"linux",
                transport:"native"
            }')")"
    status="${exchange##*$'\n'}"
    response="${exchange%$'\n'*}"
    if [[ "$status" == '429' ]]; then
        fail "${label} login-link request is rate-limited: $(problem_summary "$response")"
    fi
    [[ "$status" == '202' ]] \
        || fail "${label} login-link request failed (HTTP ${status}): $(problem_summary "$response")"
    approval_token="$(jq -r '.developmentApprovalToken // empty' <<<"$response")"
    [[ -n "$approval_token" ]] \
        || fail 'the API did not expose a development approval token; use the loopback development profile with EXPOSE_DEVELOPMENT_TOKENS=1.'
    exchange="$(post_json_exchange \
        "${api_base}/api/v1/auth/login-links/${request_id}/decision" \
        "$(jq -n --arg approvalToken "$approval_token" \
            '{applicationKind:"homeowner",approvalToken:$approvalToken,decision:"approve"}')")"
    status="${exchange##*$'\n'}"
    response="${exchange%$'\n'*}"
    [[ "$status" == '202' ]] \
        || fail "${label} login-link approval failed (HTTP ${status}): $(problem_summary "$response")"
    exchange="$(post_json_exchange \
        "${api_base}/api/v1/auth/login-links/${request_id}/exchange" \
        "$(jq -n \
            --arg pollToken "$poll_token" \
            --arg codeVerifier "$code_verifier" \
            --arg state "$state_value" \
            '{pollToken:$pollToken,codeVerifier:$codeVerifier,state:$state}')")"
    status="${exchange##*$'\n'}"
    response="${exchange%$'\n'*}"
    [[ "$status" == '200' ]] || fail \
        "${label} login-link exchange failed (HTTP ${status}): $(problem_summary "$response"). The account may be deactivated."
    jq -e '.accessToken and .userId' <<<"$response" >/dev/null \
        || fail "${label} login-link exchange returned an invalid native-session response."
    printf '%s' "$response"
}

bootstrap_login="$(login_link_session \
    'bootstrap owner' \
    "$bootstrap_email" \
    "$bootstrap_installation_id" \
    'Bootstrap owner provisioning')"
bootstrap_access_token="$(jq -er '.accessToken' <<<"$bootstrap_login")"
bootstrap_user_id="$(jq -er '.userId' <<<"$bootstrap_login")"

homes_exchange="$(authorized_exchange GET "${api_base}/api/v1/homes" "$bootstrap_access_token")"
homes_status="${homes_exchange##*$'\n'}"
homes_response="${homes_exchange%$'\n'*}"
[[ "$homes_status" == '200' ]] \
    || fail "could not list bootstrap homes (HTTP ${homes_status}): $(problem_summary "$homes_response")"
bootstrap_home_role="$(jq -r --arg home "$bootstrap_home_id" \
    '.data[]? | select(.id == $home) | .role // empty' <<<"$homes_response")"
[[ -n "$bootstrap_home_role" ]] \
    || fail 'the handoff home is not visible to the fresh bootstrap session.'
[[ "$bootstrap_home_role" == 'owner' ]] \
    || fail "the handoff account is ${bootstrap_home_role}, not the bootstrap home owner."

test_login="$(login_link_session \
    'test user' \
    "$test_email" \
    "$test_installation_id" \
    "$display_name")"
test_access_token="$(jq -er '.accessToken' <<<"$test_login")"
test_refresh_token="$(jq -er '.refreshToken' <<<"$test_login")"
test_session_id="$(jq -er '.sessionId' <<<"$test_login")"
test_device_id="$(jq -er '.deviceId' <<<"$test_login")"
test_user_id="$(jq -er '.userId' <<<"$test_login")"
[[ "$test_user_id" != "$bootstrap_user_id" ]] \
    || fail 'the test account must be different from the bootstrap owner account.'
memberships_exchange="$(authorized_exchange \
    GET \
    "${api_base}/api/v1/homes/${bootstrap_home_id}/memberships" \
    "$bootstrap_access_token")"
memberships_status="${memberships_exchange##*$'\n'}"
memberships_response="${memberships_exchange%$'\n'*}"
[[ "$memberships_status" == '200' ]] \
    || fail "could not list home memberships (HTTP ${memberships_status}): $(problem_summary "$memberships_response")"
membership="$(jq -c --arg user "$test_user_id" \
    '.data[]? | select(.userId == $user)' <<<"$memberships_response" | head -n 1)"
effective_role='none'
if [[ -n "$membership" ]]; then
    effective_role="$(jq -r '.role' <<<"$membership")"
fi

if [[ "$requested_role" != 'none' ]]; then
    if [[ -n "$membership" ]]; then
        if [[ "$effective_role" != "$requested_role" ]]; then
            membership_revision="$(jq -er '.revision' <<<"$membership")"
            role_exchange="$(authorized_exchange \
                PATCH \
                "${api_base}/api/v1/homes/${bootstrap_home_id}/memberships/${test_user_id}" \
                "$bootstrap_access_token" \
                "$(jq -n \
                    --arg role "$requested_role" \
                    --argjson expectedRevision "$membership_revision" \
                    '{role:$role,expectedRevision:$expectedRevision}')")"
            role_status="${role_exchange##*$'\n'}"
            role_response="${role_exchange%$'\n'*}"
            [[ "$role_status" == '204' ]] || fail \
                "could not change membership role (HTTP ${role_status}): $(problem_summary "$role_response")"
            effective_role="$requested_role"
        fi
    else
        invitation_exchange="$(authorized_exchange \
            POST \
            "${api_base}/api/v1/homes/${bootstrap_home_id}/invitations" \
            "$bootstrap_access_token" \
            "$(jq -n --arg email "$test_email" --arg role "$requested_role" \
                '{email:$email,role:$role}')")"
        invitation_status="${invitation_exchange##*$'\n'}"
        invitation_response="${invitation_exchange%$'\n'*}"
        [[ "$invitation_status" == '201' ]] || fail \
            "could not invite test user (HTTP ${invitation_status}): $(problem_summary "$invitation_response")"
        invitation_token="$(jq -r '.developmentInvitationToken // empty' <<<"$invitation_response")"
        [[ -n "$invitation_token" ]] || fail \
            'the API did not expose a development invitation token; use the loopback development profile.'
        acceptance_exchange="$(authorized_exchange \
            POST \
            "${api_base}/api/v1/home-invitations/accept" \
            "$test_access_token" \
            "$(jq -n --arg token "$invitation_token" '{token:$token}')")"
        acceptance_status="${acceptance_exchange##*$'\n'}"
        acceptance_response="${acceptance_exchange%$'\n'*}"
        [[ "$acceptance_status" == '200' ]] || fail \
            "test user could not accept the invitation (HTTP ${acceptance_status}): $(problem_summary "$acceptance_response")"
        effective_role="$requested_role"
    fi

    memberships_exchange="$(authorized_exchange \
        GET \
        "${api_base}/api/v1/homes/${bootstrap_home_id}/memberships" \
        "$bootstrap_access_token")"
    memberships_status="${memberships_exchange##*$'\n'}"
    memberships_response="${memberships_exchange%$'\n'*}"
    [[ "$memberships_status" == '200' ]] \
        || fail "could not verify home membership (HTTP ${memberships_status}): $(problem_summary "$memberships_response")"
    verified_role="$(jq -r --arg user "$test_user_id" \
        '.data[]? | select(.userId == $user) | .role // empty' <<<"$memberships_response")"
    [[ "$verified_role" == "$requested_role" ]] \
        || fail "home membership verification returned role '${verified_role:-missing}', expected '${requested_role}'."
    effective_role="$verified_role"
fi

handoff_dir="$(cd -- "$(dirname -- "$handoff_file")" && pwd)"
handoff_tmp="$(mktemp "${handoff_dir}/.providentia-development.XXXXXX")"
cleanup() {
    rm -f -- "$handoff_tmp"
}
trap cleanup EXIT
umask 077
jq \
    --arg email "$test_email" \
    --arg displayName "$display_name" \
    --arg installationId "$test_installation_id" \
    --arg deviceId "$test_device_id" \
    --arg userId "$test_user_id" \
    --arg homeId "$bootstrap_home_id" \
    --arg role "$effective_role" \
    --arg accessToken "$test_access_token" \
    --arg refreshToken "$test_refresh_token" \
    --arg sessionId "$test_session_id" '
    .testUsers = (
        ((.testUsers // [])
            | map(select((.email | ascii_downcase) != ($email | ascii_downcase))))
        + [{
            email: $email,
            displayName: $displayName,
            installationId: $installationId,
            deviceId: $deviceId,
            userId: $userId,
            homeId: (if $role == "none" then null else $homeId end),
            role: $role,
            session: {
                accessToken: $accessToken,
                refreshToken: $refreshToken,
                sessionId: $sessionId
            }
        }]
    )
' "$handoff_file" >"$handoff_tmp"
chmod 0600 "$handoff_tmp"
mv -f -- "$handoff_tmp" "$handoff_file"
trap - EXIT

printf '\nProvidentia development user is ready.\n'
printf 'API:                    %s\n' "$api_base"
printf 'Bootstrap owner email:  %s\n' "$bootstrap_email"
printf 'Bootstrap owner ID:     %s\n' "$bootstrap_user_id"
printf 'Bootstrap home ID:      %s\n' "$bootstrap_home_id"
printf 'Test user email:        %s\n' "$test_email"
printf 'Test user ID:           %s\n' "$test_user_id"
printf 'Test installation ID:   %s\n' "$test_installation_id"
printf 'Test device ID:         %s\n' "$test_device_id"
printf 'Household role:         %s\n' "$effective_role"
printf 'Session tokens:         stored in the protected handoff (no passwords)\n'
printf 'Protected handoff:      %s (mode 0600; never commit)\n' "$handoff_file"
