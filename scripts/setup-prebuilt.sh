#!/usr/bin/env bash

set -Eeuo pipefail

root_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="${root_dir}/compose.prebuilt.yaml"
env_file="${PROVIDENTIA_PREBUILT_ENV_FILE:-${root_dir}/.env.prebuilt.local}"
handoff_file="${root_dir}/.providentia-development.json"
version_override=""
registry_override=""
image_namespace_override=""
email_override=""
http_port_override=""
mailpit_port_override=""
bind_address_override=""
skip_provision=0
reset_data=0
handover_zip="${PROVIDENTIA_HANDOVER_ZIP:-}"

registry_environment="${PROVIDENTIA_REGISTRY:-}"
image_namespace_environment="${PROVIDENTIA_IMAGE_NAMESPACE:-}"
version_environment="${PROVIDENTIA_VERSION:-}"
default_registry="ghcr.io"
default_image_namespace="providentia-systems/backend"
canonical_image_repository="${default_registry}/${default_image_namespace}"
legacy_image_repository="ghcr.io/vast-development-method/providentia-laminas"

detect_image_namespace() {
    local remote namespace
    command -v git >/dev/null 2>&1 || return 1
    remote="$(git -C "$root_dir" remote get-url origin 2>/dev/null || true)"
    case "$remote" in
        https://github.com/*|http://github.com/*)
            namespace="${remote#*github.com/}"
            ;;
        git@github.com:*)
            namespace="${remote#git@github.com:}"
            ;;
        ssh://git@github.com/*)
            namespace="${remote#ssh://git@github.com/}"
            ;;
        *)
            return 1
            ;;
    esac
    namespace="${namespace%.git}"
    namespace="${namespace,,}"
    [[ "$namespace" =~ ^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$ ]] || return 1
    printf '%s\n' "$namespace"
}

detect_checkout_candidate_version() {
    local branch commit
    command -v git >/dev/null 2>&1 || return 1
    branch="$(git -C "$root_dir" symbolic-ref --quiet --short HEAD 2>/dev/null || true)"
    case "$branch" in
        agent/*)
            commit="$(git -C "$root_dir" rev-parse --verify HEAD 2>/dev/null || true)"
            [[ "$commit" =~ ^[0-9a-f]{40}$ ]] || return 1
            printf 'sha-%s\n' "${commit:0:12}"
            ;;
        *)
            return 1
            ;;
    esac
}

usage() {
    cat <<'EOF'
Usage: bash scripts/setup-prebuilt.sh [options]

Pull and run the published Providentia production images for local testing.

Options:
  --version TAG          Image tag to run (default: edge)
  --registry HOST        Container registry (default: ghcr.io)
  --image-namespace PATH Image owner/repository (default: detected Git remote)
  --dev-email EMAIL     Development account email
  --http-port PORT      Host API port (default: 8080)
  --mailpit-port PORT   Host Mailpit port (default: 8025)
  --bind-address IP     Host bind address (default: 127.0.0.1)
  --handover ZIP        Verified handover archive; seeds the starter catalog
  --skip-provision      Start and test the stack without creating an account/home
  --reset-data          Delete this prebuilt stack's containers and named volumes
  --help                Show this help

When the verified handover archive is available (via --handover, the
PROVIDENTIA_HANDOVER_ZIP environment variable, or the known local paths), the
approved starter catalog is seeded automatically and idempotently right after
the database migrations. Without it the stack still starts, with an explicit
warning that the starter catalog is empty.

The development account is provisioned through a passwordless login link that
the script approves itself; the prebuilt Compose profile exposes the required
development approval token (EXPOSE_DEVELOPMENT_TOKENS).
EOF
}

while (($#)); do
    case "$1" in
        --version) version_override="${2:?--version requires a tag}"; shift 2 ;;
        --registry) registry_override="${2:?--registry requires a host}"; shift 2 ;;
        --image-namespace) image_namespace_override="${2:?--image-namespace requires owner/repository}"; shift 2 ;;
        --dev-email) email_override="${2:?--dev-email requires a value}"; shift 2 ;;
        --http-port) http_port_override="${2:?--http-port requires a value}"; shift 2 ;;
        --mailpit-port) mailpit_port_override="${2:?--mailpit-port requires a value}"; shift 2 ;;
        --bind-address) bind_address_override="${2:?--bind-address requires a value}"; shift 2 ;;
        --handover) handover_zip="${2:?--handover requires a ZIP path}"; shift 2 ;;
        --skip-provision) skip_provision=1; shift ;;
        --reset-data) reset_data=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) printf 'Unknown argument: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done

for command_name in docker curl jq openssl; do
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
    --filter label=com.docker.compose.project=providentia-prebuilt | sed -n '1p')"
if [[ ! -f "$env_file" && -n "$existing_volume" && "$reset_data" -eq 0 ]]; then
    cat >&2 <<EOF
Existing Providentia prebuilt volume found, but ${env_file} is missing.
MySQL applies generated credentials only when it initializes an empty data
directory. Restore the matching secrets file, or explicitly reset local data:

  bash scripts/setup-prebuilt.sh --reset-data
EOF
    exit 1
fi

detected_image_namespace="$(detect_image_namespace || true)"
checkout_candidate_version="$(detect_checkout_candidate_version || true)"
generated_registry="${registry_override:-${registry_environment:-$default_registry}}"
generated_image_namespace="${image_namespace_override:-${image_namespace_environment:-${detected_image_namespace:-$default_image_namespace}}}"

if [[ ! "$generated_registry" =~ ^[a-z0-9][a-z0-9.-]*(:[0-9]+)?$ ]]; then
    printf 'Invalid container registry host: %s\n' "$generated_registry" >&2
    exit 2
fi
if [[ ! "$generated_image_namespace" =~ ^[a-z0-9][a-z0-9._-]*(/[a-z0-9][a-z0-9._-]*)+$ ]]; then
    printf 'Invalid image namespace; expected lowercase owner/repository: %s\n' "$generated_image_namespace" >&2
    exit 2
fi

if [[ ! -f "$env_file" ]]; then
    umask 077
    generated_version="${version_override:-${version_environment:-${checkout_candidate_version:-edge}}}"
    generated_email="${email_override:-developer@providentia.local}"
    generated_http_port="${http_port_override:-8080}"
    generated_mailpit_port="${mailpit_port_override:-8025}"
    generated_bind_address="${bind_address_override:-127.0.0.1}"
    {
        printf 'PROVIDENTIA_VERSION=%s\n' "$generated_version"
        printf 'PROVIDENTIA_REGISTRY=%s\n' "$generated_registry"
        printf 'PROVIDENTIA_IMAGE_NAMESPACE=%s\n' "$generated_image_namespace"
        printf 'PROVIDENTIA_BIND_ADDRESS=%s\n' "$generated_bind_address"
        printf 'PROVIDENTIA_HTTP_PORT=%s\n' "$generated_http_port"
        printf 'PROVIDENTIA_MAILPIT_PORT=%s\n' "$generated_mailpit_port"
        printf 'PROVIDENTIA_TRUSTED_PROXY_CIDRS=172.16.0.0/12\n'
        printf 'PROVIDENTIA_METRICS_TOKEN=%s\n' "$(openssl rand -hex 32)"
        printf 'MYSQL_PASSWORD=%s\n' "$(openssl rand -hex 24)"
        printf 'MYSQL_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)"
        printf 'REDIS_PASSWORD=%s\n' "$(openssl rand -hex 24)"
        printf 'AUTH_TOKEN_PEPPER=%s\n' "$(openssl rand -hex 32)"
        printf 'SYNC_CURSOR_SECRET=%s\n' "$(openssl rand -hex 32)"
        printf 'NOTIFICATION_PAYLOAD_KEK=%s\n' "$(openssl rand -base64 32 | tr -d '\n')"
        printf 'AI_MEDIA_KEK=%s\n' "$(openssl rand -base64 32 | tr -d '\n')"
        printf 'CATALOG_IMAGE_KEK=%s\n' "$(openssl rand -base64 32 | tr -d '\n')"
        printf 'DATA_EXPORT_KEK=%s\n' "$(openssl rand -base64 32 | tr -d '\n')"
        printf 'PROVIDENTIA_DEV_EMAIL=%s\n' "$generated_email"
        printf 'PROVIDENTIA_DEV_INSTALLATION_ID=%s\n' "$(generate_uuid)"
    } >"$env_file"
    chmod 0600 "$env_file"
fi

if ! grep -q '^CATALOG_IMAGE_KEK=' "$env_file"; then
    umask 077
    printf 'CATALOG_IMAGE_KEK=%s\n' "$(openssl rand -base64 32 | tr -d '\n')" >>"$env_file"
    chmod 0600 "$env_file"
fi

if ! grep -q '^PROVIDENTIA_DEV_INSTALLATION_ID=' "$env_file"; then
    umask 077
    printf 'PROVIDENTIA_DEV_INSTALLATION_ID=%s\n' "$(generate_uuid)" >>"$env_file"
    chmod 0600 "$env_file"
fi

set -a
# This file is generated locally with fixed KEY=VALUE lines and mode 0600.
source "$env_file"
set +a

using_checkout_candidate=0
if [[ "${PROVIDENTIA_SKIP_PULL:-0}" == '1' ]]; then
    checkout_candidate_version=""
elif [[ -n "$checkout_candidate_version" && -z "$version_override" && -z "$version_environment" ]]; then
    using_checkout_candidate=1
fi

export PROVIDENTIA_VERSION="${version_override:-${version_environment:-${checkout_candidate_version:-${PROVIDENTIA_VERSION:?PROVIDENTIA_VERSION is required}}}}"
export PROVIDENTIA_REGISTRY="${registry_override:-${registry_environment:-${PROVIDENTIA_REGISTRY:-$generated_registry}}}"
export PROVIDENTIA_IMAGE_NAMESPACE="${image_namespace_override:-${image_namespace_environment:-${detected_image_namespace:-${PROVIDENTIA_IMAGE_NAMESPACE:-$generated_image_namespace}}}}"
if [[ ! "$PROVIDENTIA_REGISTRY" =~ ^[a-z0-9][a-z0-9.-]*(:[0-9]+)?$ ]]; then
    printf 'Invalid container registry host: %s\n' "$PROVIDENTIA_REGISTRY" >&2
    exit 2
fi
if [[ ! "$PROVIDENTIA_IMAGE_NAMESPACE" =~ ^[a-z0-9][a-z0-9._-]*(/[a-z0-9][a-z0-9._-]*)+$ ]]; then
    printf 'Invalid image namespace; expected lowercase owner/repository: %s\n' "$PROVIDENTIA_IMAGE_NAMESPACE" >&2
    exit 2
fi
export PROVIDENTIA_IMAGE_REPOSITORY="${PROVIDENTIA_REGISTRY}/${PROVIDENTIA_IMAGE_NAMESPACE}"
export PROVIDENTIA_WEB_IMAGE_REPOSITORY="${PROVIDENTIA_WEB_IMAGE_REPOSITORY:-${PROVIDENTIA_IMAGE_REPOSITORY}-web}"
export PROVIDENTIA_MEDIA_IMAGE_REPOSITORY="${PROVIDENTIA_MEDIA_IMAGE_REPOSITORY:-${PROVIDENTIA_IMAGE_REPOSITORY}-media-worker}"

# PR #11 moved publication to the repository-owned GHCR namespace. Older
# generated env files still contain full references to the former package; do
# not let those stale values keep selecting an image that no longer represents
# this repository. Known official references are also derived again so
# --version always changes the image tag on an existing installation.
case "${PROVIDENTIA_IMAGE:-}" in
    "${legacy_image_repository}:"*|"${canonical_image_repository}:"*|"${PROVIDENTIA_IMAGE_REPOSITORY}:"*) unset PROVIDENTIA_IMAGE ;;
esac
case "${PROVIDENTIA_WEB_IMAGE:-}" in
    "${legacy_image_repository}-web:"*|"${canonical_image_repository}-web:"*|"${PROVIDENTIA_IMAGE_REPOSITORY}-web:"*) unset PROVIDENTIA_WEB_IMAGE ;;
esac
case "${PROVIDENTIA_MEDIA_IMAGE:-}" in
    "${legacy_image_repository}-media-worker:"*|"${canonical_image_repository}-media-worker:"*|"${PROVIDENTIA_IMAGE_REPOSITORY}-media-worker:"*) unset PROVIDENTIA_MEDIA_IMAGE ;;
esac

export PROVIDENTIA_IMAGE="${PROVIDENTIA_IMAGE:-${PROVIDENTIA_IMAGE_REPOSITORY}:${PROVIDENTIA_VERSION}}"
export PROVIDENTIA_WEB_IMAGE="${PROVIDENTIA_WEB_IMAGE:-${PROVIDENTIA_WEB_IMAGE_REPOSITORY}:${PROVIDENTIA_VERSION}}"
export PROVIDENTIA_MEDIA_IMAGE="${PROVIDENTIA_MEDIA_IMAGE:-${PROVIDENTIA_MEDIA_IMAGE_REPOSITORY}:${PROVIDENTIA_VERSION}}"
export PROVIDENTIA_DEV_EMAIL="${email_override:-${PROVIDENTIA_DEV_EMAIL:?PROVIDENTIA_DEV_EMAIL is required}}"
export PROVIDENTIA_DEV_INSTALLATION_ID="${PROVIDENTIA_DEV_INSTALLATION_ID:?PROVIDENTIA_DEV_INSTALLATION_ID is required}"
export PROVIDENTIA_HTTP_PORT="${http_port_override:-${PROVIDENTIA_HTTP_PORT:?PROVIDENTIA_HTTP_PORT is required}}"
export PROVIDENTIA_MAILPIT_PORT="${mailpit_port_override:-${PROVIDENTIA_MAILPIT_PORT:?PROVIDENTIA_MAILPIT_PORT is required}}"
export PROVIDENTIA_BIND_ADDRESS="${bind_address_override:-${PROVIDENTIA_BIND_ADDRESS:?PROVIDENTIA_BIND_ADDRESS is required}}"

if [[ ! "$PROVIDENTIA_HTTP_PORT" =~ ^[0-9]+$ ]] || ((PROVIDENTIA_HTTP_PORT < 1 || PROVIDENTIA_HTTP_PORT > 65535)); then
    printf 'Invalid HTTP port: %s\n' "$PROVIDENTIA_HTTP_PORT" >&2
    exit 2
fi
if [[ ! "$PROVIDENTIA_MAILPIT_PORT" =~ ^[0-9]+$ ]] || ((PROVIDENTIA_MAILPIT_PORT < 1 || PROVIDENTIA_MAILPIT_PORT > 65535)); then
    printf 'Invalid Mailpit port: %s\n' "$PROVIDENTIA_MAILPIT_PORT" >&2
    exit 2
fi

compose=(docker compose --env-file "$env_file" -f "$compose_file")

if ((reset_data == 1)); then
    "${compose[@]}" down --volumes --remove-orphans
fi

diagnostics() {
    local status=$?
    trap - EXIT
    if ((status != 0)); then
        printf '\nProvidentia startup failed. Container state and bounded logs follow.\n' >&2
        "${compose[@]}" ps >&2 || true
        "${compose[@]}" logs --tail=100 \
            api web worker outbox notification data-governance sync-compactor ai-video-worker \
            mysql redis mailpit >&2 || true
    fi
    exit "$status"
}
trap diagnostics EXIT

printf 'Pulling Providentia %s production images...\n' "$PROVIDENTIA_VERSION"
if [[ "${PROVIDENTIA_SKIP_PULL:-0}" == '1' ]]; then
    printf 'Using the explicitly supplied local images without pulling.\n'
elif ! "${compose[@]}" pull; then
    cat >&2 <<'EOF'

The GHCR pull failed. If the packages are private, authenticate first:

  printf '%s' "$GHCR_TOKEN" | docker login ghcr.io --username YOUR_GITHUB_LOGIN --password-stdin

The token needs read access to the repository packages.
EOF
    exit 1
fi

if ((using_checkout_candidate == 1)); then
    expected_revision="$(git -C "$root_dir" rev-parse --verify HEAD)"
    for image in "$PROVIDENTIA_IMAGE" "$PROVIDENTIA_WEB_IMAGE" "$PROVIDENTIA_MEDIA_IMAGE"; do
        image_revision="$(docker image inspect \
            --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' \
            "$image" 2>/dev/null || true)"
        if [[ "$image_revision" != "$expected_revision" ]]; then
            printf 'Pulled candidate image does not match this checkout: %s\n' "$image" >&2
            printf 'Expected revision %s, found %s.\n' \
                "$expected_revision" "${image_revision:-no revision label}" >&2
            exit 1
        fi
    done
fi

"${compose[@]}" --profile tools run --rm volume-init
"${compose[@]}" up -d --wait mysql redis mailpit
"${compose[@]}" --profile tools run --rm migrate

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
if [[ -n "$handover_zip" && -f "$handover_zip" ]]; then
    command -v unzip >/dev/null 2>&1 && command -v sha256sum >/dev/null 2>&1 || {
        printf 'unzip and sha256sum are required to seed the starter catalog.\n' >&2
        exit 1
    }
    seed_dir="$(mktemp -d)"
    seed_archive_root='Pantry_Stock_Project_Handover_2026-07-29/03_data_exports'
    if ! unzip -p "$handover_zip" "${seed_archive_root}/pantry-data.json" \
        >"${seed_dir}/pantry-data.json" \
        || ! unzip -p "$handover_zip" "${seed_archive_root}/product-rules.json" \
            >"${seed_dir}/product-rules.json"; then
        printf 'The handover does not contain the two required files under %s.\n' \
            "$seed_archive_root" >&2
        exit 1
    fi
    if ! printf '%s  %s\n' \
        'ac2a74f267d7a48a460c8fae24515887f97632cddfb4a17f5f45dd07c9e90116' \
        "${seed_dir}/pantry-data.json" \
        '8131bd3bf41c9b70f0e4cfe86c9e7de699ca0df827c6287fc9f2927e35827899' \
        "${seed_dir}/product-rules.json" | sha256sum --check --status; then
        printf 'The handover exports do not match the verified Phase 0 checksums.\n' >&2
        exit 1
    fi
    catalog_seed="$(
        "${compose[@]}" --profile tools run --rm \
            --volume "${seed_dir}:/seed:ro" seed
    )"
    jq -e '
        .mappedSourceRows == 292
        and .approvedAliases == 19
        and .approvedRules == 19
        and .unresolvedRows == 8
    ' <<<"$catalog_seed" >/dev/null
    catalog_replay="$(
        "${compose[@]}" --profile tools run --rm \
            --volume "${seed_dir}:/seed:ro" seed
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
    printf 'Starter catalog seeded and replay-verified (292 mapped rows, 8 quarantined).\n'
    rm -rf -- "$seed_dir"
else
    cat >&2 <<'SEEDWARN'
WARNING: no verified handover archive was supplied, so the approved starter
catalog was NOT seeded. New homes will start without the shared catalog.
Re-run with --handover /absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip
(or set PROVIDENTIA_HANDOVER_ZIP) to bootstrap it; the import is idempotent.
SEEDWARN
fi
"${compose[@]}" up -d --wait \
    api web worker outbox notification data-governance sync-compactor ai-video-worker

api_base="http://127.0.0.1:${PROVIDENTIA_HTTP_PORT}"
curl --fail-with-body --silent --show-error "${api_base}/health/live" >/dev/null
curl --fail-with-body --silent --show-error "${api_base}/health/ready" >/dev/null
curl --fail-with-body --silent --show-error "${api_base}/api/v1/system/info" >/dev/null

if ((skip_provision == 0)); then
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
        --arg email "$PROVIDENTIA_DEV_EMAIL" \
        --arg pollChallenge "$(s256_challenge "$login_poll_token")" \
        --arg codeChallenge "$(s256_challenge "$login_code_verifier")" \
        --arg state "$login_state" \
        --arg installationId "$PROVIDENTIA_DEV_INSTALLATION_ID" \
        '{requestId:$requestId,email:$email,applicationKind:"homeowner",pollChallenge:$pollChallenge,codeChallenge:$codeChallenge,codeChallengeMethod:"S256",state:$state,installationId:$installationId,deviceName:"Providentia prebuilt",platform:"linux",transport:"native"}')"
    start_exchange="$(post_json_exchange "${api_base}/api/v1/auth/login-links" "$start_payload")"
    start_status="${start_exchange##*$'\n'}"
    start_response="${start_exchange%$'\n'*}"
    if [[ "$start_status" == '429' ]]; then
        printf 'Development login-link requests are rate-limited: %s\n' \
            "$(problem_summary "$start_response")" >&2
        printf 'Wait for the stated window or choose a different --dev-email.\n' >&2
        exit 1
    elif [[ "$start_status" != '202' ]]; then
        printf 'Development login-link request failed (HTTP %s): %s\n' \
            "$start_status" "$(problem_summary "$start_response")" >&2
        exit 1
    fi
    approval_token="$(jq -r '.developmentApprovalToken // empty' <<<"$start_response")"
    [[ -n "$approval_token" ]] || {
        printf 'The API did not expose a development approval token for the login link.\n' >&2
        printf 'The prebuilt Compose profile must keep EXPOSE_DEVELOPMENT_TOKENS enabled.\n' >&2
        exit 1
    }
    decision_exchange="$(post_json_exchange \
        "${api_base}/api/v1/auth/login-links/${login_request_id}/decision" \
        "$(jq -n --arg approvalToken "$approval_token" \
            '{applicationKind:"homeowner",approvalToken:$approvalToken,decision:"approve"}')")"
    decision_status="${decision_exchange##*$'\n'}"
    decision_response="${decision_exchange%$'\n'*}"
    if [[ "$decision_status" != '202' ]]; then
        printf 'Development login-link approval failed (HTTP %s): %s\n' \
            "$decision_status" "$(problem_summary "$decision_response")" >&2
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
            "$session_status" "$(problem_summary "$session_response")" >&2
        exit 1
    fi

    access_token="$(jq -er '.accessToken' <<<"$session_response")"
    actor_user_id="$(jq -er '.userId' <<<"$session_response")"
    homes="$(curl --fail-with-body --silent --show-error \
        -H "Authorization: Bearer ${access_token}" \
        "${api_base}/api/v1/homes")"
    home_id="$(jq -r '[.data[]? | select(.role == "owner")][0].id // empty' <<<"$homes")"
    if [[ -z "$home_id" ]]; then
        home="$(curl --fail-with-body --silent --show-error \
            -H 'Content-Type: application/json' \
            -H "Authorization: Bearer ${access_token}" \
            -X POST "${api_base}/api/v1/homes" \
            --data '{"name":"Providentia Development Home","locale":"en-NA","currency":"NAD","timezone":"Africa/Windhoek"}')"
        home_id="$(jq -er '.id' <<<"$home")"
    fi
    curl --fail-with-body --silent --show-error \
        -H "Authorization: Bearer ${access_token}" \
        -X POST "${api_base}/api/v1/homes/${home_id}/switch" >/dev/null

    umask 077
    jq -n \
        --arg apiBaseUrl "$api_base" \
        --arg homeId "$home_id" \
        --arg userId "$actor_user_id" \
        --arg email "$PROVIDENTIA_DEV_EMAIL" \
        --arg installationId "$PROVIDENTIA_DEV_INSTALLATION_ID" \
        --arg deviceId "$(jq -er '.deviceId' <<<"$session_response")" \
        --arg accessToken "$access_token" \
        --arg refreshToken "$(jq -er '.refreshToken' <<<"$session_response")" \
        --arg sessionId "$(jq -er '.sessionId' <<<"$session_response")" \
        '{apiBaseUrl:$apiBaseUrl,homeId:$homeId,userId:$userId,email:$email,installationId:$installationId,deviceId:$deviceId,session:{accessToken:$accessToken,refreshToken:$refreshToken,sessionId:$sessionId}}' \
        >"$handoff_file"
    chmod 0600 "$handoff_file"
fi

trap - EXIT
printf '\nProvidentia prebuilt environment is ready.\n'
printf 'Image tag:         %s\n' "$PROVIDENTIA_VERSION"
printf 'API:               %s\n' "$api_base"
printf 'Liveness:          %s/health/live\n' "$api_base"
printf 'Readiness:         %s/health/ready\n' "$api_base"
printf 'Mailpit:           http://127.0.0.1:%s\n' "$PROVIDENTIA_MAILPIT_PORT"
if ((skip_provision == 0)); then
    printf 'Developer email:   %s\n' "$PROVIDENTIA_DEV_EMAIL"
    printf 'Developer login:   passwordless login link (session tokens in the handoff)\n'
    printf 'Flutter handoff:   %s (mode 0600; local development only)\n' "$handoff_file"
fi
printf 'Local secrets:     %s (mode 0600; never commit)\n' "$env_file"
