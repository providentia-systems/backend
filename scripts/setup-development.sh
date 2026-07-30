#!/usr/bin/env bash

set -Eeuo pipefail

root_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
handover_zip="${PROVIDENTIA_HANDOVER_ZIP:-}"
dev_email="${PROVIDENTIA_DEV_EMAIL:-developer@providentia.local}"
dev_password="${PROVIDENTIA_DEV_PASSWORD:-}"
env_file="${root_dir}/.env.development.local"
handoff_file="${root_dir}/.providentia-development.json"
http_port="${PROVIDENTIA_HTTP_PORT:-8080}"
mailpit_port="${PROVIDENTIA_MAILPIT_PORT:-8025}"

while (($#)); do
    case "$1" in
        --handover) handover_zip="${2:?--handover requires a ZIP path}"; shift 2 ;;
        --dev-email) dev_email="${2:?--dev-email requires a value}"; shift 2 ;;
        --dev-password) dev_password="${2:?--dev-password requires a value}"; shift 2 ;;
        *) printf 'Unknown argument: %s\n' "$1" >&2; exit 2 ;;
    esac
done

for command_name in docker unzip sha256sum curl jq openssl; do
    command -v "$command_name" >/dev/null 2>&1 || {
        printf 'Required command is unavailable: %s\n' "$command_name" >&2
        exit 1
    }
done

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
unzip -p "$handover_zip" "${archive_root}/pantry-data.json" >"${scratch_dir}/pantry-data.json"
unzip -p "$handover_zip" "${archive_root}/product-rules.json" >"${scratch_dir}/product-rules.json"
printf '%s  %s\n' \
    'ac2a74f267d7a48a460c8fae24515887f97632cddfb4a17f5f45dd07c9e90116' \
    "${scratch_dir}/pantry-data.json" \
    '8131bd3bf41c9b70f0e4cfe86c9e7de699ca0df827c6287fc9f2927e35827899' \
    "${scratch_dir}/product-rules.json" | sha256sum --check --status

if [[ ! -f "$env_file" ]]; then
    umask 077
    if [[ -z "$dev_password" ]]; then
        dev_password="$(openssl rand -hex 16)"
    fi
    {
        printf 'AUTH_TOKEN_PEPPER=%s\n' "$(openssl rand -hex 32)"
        printf 'SYNC_CURSOR_SECRET=%s\n' "$(openssl rand -hex 32)"
        printf 'MYSQL_PASSWORD=%s\n' "$(openssl rand -hex 18)"
        printf 'MYSQL_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 18)"
        printf 'PROVIDENTIA_HTTP_PORT=%s\n' "$http_port"
        printf 'PROVIDENTIA_MAILPIT_PORT=%s\n' "$mailpit_port"
        printf 'EXPOSE_DEVELOPMENT_TOKENS=1\n'
        printf 'PROVIDENTIA_DEV_PASSWORD=%s\n' "$dev_password"
        printf 'PROVIDENTIA_DEV_DEVICE_ID=%s\n' "$(cat /proc/sys/kernel/random/uuid)"
    } >"$env_file"
fi

set -a
# Generated locally with fixed KEY=VALUE lines and mode 0600.
source "$env_file"
set +a
if [[ -z "$dev_password" ]]; then
    dev_password="${PROVIDENTIA_DEV_PASSWORD:?Development password is missing from the local secrets file.}"
fi
dev_device_id="${PROVIDENTIA_DEV_DEVICE_ID:?Development device ID is missing from the local secrets file.}"

cd "$root_dir"
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
login_payload="$(jq -n \
    --arg email "$dev_email" \
    --arg password "$dev_password" \
    --arg deviceId "$dev_device_id" \
    '{email:$email,password:$password,deviceId:$deviceId,deviceName:"Providentia development",platform:"linux",transport:"native"}')"

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

verify_development_token() {
    local token="$1"
    local verification_exchange
    local verification_status
    local verification_response
    verification_exchange="$(
        post_json_exchange \
            "${api_base}/api/v1/auth/verify-email" \
            "$(jq -n --arg token "$token" '{token:$token}')"
    )"
    verification_status="${verification_exchange##*$'\n'}"
    verification_response="${verification_exchange%$'\n'*}"
    if [[ "$verification_status" != '204' ]]; then
        printf 'Email verification failed (HTTP %s): %s\n' \
            "$verification_status" \
            "$(problem_summary "$verification_response")" >&2
        exit 1
    fi
}

resend_development_verification() {
    local resend_exchange
    local resend_status
    local resend_response
    resend_exchange="$(
        post_json_exchange \
            "${api_base}/api/v1/auth/verify-email/resend" \
            "$(jq -n --arg email "$dev_email" '{email:$email}')"
    )"
    resend_status="${resend_exchange##*$'\n'}"
    resend_response="${resend_exchange%$'\n'*}"
    if [[ "$resend_status" != '202' ]]; then
        printf 'Verification resend failed (HTTP %s): %s\n' \
            "$resend_status" \
            "$(problem_summary "$resend_response")" >&2
        exit 1
    fi
    jq -r '.developmentVerificationToken // empty' <<<"$resend_response"
}

login_exchange="$(post_json_exchange "${api_base}/api/v1/auth/login" "$login_payload")"
login_status="${login_exchange##*$'\n'}"
login_response="${login_exchange%$'\n'*}"
if [[ "$login_status" == '429' ]]; then
    printf 'Development login is locked or rate-limited: %s\n' \
        "$(problem_summary "$login_response")" >&2
    printf 'Wait for the stated window or choose a different --dev-email; do not delete data to bypass the control.\n' >&2
    exit 1
fi
if [[ "$login_status" == '403' ]]; then
    verification_token="$(resend_development_verification)"
    if [[ -z "$verification_token" ]]; then
        printf 'The account cannot sign in and no development verification token was issued: %s\n' \
            "$(problem_summary "$login_response")" >&2
        printf 'Inspect the account status or use a different --dev-email; the setup will not overwrite it.\n' >&2
        exit 1
    fi
    verify_development_token "$verification_token"
    login_exchange="$(post_json_exchange "${api_base}/api/v1/auth/login" "$login_payload")"
    login_status="${login_exchange##*$'\n'}"
    login_response="${login_exchange%$'\n'*}"
elif [[ "$login_status" == '401' ]]; then
    registration_exchange="$(
        post_json_exchange \
            "${api_base}/api/v1/auth/register" \
            "$(jq -n \
                --arg email "$dev_email" \
                --arg password "$dev_password" \
                '{email:$email,password:$password,displayName:"Providentia Developer"}')"
    )"
    registration_status="${registration_exchange##*$'\n'}"
    registration_response="${registration_exchange%$'\n'*}"
    if [[ "$registration_status" != '202' ]]; then
        # Registration commits before synchronous development mail delivery.
        # A failed response may therefore have left a valid unverified account.
        verification_token="$(resend_development_verification)"
        if [[ -z "$verification_token" ]]; then
            printf 'Generic account registration failed (HTTP %s): %s\n' \
                "$registration_status" \
                "$(problem_summary "$registration_response")" >&2
            exit 1
        fi
    else
        verification_token="$(jq -r '.developmentVerificationToken // empty' <<<"$registration_response")"
    fi
    if [[ -z "$verification_token" ]]; then
        printf 'The account already exists and is verified, but the supplied development password is incorrect.\n' >&2
        printf 'Use the original password, choose a different --dev-email, or complete the normal password-reset flow.\n' >&2
        exit 1
    fi
    verify_development_token "$verification_token"
    login_exchange="$(post_json_exchange "${api_base}/api/v1/auth/login" "$login_payload")"
    login_status="${login_exchange##*$'\n'}"
    login_response="${login_exchange%$'\n'*}"
elif [[ "$login_status" != '200' ]]; then
    printf 'Development login failed (HTTP %s): %s\n' \
        "$login_status" \
        "$(problem_summary "$login_response")" >&2
    exit 1
fi
if [[ "$login_status" != '200' ]]; then
    printf 'Development login still failed after safe account recovery (HTTP %s): %s\n' \
        "$login_status" \
        "$(problem_summary "$login_response")" >&2
    exit 1
fi
access_token="$(jq -r '.accessToken' <<<"$login_response")"
actor_user_id="$(jq -r '.userId' <<<"$login_response")"
homes="$(
    curl --fail-with-body --silent --show-error \
        -H "Authorization: Bearer ${access_token}" \
        "${api_base}/api/v1/homes"
)"
home_id="$(jq -r '.data[0].id // empty' <<<"$homes")"
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
    .catalogLinked == 23
    and .privateProducts == 37
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
    --arg password "$dev_password" \
    --arg deviceId "$dev_device_id" \
    --arg accessToken "$access_token" \
    --arg refreshToken "$(jq -r '.refreshToken' <<<"$login_response")" \
    '{apiBaseUrl:$apiBaseUrl,homeId:$homeId,userId:$userId,email:$email,password:$password,deviceId:$deviceId,accessToken:$accessToken,refreshToken:$refreshToken}' \
    >"$handoff_file"

printf '\nProvidentia development environment is ready.\n'
printf 'API:              %s\n' "$api_base"
printf 'Health:           %s/health/ready\n' "$api_base"
printf 'Mailpit:          http://127.0.0.1:%s\n' "$mailpit_port"
printf 'MySQL (internal): mysql:3306 / database providentia / user providentia\n'
printf 'Redis (internal): redis:6379\n'
printf 'Developer email:  %s\n' "$dev_email"
printf 'Developer pass:   %s\n' "$dev_password"
printf 'Active home ID:   %s\n' "$home_id"
printf 'Flutter handoff:  %s (mode 0600; loopback development only)\n' "$handoff_file"
printf 'Secrets file:     %s (mode 0600; never commit)\n' "$env_file"
