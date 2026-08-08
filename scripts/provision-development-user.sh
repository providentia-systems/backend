#!/usr/bin/env bash

set -Eeuo pipefail

root_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
handoff_file="${PROVIDENTIA_DEVELOPMENT_HANDOFF:-${root_dir}/.providentia-development.json}"
test_email="${PROVIDENTIA_TEST_EMAIL:-test-user@providentia.local}"
test_password="${PROVIDENTIA_TEST_PASSWORD:-}"
display_name="${PROVIDENTIA_TEST_DISPLAY_NAME:-Providentia Test User}"
requested_role="${PROVIDENTIA_TEST_HOME_ROLE:-member}"

usage() {
    cat <<'EOF'
Usage: bash scripts/provision-development-user.sh [options]

Create or reuse a verified password account on the loopback development API.
By default the account is invited into the bootstrap home as a member.

Options:
  --handoff FILE       Development handoff (default: .providentia-development.json)
  --email EMAIL        Test account email (default: test-user@providentia.local)
  --password PASSWORD  Test password (generated and saved when omitted)
  --display-name NAME  Test account display name
  --role ROLE          manager, member, viewer, or none (default: member)
  --help               Show this help

The "none" role provisions the account without creating or changing a home
membership. It does not remove a membership created by an earlier run.
EOF
}

while (($#)); do
    case "$1" in
        --handoff) handoff_file="${2:?--handoff requires a file path}"; shift 2 ;;
        --email) test_email="${2:?--email requires a value}"; shift 2 ;;
        --password) test_password="${2:?--password requires a value}"; shift 2 ;;
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
bootstrap_password="$(jq -er '.password | select(type == "string" and length > 0)' "$handoff_file")" \
    || fail 'handoff is missing the bootstrap password.'
bootstrap_device_id="$(jq -er '.deviceId | select(type == "string" and length > 0)' "$handoff_file")" \
    || fail 'handoff is missing the bootstrap deviceId.'

if [[ ! "$api_base" =~ ^https?://(127\.0\.0\.1|localhost|\[::1\])(:([0-9]{1,5}))?$ ]]; then
    fail "refusing non-loopback API URL: ${api_base}"
fi
if [[ -n "${BASH_REMATCH[3]:-}" ]] \
    && ((10#${BASH_REMATCH[3]} < 1 || 10#${BASH_REMATCH[3]} > 65535)); then
    fail "API URL contains an invalid port: ${api_base}"
fi
uuid_pattern='^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$'
[[ "$bootstrap_home_id" =~ $uuid_pattern ]] || fail 'handoff homeId is not a UUID.'
[[ "$bootstrap_device_id" =~ $uuid_pattern ]] || fail 'handoff deviceId is not a UUID.'

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
if [[ -z "$test_password" ]]; then
    test_password="$(jq -r '.password // empty' <<<"$stored_user")"
fi
if [[ -z "$test_password" ]]; then
    test_password="Test-$(openssl rand -hex 16)!"
fi
test_device_id="$(jq -r '.deviceId // empty' <<<"$stored_user")"
if [[ -z "$test_device_id" ]]; then
    uuid_hex="$(openssl rand -hex 16)"
    test_device_id="${uuid_hex:0:8}-${uuid_hex:8:4}-4${uuid_hex:13:3}-8${uuid_hex:17:3}-${uuid_hex:20:12}"
fi
[[ "$test_device_id" =~ $uuid_pattern ]] || fail 'stored test-user deviceId is not a UUID.'

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

login_exchange() {
    local email="$1"
    local password="$2"
    local device_id="$3"
    local device_name="$4"
    post_json_exchange \
        "${api_base}/api/v1/auth/login" \
        "$(jq -n \
            --arg email "$email" \
            --arg password "$password" \
            --arg deviceId "$device_id" \
            --arg deviceName "$device_name" \
            '{
                email:$email,
                password:$password,
                deviceId:$deviceId,
                deviceName:$deviceName,
                platform:"linux",
                transport:"native"
            }')"
}

fresh_existing_login() {
    local label="$1"
    local email="$2"
    local password="$3"
    local device_id="$4"
    local exchange status response
    exchange="$(login_exchange "$email" "$password" "$device_id" "${label} provisioning")"
    status="${exchange##*$'\n'}"
    response="${exchange%$'\n'*}"
    if [[ "$status" != '200' ]]; then
        if [[ "$status" == '410' ]]; then
            fail "password login is disabled on the API. Use a development profile with AUTH_PASSWORD_LOGIN_ENABLED=1."
        fi
        fail "${label} login failed (HTTP ${status}): $(problem_summary "$response")"
    fi
    jq -e '.accessToken and .userId' <<<"$response" >/dev/null \
        || fail "${label} login returned an invalid native-session response."
    printf '%s' "$response"
}

verify_development_token() {
    local token="$1"
    local exchange status response
    exchange="$(post_json_exchange \
        "${api_base}/api/v1/auth/verify-email" \
        "$(jq -n --arg token "$token" '{token:$token}')")"
    status="${exchange##*$'\n'}"
    response="${exchange%$'\n'*}"
    [[ "$status" == '204' ]] \
        || fail "email verification failed (HTTP ${status}): $(problem_summary "$response")"
}

resend_development_token() {
    local email="$1"
    local exchange status response token
    exchange="$(post_json_exchange \
        "${api_base}/api/v1/auth/verify-email/resend" \
        "$(jq -n --arg email "$email" '{email:$email}')")"
    status="${exchange##*$'\n'}"
    response="${exchange%$'\n'*}"
    [[ "$status" == '202' ]] \
        || fail "verification resend failed (HTTP ${status}): $(problem_summary "$response")"
    token="$(jq -r '.developmentVerificationToken // empty' <<<"$response")"
    [[ -n "$token" ]] \
        || fail 'the API did not expose a development verification token; use the loopback development profile.'
    printf '%s' "$token"
}

provision_user_login() {
    local exchange status response verification_token registration_exchange
    local registration_status registration_response
    exchange="$(login_exchange "$test_email" "$test_password" "$test_device_id" "$display_name")"
    status="${exchange##*$'\n'}"
    response="${exchange%$'\n'*}"

    if [[ "$status" == '200' ]]; then
        printf '%s' "$response"
        return
    fi
    if [[ "$status" == '410' ]]; then
        fail 'password login is disabled; use a development profile with AUTH_PASSWORD_LOGIN_ENABLED=1.'
    fi
    if [[ "$status" == '429' ]]; then
        fail "test-user login is rate-limited: $(problem_summary "$response")"
    fi
    if [[ "$status" == '403' ]]; then
        [[ "$(jq -r '.title // empty' <<<"$response")" == 'Email verification required' ]] \
            || fail "test-user login was forbidden: $(problem_summary "$response")"
        verification_token="$(resend_development_token "$test_email")"
        verify_development_token "$verification_token"
    elif [[ "$status" == '401' ]]; then
        registration_exchange="$(post_json_exchange \
            "${api_base}/api/v1/auth/register" \
            "$(jq -n \
                --arg email "$test_email" \
                --arg password "$test_password" \
                --arg displayName "$display_name" \
                '{email:$email,password:$password,displayName:$displayName}')")"
        registration_status="${registration_exchange##*$'\n'}"
        registration_response="${registration_exchange%$'\n'*}"
        if [[ "$registration_status" == '410' ]]; then
            fail 'password registration is disabled; use the loopback development profile.'
        fi
        if [[ "$registration_status" == '202' ]]; then
            verification_token="$(jq -r '.developmentVerificationToken // empty' <<<"$registration_response")"
        elif [[ "$registration_status" =~ ^5[0-9][0-9]$ ]]; then
            # Registration may have committed before a development notification failed.
            verification_token="$(resend_development_token "$test_email")"
        else
            fail "test-user registration failed (HTTP ${registration_status}): $(problem_summary "$registration_response")"
        fi
        [[ -n "$verification_token" ]] || fail \
            'the account already exists and is verified, but its password does not match. Supply the original --password.'
        verify_development_token "$verification_token"
    else
        fail "test-user login failed (HTTP ${status}): $(problem_summary "$response")"
    fi

    exchange="$(login_exchange "$test_email" "$test_password" "$test_device_id" "$display_name")"
    status="${exchange##*$'\n'}"
    response="${exchange%$'\n'*}"
    [[ "$status" == '200' ]] || fail \
        "test-user login failed after verification (HTTP ${status}): $(problem_summary "$response"). The account may already have a different password."
    printf '%s' "$response"
}

bootstrap_login="$(fresh_existing_login \
    'bootstrap owner' \
    "$bootstrap_email" \
    "$bootstrap_password" \
    "$bootstrap_device_id")"
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

test_login="$(provision_user_login)"
test_access_token="$(jq -er '.accessToken' <<<"$test_login")"
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
    --arg password "$test_password" \
    --arg displayName "$display_name" \
    --arg deviceId "$test_device_id" \
    --arg userId "$test_user_id" \
    --arg homeId "$bootstrap_home_id" \
    --arg role "$effective_role" '
    .testUsers = (
        ((.testUsers // [])
            | map(select((.email | ascii_downcase) != ($email | ascii_downcase))))
        + [{
            email: $email,
            password: $password,
            displayName: $displayName,
            deviceId: $deviceId,
            userId: $userId,
            homeId: (if $role == "none" then null else $homeId end),
            role: $role
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
printf 'Test user password:     %s\n' "$test_password"
printf 'Test user ID:           %s\n' "$test_user_id"
printf 'Test device ID:         %s\n' "$test_device_id"
printf 'Household role:         %s\n' "$effective_role"
printf 'Protected handoff:      %s (mode 0600; never commit)\n' "$handoff_file"
