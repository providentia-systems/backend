#!/usr/bin/env bash

set -Eeuo pipefail

root_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
handover_zip="${PROVIDENTIA_HANDOVER_ZIP:-}"
dev_email="${PROVIDENTIA_DEV_EMAIL:-developer@providentia.local}"
env_file="${root_dir}/.env.development.local"
handoff_file="${root_dir}/.providentia-development.json"
http_port="${PROVIDENTIA_HTTP_PORT:-8080}"
mailpit_port="${PROVIDENTIA_MAILPIT_PORT:-8025}"
reset_data=0

usage() {
    cat <<'EOF'
Usage: bash scripts/setup-development.sh --handover ZIP [options]

Build and run Providentia from the current source checkout.

Options:
  --handover ZIP       Verified full or minimal development handover archive
  --dev-email EMAIL    Development account email
  --reset-data         Delete this source stack's containers and named volumes
  --help               Show this help

The development account is provisioned through a passwordless login link. The
script approves the link itself using the development approval token that the
loopback API exposes when EXPOSE_DEVELOPMENT_TOKENS=1 is set.

See docs/deployment/local-development.md for where to obtain the protected
handover or how to construct a checksum-verified minimal setup archive.
EOF
}

while (($#)); do
    case "$1" in
        --handover) handover_zip="${2:?--handover requires a ZIP path}"; shift 2 ;;
        --dev-email) dev_email="${2:?--dev-email requires a value}"; shift 2 ;;
        --reset-data) reset_data=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) printf 'Unknown argument: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done

for command_name in docker unzip sha256sum curl jq openssl; do
    command -v "$command_name" >/dev/null 2>&1 || {
        printf 'Required command is unavailable: %s\n' "$command_name" >&2
        exit 1
    }
done
command -v uuidgen >/dev/null 2>&1 || command -v python3 >/dev/null 2>&1 || {
    printf 'Required command is unavailable: uuidgen (or python3)\n' >&2
    exit 1
}
docker compose version >/dev/null 2>&1 || {
    printf 'Docker Compose v2 is required (docker compose).\n' >&2
    exit 1
}

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

existing_volume="$(docker volume ls --quiet \
    --filter label=com.docker.compose.project=providentia | sed -n '1p')"
if [[ ! -f "$env_file" && -n "$existing_volume" && "$reset_data" -eq 0 ]]; then
    cat >&2 <<EOF
Existing Providentia development volume found, but ${env_file} is missing.
The database was initialized with credentials that are no longer available.
Restore the matching secrets file, or explicitly destroy the local stack with:

  bash scripts/setup-development.sh --reset-data --handover /absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip
EOF
    exit 1
fi

if [[ -z "$handover_zip" ]]; then
    for candidate in \
        "${root_dir}/../../project_sources/01-Pantry_Stock_Project_Handover_2026-07-29.zip" \
        "${root_dir}/Pantry_Stock_Project_Handover_2026-07-29.zip"; do
        if [[ -f "$candidate" ]]; then
            handover_zip="$candidate"
            break
        fi
    done
fi
if [[ ! -f "$handover_zip" ]]; then
    printf 'Supply the verified handover with --handover /absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip\n' >&2
    exit 1
fi

scratch_dir="$(mktemp -d)"
trap 'rm -rf -- "$scratch_dir"' EXIT
archive_root='Pantry_Stock_Project_Handover_2026-07-29/03_data_exports'
if ! unzip -p "$handover_zip" "${archive_root}/pantry-data.json" \
    >"${scratch_dir}/pantry-data.json" \
    || ! unzip -p "$handover_zip" "${archive_root}/product-rules.json" \
        >"${scratch_dir}/product-rules.json"; then
    printf 'The handover does not contain the two required files under %s.\n' \
        "$archive_root" >&2
    printf 'See docs/deployment/local-development.md for the required archive layout.\n' >&2
    exit 1
fi
if ! printf '%s  %s\n' \
    'ac2a74f267d7a48a460c8fae24515887f97632cddfb4a17f5f45dd07c9e90116' \
    "${scratch_dir}/pantry-data.json" \
    '8131bd3bf41c9b70f0e4cfe86c9e7de699ca0df827c6287fc9f2927e35827899' \
    "${scratch_dir}/product-rules.json" | sha256sum --check --status; then
    printf 'The handover exports do not match the Phase 0 SHA-256 checksums.\n' >&2
    printf 'Do not import or repackage edited exports. Obtain the verified originals.\n' >&2
    exit 1
fi

if [[ ! -f "$env_file" ]]; then
    umask 077
    {
        printf 'AUTH_TOKEN_PEPPER=%s\n' "$(openssl rand -hex 32)"
        printf 'SYNC_CURSOR_SECRET=%s\n' "$(openssl rand -hex 32)"
        printf 'MYSQL_PASSWORD=%s\n' "$(openssl rand -hex 18)"
        printf 'MYSQL_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 18)"
        printf 'PROVIDENTIA_HTTP_PORT=%s\n' "$http_port"
        printf 'PROVIDENTIA_MAILPIT_PORT=%s\n' "$mailpit_port"
        printf 'EXPOSE_DEVELOPMENT_TOKENS=1\n'
        printf 'PROVIDENTIA_DEV_INSTALLATION_ID=%s\n' "$(generate_uuid)"
    } >"$env_file"
fi

# Older generated secrets files predate passwordless provisioning; extend them
# in place so the login-link flow works without discarding local data.
if ! grep -q '^EXPOSE_DEVELOPMENT_TOKENS=' "$env_file"; then
    umask 077
    printf 'EXPOSE_DEVELOPMENT_TOKENS=1\n' >>"$env_file"
fi
if ! grep -q '^PROVIDENTIA_DEV_INSTALLATION_ID=' "$env_file"; then
    umask 077
    printf 'PROVIDENTIA_DEV_INSTALLATION_ID=%s\n' "$(generate_uuid)" >>"$env_file"
fi

set -a
# Generated locally with fixed KEY=VALUE lines and mode 0600.
source "$env_file"
set +a
dev_installation_id="${PROVIDENTIA_DEV_INSTALLATION_ID:?Development installation ID is missing from the local secrets file.}"

cd "$root_dir"
if ((reset_data == 1)); then
    docker compose --env-file "$env_file" \
        --profile sqlite --profile mysql --profile mariadb \
        --profile redis --profile valkey \
        down --volumes --remove-orphans
fi
docker compose --env-file "$env_file" --profile mysql --profile redis up -d --build --wait
docker compose --env-file "$env_file" cp "${scratch_dir}/pantry-data.json" api-mysql:/tmp/pantry-data.json
docker compose --env-file "$env_file" cp "${scratch_dir}/product-rules.json" api-mysql:/tmp/product-rules.json
docker compose --env-file "$env_file" exec -T api-mysql \
    php bin/providentia catalog:seed \
    --data=/tmp/pantry-data.json \
    --rules=/tmp/product-rules.json \
    --dry-run
catalog_import="$(
    docker compose --env-file "$env_file" exec -T api-mysql \
        php bin/providentia catalog:seed \
        --data=/tmp/pantry-data.json \
        --rules=/tmp/product-rules.json
)"
jq -e '
    .mappedSourceRows == 292
    and .approvedAliases == 19
    and .approvedRules == 19
    and .unresolvedRows == 8
' <<<"$catalog_import" >/dev/null
catalog_replay="$(
    docker compose --env-file "$env_file" exec -T api-mysql \
        php bin/providentia catalog:seed \
        --data=/tmp/pantry-data.json \
        --rules=/tmp/product-rules.json
)"
jq -e '
    .productsInserted == 0
    and .packsInserted == 0
    and .aliasesInserted == 0
    and .rulesInserted == 0
    and .quarantineInserted == 0
    and .seedRunsInserted == 0
    and .mappedSourceRows == 292
' <<<"$catalog_replay" >/dev/null
baseline_dry_run="$(
    docker compose --env-file "$env_file" exec -T api-mysql \
        php bin/providentia baseline:import \
        --data=/tmp/pantry-data.json \
        --rules=/tmp/product-rules.json \
        --dry-run
)"
jq -e '
    .dryRun == true
    and .itemMasterRows == 292
    and .openingStockLines == 60
    and .openingStockQuantity == 159
    and .recentPurchaseLines == 16
    and .recentPurchaseSpend == "1078.38"
    and .historicalPurchaseLines == 452
    and .monthlyValidationRows == 261
    and .aliases == 19
    and .identityRules == 19
    and .unresolvedDescriptions == 8
' <<<"$baseline_dry_run" >/dev/null

api_base="http://127.0.0.1:${http_port}"

post_json_exchange() {
    local url="$1"
    local payload="$2"
    curl --silent --show-error --write-out $'\n%{http_code}' \
        -H 'Content-Type: application/json' \
        -X POST "$url" \
        --data "$payload"
}

problem_summary() {
    local response="$1"
    jq -r '.detail // .title // "No problem detail was returned."' <<<"$response" 2>/dev/null \
        || printf 'The response was not valid problem JSON.'
}

login_request_id="$(generate_uuid)"
login_poll_token="$(generate_login_secret)"
login_code_verifier="$(generate_login_secret)"
login_state="$(generate_login_secret)"
start_payload="$(jq -n \
    --arg requestId "$login_request_id" \
    --arg email "$dev_email" \
    --arg pollChallenge "$(s256_challenge "$login_poll_token")" \
    --arg codeChallenge "$(s256_challenge "$login_code_verifier")" \
    --arg state "$login_state" \
    --arg installationId "$dev_installation_id" \
    '{requestId:$requestId,email:$email,applicationKind:"homeowner",pollChallenge:$pollChallenge,codeChallenge:$codeChallenge,codeChallengeMethod:"S256",state:$state,installationId:$installationId,deviceName:"Providentia development",platform:"linux",transport:"native"}')"

start_exchange="$(post_json_exchange "${api_base}/api/v1/auth/login-links" "$start_payload")"
start_status="${start_exchange##*$'\n'}"
start_response="${start_exchange%$'\n'*}"
if [[ "$start_status" == '429' ]]; then
    printf 'Development login-link requests are rate-limited: %s\n' \
        "$(problem_summary "$start_response")" >&2
    printf 'Wait for the stated window or choose a different --dev-email; do not delete data to bypass the control.\n' >&2
    exit 1
fi
if [[ "$start_status" != '202' ]]; then
    printf 'Development login-link request failed (HTTP %s): %s\n' \
        "$start_status" \
        "$(problem_summary "$start_response")" >&2
    exit 1
fi
approval_token="$(jq -r '.developmentApprovalToken // empty' <<<"$start_response")"
if [[ -z "$approval_token" ]]; then
    printf 'The API did not expose a development approval token for the login link.\n' >&2
    printf 'Ensure the development stack runs with EXPOSE_DEVELOPMENT_TOKENS=1 (set in %s).\n' "$env_file" >&2
    exit 1
fi
decision_exchange="$(post_json_exchange \
    "${api_base}/api/v1/auth/login-links/${login_request_id}/decision" \
    "$(jq -n --arg approvalToken "$approval_token" \
        '{applicationKind:"homeowner",approvalToken:$approvalToken,decision:"approve"}')")"
decision_status="${decision_exchange##*$'\n'}"
decision_response="${decision_exchange%$'\n'*}"
if [[ "$decision_status" != '202' ]]; then
    printf 'Development login-link approval failed (HTTP %s): %s\n' \
        "$decision_status" \
        "$(problem_summary "$decision_response")" >&2
    exit 1
fi
session_exchange="$(post_json_exchange \
    "${api_base}/api/v1/auth/login-links/${login_request_id}/exchange" \
    "$(jq -n \
        --arg pollToken "$login_poll_token" \
        --arg codeVerifier "$login_code_verifier" \
        --arg state "$login_state" \
        '{pollToken:$pollToken,codeVerifier:$codeVerifier,state:$state}')")"
session_status="${session_exchange##*$'\n'}"
session_response="${session_exchange%$'\n'*}"
if [[ "$session_status" != '200' ]]; then
    printf 'Development login-link exchange failed (HTTP %s): %s\n' \
        "$session_status" \
        "$(problem_summary "$session_response")" >&2
    printf 'The account may be unavailable; inspect it or use a different --dev-email. The setup will not overwrite it.\n' >&2
    exit 1
fi
access_token="$(jq -r '.accessToken' <<<"$session_response")"
actor_user_id="$(jq -r '.userId' <<<"$session_response")"
homes="$(
    curl --fail-with-body --silent --show-error \
        -H "Authorization: Bearer ${access_token}" \
        "${api_base}/api/v1/homes"
)"
home_id="$(jq -r '[.data[]? | select(.role == "owner")][0].id // empty' <<<"$homes")"
if [[ -z "$home_id" ]]; then
    home="$(
        curl --fail-with-body --silent --show-error \
            -H 'Content-Type: application/json' \
            -H "Authorization: Bearer ${access_token}" \
            -X POST "${api_base}/api/v1/homes" \
            --data '{"name":"Providentia Development Home","locale":"en-NA","currency":"NAD","timezone":"Africa/Windhoek"}'
    )"
    home_id="$(jq -r '.id' <<<"$home")"
fi
curl --fail-with-body --silent --show-error \
    -H "Authorization: Bearer ${access_token}" \
    -X POST "${api_base}/api/v1/homes/${home_id}/switch" >/dev/null

baseline_import="$(
    docker compose --env-file "$env_file" exec -T api-mysql \
        php bin/providentia baseline:import \
        --data=/tmp/pantry-data.json \
        --rules=/tmp/product-rules.json \
        --home="$home_id" \
        --actor-user="$actor_user_id"
)"
jq -e '
    .catalogLinked == 32
    and .privateProducts == 28
    and .countLines == 60
    and .quantity == 159
    and .receipts == 9
    and .lines == 468
    and .approvedMatches == 456
    and .unresolvedLines == 12
    and .priceObservations == 16
' <<<"$baseline_import" >/dev/null
baseline_replay="$(
    docker compose --env-file "$env_file" exec -T api-mysql \
        php bin/providentia baseline:import \
        --data=/tmp/pantry-data.json \
        --rules=/tmp/product-rules.json \
        --home="$home_id" \
        --actor-user="$actor_user_id"
)"
jq -e '.replayed == true' <<<"$baseline_replay" >/dev/null

umask 077
jq -n \
    --arg apiBaseUrl "$api_base" \
    --arg homeId "$home_id" \
    --arg userId "$actor_user_id" \
    --arg email "$dev_email" \
    --arg installationId "$dev_installation_id" \
    --arg deviceId "$(jq -r '.deviceId' <<<"$session_response")" \
    --arg accessToken "$access_token" \
    --arg refreshToken "$(jq -r '.refreshToken' <<<"$session_response")" \
    --arg sessionId "$(jq -r '.sessionId' <<<"$session_response")" \
    '{apiBaseUrl:$apiBaseUrl,homeId:$homeId,userId:$userId,email:$email,installationId:$installationId,deviceId:$deviceId,session:{accessToken:$accessToken,refreshToken:$refreshToken,sessionId:$sessionId}}' \
    >"$handoff_file"
chmod 0600 "$handoff_file"

printf '\nProvidentia development environment is ready.\n'
printf 'API:              %s\n' "$api_base"
printf 'Health:           %s/health/ready\n' "$api_base"
printf 'Mailpit:          http://127.0.0.1:%s\n' "$mailpit_port"
printf 'MySQL (internal): mysql:3306 / database providentia / user providentia\n'
printf 'Redis (internal): redis:6379\n'
printf 'Developer email:  %s\n' "$dev_email"
printf 'Developer login:  passwordless login link (session tokens in the handoff)\n'
printf 'Active home ID:   %s\n' "$home_id"
printf 'Flutter handoff:  %s (mode 0600; loopback development only)\n' "$handoff_file"
printf 'Secrets file:     %s (mode 0600; never commit)\n' "$env_file"
