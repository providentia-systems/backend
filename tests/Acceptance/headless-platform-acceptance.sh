#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
http_port="${PROVIDENTIA_ACCEPTANCE_HTTP_PORT:-18084}"
mailpit_port="${PROVIDENTIA_ACCEPTANCE_MAILPIT_PORT:-18085}"
project_name="${PROVIDENTIA_ACCEPTANCE_PROJECT:-providentia-headless-acceptance}"
evidence_dir="${repo_root}/var/headless-platform-acceptance"
response_body="${evidence_dir}/response.json"
response_headers="${evidence_dir}/response.headers"
runtime_log="${evidence_dir}/runtime.log"
summary_file="${evidence_dir}/summary.json"
api_base="http://127.0.0.1:${http_port}"
mailpit_base="http://127.0.0.1:${mailpit_port}"
overlay="${repo_root}/tests/Acceptance/compose.headless-platform-acceptance.yaml"
compose=(
    docker compose
    --project-directory "$repo_root"
    --project-name "$project_name"
    --file "${repo_root}/compose.yaml"
    --file "$overlay"
    --profile sqlite
)

export PROVIDENTIA_HTTP_PORT="$http_port"
export PROVIDENTIA_MAILPIT_PORT="$mailpit_port"

fail() {
    printf 'HEADLESS ACCEPTANCE ERROR: %s\n' "$*" >&2
    exit 1
}

redact_stream() {
    sed -E \
        -e 's/(approval=)[A-Za-z0-9_-]+/\1[REDACTED]/g' \
        -e 's/(Bearer )[A-Za-z0-9._~-]+/\1[REDACTED]/g' \
        -e 's/("(pollToken|codeVerifier|accessToken|refreshToken|approvalToken)"[[:space:]]*:[[:space:]]*")[^"]+"/\1[REDACTED]"/g' \
        -e 's/acceptance-ai-token-(initial|replacement)-[0-9]+/[REDACTED]/g'
}

cleanup() {
    local status=$?
    trap - EXIT
    if [[ "$status" -ne 0 ]]; then
        "${compose[@]}" ps >&2 || true
        "${compose[@]}" logs --no-color --tail 160 \
            api-sqlite notification-sqlite mailpit ai-fixture 2>&1 \
            | redact_stream >&2 || true
    fi
    "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    exit "$status"
}
trap cleanup EXIT

uuid() {
    if [[ -r /proc/sys/kernel/random/uuid ]]; then
        tr '[:upper:]' '[:lower:]' </proc/sys/kernel/random/uuid
        return
    fi
    local value
    value="$(openssl rand -hex 16)"
    printf '%s-%s-4%s-a%s-%s\n' \
        "${value:0:8}" "${value:8:4}" "${value:13:3}" "${value:17:3}" "${value:20:12}"
}

base64url_random() {
    openssl rand 32 | openssl base64 -A | tr '+/' '-_' | tr -d '='
}

s256() {
    printf '%s' "$1" | openssl dgst -sha256 -binary \
        | openssl base64 -A | tr '+/' '-_' | tr -d '='
}

safe_response_summary() {
    jq -c \
        'if type == "object" then {status, title, detail} else {type:(type)} end' \
        "$response_body" 2>/dev/null || printf '{"status":"unparseable"}'
}

http_json() {
    local method="$1"
    local path="$2"
    local expected_status="$3"
    local bearer="${4:-}"
    local payload="${5:-}"
    local origin="${6:-}"
    local -a arguments=(
        --silent --show-error
        --connect-timeout 10 --max-time 90
        --request "$method"
        --dump-header "$response_headers"
        --output "$response_body"
        --write-out '%{http_code}'
        --header 'Accept: application/json'
    )
    if [[ -n "$bearer" ]]; then
        arguments+=(--header "Authorization: Bearer ${bearer}")
    fi
    if [[ -n "$payload" ]]; then
        arguments+=(--header 'Content-Type: application/json' --data "$payload")
    fi
    if [[ -n "$origin" ]]; then
        arguments+=(--header "Origin: ${origin}")
    fi

    response_status="$(curl "${arguments[@]}" "${api_base}${path}")"
    if [[ "$response_status" != "$expected_status" ]]; then
        fail "${method} ${path} returned HTTP ${response_status}; expected ${expected_status}: $(safe_response_summary)"
    fi
}

http_multipart() {
    local path="$1"
    local expected_status="$2"
    local bearer="$3"
    local image_file="$4"
    local session_id="$5"

    response_status="$(curl \
        --silent --show-error --connect-timeout 10 --max-time 90 \
        --request POST \
        --dump-header "$response_headers" \
        --output "$response_body" \
        --write-out '%{http_code}' \
        --header 'Accept: application/json' \
        --header "Authorization: Bearer ${bearer}" \
        --form 'kind=stock' \
        --form "targetId=${session_id}" \
        --form 'transmissionConsent=true' \
        --form "image=@${image_file};type=image/png;filename=acceptance-stock.png" \
        "${api_base}${path}")"
    if [[ "$response_status" != "$expected_status" ]]; then
        fail "POST ${path} returned HTTP ${response_status}; expected ${expected_status}: $(safe_response_summary)"
    fi
}

assert_json() {
    local description="$1"
    local filter="$2"
    shift 2
    jq -e "$@" "$filter" "$response_body" >/dev/null \
        || fail "$description"
}

assert_problem_json() {
    grep -Eiq '^content-type:[[:space:]]*application/problem\+json' "$response_headers" \
        || fail 'A rejected request did not return application/problem+json.'
    assert_json 'A rejected request did not return a valid problem document.' \
        '.status == ($expected | tonumber) and (.title | type == "string") and (.requestId | type == "string")' \
        --arg expected "$response_status"
}

wait_for_api() {
    local attempt
    for attempt in $(seq 1 90); do
        if curl --fail --silent --show-error --max-time 2 "${api_base}/health/ready" >/dev/null 2>&1; then
            return
        fi
        sleep 1
    done
    fail 'The source-Compose API did not become ready.'
}

preflight_ai_fixture() {
    local fixture_status=0
    "${compose[@]}" exec --no-TTY api-sqlite php -r '
        require "/app/vendor/autoload.php";

        function diagnostic(string $stage, int $status, string $code, int $exitCode): never
        {
            $boundedCode = preg_match("/^[a-z0-9_]{1,64}$/D", $code) === 1
                ? $code
                : "unavailable";
            fwrite(
                STDERR,
                sprintf(
                    "AI_FIXTURE_PREFLIGHT stage=%s status=%d code=%s\n",
                    $stage,
                    $status,
                    $boundedCode,
                ),
            );
            exit($exitCode);
        }

        /** @param list<string> $headers */
        function responseHeader(array $headers, string $name): ?string
        {
            $prefix = strtolower($name) . ":";
            foreach ($headers as $header) {
                if (str_starts_with(strtolower($header), $prefix)) {
                    return trim(substr($header, strlen($prefix)));
                }
            }
            return null;
        }

        /** @param list<string> $headers */
        function jsonDiagnostic(
            string $stage,
            string $bytes,
            int $status,
            array $headers,
            int $exitCode,
        ): never {
            json_decode($bytes, true, 128);
            $jsonErrorCode = preg_replace(
                "/[^a-z0-9]+/",
                "_",
                strtolower(json_last_error_msg()),
            );
            $jsonErrorCode = is_string($jsonErrorCode)
                ? trim($jsonErrorCode, "_")
                : "unavailable";
            $expectedLength = responseHeader($headers, "X-Acceptance-Body-Length");
            $expectedSha256 = responseHeader($headers, "X-Acceptance-Body-Sha256");
            $expectedLength = is_string($expectedLength)
                && preg_match("/^[0-9]{1,10}$/D", $expectedLength) === 1
                    ? $expectedLength
                    : "unavailable";
            $expectedSha256 = is_string($expectedSha256)
                && preg_match("/^[a-f0-9]{64}$/D", $expectedSha256) === 1
                    ? $expectedSha256
                    : "unavailable";
            fwrite(
                STDERR,
                sprintf(
                    "AI_FIXTURE_PREFLIGHT stage=%s status=%d length=%d sha256=%s first=%s last=%s"
                        . " json_error=%d json_error_code=%s expected_length=%s expected_sha256=%s\n",
                    $stage,
                    $status,
                    strlen($bytes),
                    hash("sha256", $bytes),
                    bin2hex(substr($bytes, 0, 1)),
                    bin2hex(substr($bytes, -1)),
                    json_last_error(),
                    $jsonErrorCode,
                    $expectedLength,
                    $expectedSha256,
                ),
            );
            exit($exitCode);
        }

        $context = stream_context_create(["http" => [
            "method" => "GET",
            "header" => "Accept: application/json\r\nConnection: close",
            "timeout" => 10,
            "ignore_errors" => true,
            "follow_location" => 0,
            "max_redirects" => 0,
            "protocol_version" => 1.1,
        ]]);
        $stream = @fopen(
            "http://ai-fixture:8090/self-test",
            "rb",
            false,
            $context,
        );
        if ($stream === false) {
            diagnostic("transport", 0, "unreachable", 20);
        }
        try {
            $outer = stream_get_contents($stream, 1048577);
            $metadata = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }
        if (! is_string($outer) || $outer === "") {
            diagnostic("transport", 0, "empty_response", 20);
        }
        $headers = array_values(array_filter(
            is_array($metadata["wrapper_data"] ?? null) ? $metadata["wrapper_data"] : [],
            "is_string",
        ));
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match("/^HTTP\\/\\S+\\s+(\\d{3})/", $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        if ($status !== 200) {
            $fixtureCode = "http_error";
            try {
                $problem = json_decode($outer, true, 32, JSON_THROW_ON_ERROR);
                if (is_string($problem["error"]["type"] ?? null)) {
                    $fixtureCode = $problem["error"]["type"];
                }
            } catch (Throwable) {
                $fixtureCode = "invalid_error_envelope";
            }
            diagnostic("http", $status, $fixtureCode, 21);
        }
        try {
            $response = \Providentia\AiIntegration\Infrastructure\Http\ProviderJsonDecoder::httpResponse($outer);
        } catch (Throwable) {
            jsonDiagnostic("outer_json", $outer, $status, $headers, 22);
        }
        $content = $response["choices"][0]["message"]["content"] ?? null;
        if (! is_string($content)) {
            diagnostic("envelope", $status, "missing_content", 23);
        }
        try {
            $extraction = \Providentia\AiIntegration\Infrastructure\Http\ProviderJsonDecoder::structuredOutput(
                $content,
            );
        } catch (Throwable) {
            jsonDiagnostic("nested_json", $content, $status, [], 24);
        }
        if (
            ! is_array($extraction)
            || ($extraction["documentType"] ?? null) !== "stock"
            || ($extraction["candidates"][0]["quantityMinimum"] ?? null) !== "6"
            || ($extraction["candidates"][0]["quantityMaximum"] ?? null) !== "8"
        ) {
            diagnostic("semantics", $status, "unexpected_fixture_shape", 25);
        }
    ' || fixture_status=$?
    [[ "$fixture_status" -eq 0 ]] \
        || fail "The deterministic provider self-test failed at bounded stage ${fixture_status}."
}

wait_for_login_message() {
    local email="$1"
    local request_id="$2"
    local expected_base="$3"
    local messages_file="${evidence_dir}/mailpit-messages.json"
    local message_file="${evidence_dir}/mailpit-message.json"
    local message_id=''
    local message_text=''
    local expected_prefix="${expected_base}#requestId=${request_id}&approval="
    local link=''
    local attempt

    for attempt in $(seq 1 120); do
        if curl --fail --silent --show-error --max-time 5 \
            "${mailpit_base}/api/v1/messages" >"$messages_file"; then
            message_id="$(jq -r --arg email "$email" '
                [.messages[]?
                    | select(any(.To[]?; (.Address | ascii_downcase) == ($email | ascii_downcase)))
                    | select(.Subject == "Approve your Providentia login")][0].ID // empty
            ' "$messages_file")"
            if [[ -n "$message_id" ]]; then
                break
            fi
        fi
        sleep 0.5
    done
    [[ -n "$message_id" ]] || fail 'Mailpit did not receive the application-bound login link.'
    curl --fail --silent --show-error --max-time 5 \
        "${mailpit_base}/api/v1/message/${message_id}" >"$message_file"
    message_text="$(jq -er '.Text' "$message_file")" \
        || fail 'Mailpit returned no plain-text login message.'
    while IFS= read -r line; do
        line="${line%$'\r'}"
        if [[ "$line" == "${expected_prefix}"* ]]; then
            link="$line"
            break
        fi
    done <<<"$message_text"
    [[ -n "$link" ]] || fail 'The login email did not use the configured application-owned base.'
    login_approval_token="${link#"$expected_prefix"}"
    [[ "$login_approval_token" =~ ^[A-Za-z0-9_-]{40,128}$ ]] \
        || fail 'The approval capability in the application fragment has an invalid shape.'
    if [[ "$message_text" == *'/auth#requestId='* || "$message_text" == *'?approval='* ]]; then
        fail 'The login email used a stale path or query-string capability.'
    fi
    rm -f "$messages_file" "$message_file"
}

login_link_session() {
    local application_kind="$1"
    local email="$2"
    local expected_base="$3"
    local platform="$4"
    local request_id
    local installation_id
    local poll_token
    local poll_challenge
    local code_verifier
    local code_challenge
    local state
    local other_kind
    local request_body
    local approval_body
    local decision_body
    local status_body
    local exchange_body

    request_id="$(uuid)"
    installation_id="$(uuid)"
    poll_token="$(base64url_random)"
    poll_challenge="$(s256 "$poll_token")"
    code_verifier="$(base64url_random)"
    code_challenge="$(s256 "$code_verifier")"
    state="$(base64url_random)"
    other_kind='homeowner'
    [[ "$application_kind" == 'homeowner' ]] && other_kind='admin'

    request_body="$(jq -cn \
        --arg requestId "$request_id" \
        --arg email "$email" \
        --arg applicationKind "$application_kind" \
        --arg pollChallenge "$poll_challenge" \
        --arg codeChallenge "$code_challenge" \
        --arg state "$state" \
        --arg installationId "$installation_id" \
        --arg platform "$platform" '
        {
            requestId:$requestId,
            email:$email,
            applicationKind:$applicationKind,
            pollChallenge:$pollChallenge,
            codeChallenge:$codeChallenge,
            codeChallengeMethod:"S256",
            state:$state,
            installationId:$installationId,
            deviceName:"Headless acceptance",
            platform:$platform,
            transport:"native"
        }
    ')"
    http_json POST '/api/v1/auth/login-links' 202 '' "$request_body"
    assert_json 'The login-link start response was not generic and client-bound.' \
        '.accepted == true and .requestId == $requestId and (.pollIntervalSeconds >= 1)' \
        --arg requestId "$request_id"

    wait_for_login_message "$email" "$request_id" "$expected_base"

    approval_body="$(jq -cn \
        --arg applicationKind "$other_kind" \
        --arg approvalToken "$login_approval_token" \
        '{applicationKind:$applicationKind,approvalToken:$approvalToken}')"
    http_json POST "/api/v1/auth/login-links/${request_id}/proof" 404 '' "$approval_body"
    assert_problem_json

    approval_body="$(jq -cn \
        --arg applicationKind "$application_kind" \
        --arg approvalToken "$login_approval_token" \
        '{applicationKind:$applicationKind,approvalToken:$approvalToken}')"
    http_json POST "/api/v1/auth/login-links/${request_id}/proof" 200 '' "$approval_body"
    assert_json 'The approval proof disclosed unexpected identity data or did not bind the application.' '
        .valid == true
        and .requestId == $requestId
        and .applicationKind == $applicationKind
        and (has("email") | not)
        and (has("account") | not)
        and (has("approvalToken") | not)
    ' --arg requestId "$request_id" --arg applicationKind "$application_kind"

    http_json POST "/api/v1/auth/login-links/${request_id}/review" 200 '' "$approval_body"
    assert_json 'The approval review was not privacy-minimal or application-bound.' '
        .requestId == $requestId
        and .applicationKind == $applicationKind
        and .deviceName == "Headless acceptance"
        and .platform == $platform
        and (has("email") | not)
        and (has("userId") | not)
    ' --arg requestId "$request_id" --arg applicationKind "$application_kind" --arg platform "$platform"

    decision_body="$(jq -cn \
        --arg applicationKind "$application_kind" \
        --arg approvalToken "$login_approval_token" \
        '{applicationKind:$applicationKind,approvalToken:$approvalToken,decision:"approve"}')"
    http_json POST "/api/v1/auth/login-links/${request_id}/decision" 202 '' "$decision_body"
    assert_json 'The login decision was not acknowledged generically.' \
        '.requestId == $requestId and .applicationKind == $applicationKind and .status == "received"' \
        --arg requestId "$request_id" --arg applicationKind "$application_kind"
    http_json POST "/api/v1/auth/login-links/${request_id}/decision" 202 '' "$decision_body"
    assert_json 'A replayed login decision was not acknowledged generically.' \
        '.requestId == $requestId and .applicationKind == $applicationKind and .status == "received"' \
        --arg requestId "$request_id" --arg applicationKind "$application_kind"

    status_body="$(jq -cn --arg pollToken "$poll_token" '{pollToken:$pollToken}')"
    http_json POST "/api/v1/auth/login-links/${request_id}/status" 200 '' "$status_body"
    assert_json 'The originating installation did not observe an approved login.' \
        '.requestId == $requestId and .applicationKind == $applicationKind and .status == "approved"' \
        --arg requestId "$request_id" --arg applicationKind "$application_kind"

    exchange_body="$(jq -cn \
        --arg pollToken "$poll_token" \
        --arg codeVerifier "$code_verifier" \
        --arg state "$state" \
        '{pollToken:$pollToken,codeVerifier:$codeVerifier,state:$state}')"
    http_json POST "/api/v1/auth/login-links/${request_id}/exchange" 200 '' "$exchange_body"
    assert_json 'The native login exchange did not return installation-bound credentials.' '
        .transport == "native"
        and .installationId == $installationId
        and (.accessToken | type == "string" and length >= 40)
        and (.refreshToken | type == "string" and length >= 40)
        and (.userId | type == "string")
    ' --arg installationId "$installation_id"
    login_access_token="$(jq -er '.accessToken' "$response_body")"
    login_user_id="$(jq -er '.userId' "$response_body")"
    login_active_home_id="$(jq -r '.activeHomeId // empty' "$response_body")"

    http_json POST "/api/v1/auth/login-links/${request_id}/exchange" 409 '' "$exchange_body"
    assert_problem_json
}

mkdir -p "$evidence_dir"
rm -f "$response_body" "$response_headers" "$runtime_log" "$summary_file"

for executable in docker curl jq openssl sha256sum; do
    command -v "$executable" >/dev/null 2>&1 || fail "Required executable is unavailable: ${executable}"
done
bash "${repo_root}/tool/materialize-openapi-contract.sh"
docker compose version >/dev/null
"${compose[@]}" config --quiet
"${compose[@]}" up --detach --build --wait --wait-timeout 240 \
    api-sqlite notification-sqlite mailpit ai-fixture
wait_for_api
preflight_ai_fixture

# The backend is JSON-only. Metrics are unavailable unless separately enabled
# with their dedicated credential, and CORS admits only the homeowner origin.
http_json GET '/' 404
assert_problem_json
assert_json 'The headless root returned an interactive document.' '
    .status == 404 and .title == "Not Found" and .instance == "/"
'
grep -Eiq '<(!doctype|html|form|script)' "$response_body" \
    && fail 'The headless root contained interactive markup.'
http_json GET '/metrics' 404
assert_problem_json
http_json OPTIONS '/api/v1/me' 204 '' '' 'https://app.example.invalid'
grep -Eiq '^access-control-allow-origin:[[:space:]]*https://app\.example\.invalid' "$response_headers" \
    || fail 'The configured homeowner PWA origin did not pass CORS.'
http_json OPTIONS '/api/v1/me' 403 '' '' 'https://untrusted.example.invalid'
assert_problem_json

# One login-link protocol serves both Flutter clients but the approval
# capability is cryptographically and operationally bound to its application.
login_link_session \
    admin acceptance-admin@example.test \
    'providentia-admin://login-link/admin' linux
admin_access_token="$login_access_token"
admin_user_id="$login_user_id"
[[ -z "$login_active_home_id" ]] || fail 'An Admin-only first login created a household.'

http_json GET '/api/v1/me' 200 "$admin_access_token"
assert_json 'The Admin bootstrap crossed the household boundary.' '
    .userId == $userId
    and .activeHomeId == null
    and .homes == []
    and (.platformRoles | index("platform_administrator") != null)
' --arg userId "$admin_user_id"

login_link_session \
    homeowner acceptance-homeowner@example.test \
    'https://app.example.invalid/homeowner' linux
homeowner_access_token="$login_access_token"
homeowner_user_id="$login_user_id"
home_id="$login_active_home_id"
[[ -n "$home_id" ]] || fail 'The homeowner login did not create its initial home.'

http_json GET '/api/v1/me' 200 "$homeowner_access_token"
assert_json 'The homeowner bootstrap was not a single private home without platform authority.' '
    .userId == $userId
    and .activeHomeId == $homeId
    and (.homes | length == 1)
    and (.platformRoles | length == 0)
' --arg userId "$homeowner_user_id" --arg homeId "$home_id"

http_json GET '/api/v1/admin/accounts?limit=1&offset=0' 403 "$homeowner_access_token"
assert_problem_json
http_json GET '/api/v1/catalog-contributions/review?status=pending&limit=50&offset=0' \
    404 "$homeowner_access_token"
assert_problem_json
http_json GET "/api/v1/homes/${home_id}/products" 404 "$admin_access_token"
assert_problem_json
http_json GET '/api/v1/admin/accounts?limit=1&offset=0' 200 "$admin_access_token"
assert_json 'Admin account pagination was not bounded or did not include a next page.' '
    .pagination.limit == 1
    and .pagination.offset == 0
    and .pagination.returned == 1
    and .pagination.total >= 2
    and .pagination.hasMore == true
    and .pagination.nextOffset == 1
'

# Billing remains explicitly non-enforcing during stabilization: household
# stock workflows stay available, while Admin can inspect the empty/draft
# operator catalog without enabling checkout or any payment provider.
http_json GET "/api/v1/homes/${home_id}/billing" 200 "$homeowner_access_token"
assert_json 'Free-phase billing unexpectedly gated the homeowner or implied a subscription.' '
    .subscription == null
    and .entitlements["billing.enforced"] == false
'
http_json GET '/api/v1/operator/billing/plans' 200 "$admin_access_token"
assert_json 'The Admin billing-plan read was unavailable during the free phase.' '
    .data | type == "array"
'

# Create one governed global category through the same Admin surface used by
# production moderation. It becomes the explicit target for promotion of the
# later consent-bound household product contribution.
category_proposal_body="$(jq -cn '
    {type:"category",payload:{canonicalName:"Acceptance pantry"}}
')"
http_json POST '/api/v1/catalog/proposals' \
    201 "$admin_access_token" "$category_proposal_body"
assert_json 'The global category proposal was not accepted for review.' '
    .status == "pending" and .revision == 1
'
category_proposal_id="$(jq -er '.id' "$response_body")"
catalog_decision_body="$(jq -cn '
    {decision:"approve",reason:"Acceptance category fixture",expectedRevision:1}
')"
http_json POST "/api/v1/catalog-admin/proposals/${category_proposal_id}/decision" \
    200 "$admin_access_token" "$catalog_decision_body"
assert_json 'The approved global category proposal did not publish a category.' '
    .status == "approved"
    and .entityType == "category"
    and (.entityId | type == "string" and length > 0)
'
published_category_id="$(jq -er '.entityId' "$response_body")"
http_json GET '/api/v1/catalog/categories?q=Acceptance%20pantry&limit=50&offset=0' 200
assert_json 'The approved global category was not publicly discoverable.' '
    .data | any(.id == $categoryId and .canonicalName == "Acceptance pantry")
' --arg categoryId "$published_category_id"

# Manual taxonomy remains home-private and usable before any sharing consent.
category_body="$(jq -cn '{name:"Acceptance pantry"}')"
http_json POST "/api/v1/homes/${home_id}/categories" 201 "$homeowner_access_token" "$category_body"
assert_json 'The private category was not created as a revisioned active record.' '
    .name == "Acceptance pantry" and .status == "active" and .revision == 1
'
home_category_id="$(jq -er '.id' "$response_body")"

product_body="$(jq -cn --arg homeCategoryId "$home_category_id" '
    {
        privateName:"Acceptance baked beans",
        originalPackText:"400 g tin",
        homeCategoryId:$homeCategoryId
    }
')"
http_json POST "/api/v1/homes/${home_id}/products" 201 "$homeowner_access_token" "$product_body"
home_product_id="$(jq -er '.id' "$response_body")"
http_json GET "/api/v1/homes/${home_id}/products?homeCategoryId=${home_category_id}" \
    200 "$homeowner_access_token"
assert_json 'The private product/category relationship was not queryable.' '
    .data
    | any(
        .homeProductId == $productId
        and .canonicalName == "Acceptance baked beans"
        and .homeCategoryId == $categoryId
        and .categorySource == "home"
    )
' --arg productId "$home_product_id" --arg categoryId "$home_category_id"

http_json GET "/api/v1/homes/${home_id}/catalog-contributions/consent" \
    200 "$homeowner_access_token"
assert_json 'A new home did not default to zero sharing.' '
    .revision == 0
    and .shareProductIdentity == false
    and .shareProductImages == false
    and .shareStorePrices == false
'

contribution_payload="$(jq -cn \
    --arg submissionId "$(uuid)" \
    --arg sourceEntityId "$home_product_id" '
    {
        submissionId:$submissionId,
        type:"product_identity",
        sourceEntityId:$sourceEntityId,
        expectedConsentRevision:1,
        payload:{
            canonicalName:"Acceptance baked beans",
            brand:"Providentia fixture",
            categoryLabel:"Acceptance pantry",
            packText:"400 g tin"
        }
    }
')"
http_json POST "/api/v1/homes/${home_id}/catalog-contributions" \
    409 "$homeowner_access_token" "$contribution_payload"
assert_problem_json
assert_json 'Catalog contribution was not rejected while consent was off.' \
    '.title == "Sharing consent required"'

consent_body="$(jq -cn '
    {
        shareProductIdentity:true,
        shareProductImages:false,
        shareStorePrices:false,
        noticeVersion:"catalog-sharing-v1",
        expectedRevision:0
    }
')"
http_json PUT "/api/v1/homes/${home_id}/catalog-contributions/consent" \
    200 "$homeowner_access_token" "$consent_body"
assert_json 'The field-scoped contribution consent was not revisioned.' '
    .revision == 1
    and .shareProductIdentity == true
    and .shareProductImages == false
    and .shareStorePrices == false
'

contribution_payload="$(jq -cn \
    --arg submissionId "$(uuid)" \
    --arg sourceEntityId "$home_product_id" '
    {
        submissionId:$submissionId,
        type:"product_identity",
        sourceEntityId:$sourceEntityId,
        expectedConsentRevision:1,
        payload:{
            canonicalName:"Acceptance baked beans",
            brand:"Providentia fixture",
            categoryLabel:"Acceptance pantry",
            packText:"400 g tin"
        }
    }
')"
http_json POST "/api/v1/homes/${home_id}/catalog-contributions" \
    201 "$homeowner_access_token" "$contribution_payload"
assert_json 'The opted-in contribution was not pending and revision-bound.' '
    .contributionType == "product_identity" and .status == "pending" and .revision == 1
'
contribution_id="$(jq -er '.id' "$response_body")"

http_json GET '/api/v1/catalog-contributions/review?status=pending&limit=50&offset=0' \
    200 "$admin_access_token"
assert_json 'The Admin moderation queue omitted the opted-in contribution.' '
    .data | any(.id == $contributionId and .revision == 1 and .status == "pending")
' --arg contributionId "$contribution_id"
assert_json 'The moderation projection leaked household or user attribution.' '
    [.data[]? | .. | objects | keys[]]
    | all(. != "homeId" and . != "sourceEntityId" and . != "userId" and . != "email")
'

decision_body="$(jq -cn '
    {decision:"approved",reason:"Acceptance fixture is safe",expectedRevision:1}
')"
http_json PUT "/api/v1/catalog-contributions/${contribution_id}/decision" \
    204 "$admin_access_token" "$decision_body"
http_json GET '/api/v1/catalog-contributions/review?status=approved&limit=50&offset=0' \
    200 "$admin_access_token"
assert_json 'The approved contribution was not recoverable with its current moderation revision.' '
    .data | any(.id == $contributionId and .status == "approved" and .revision >= 2)
' --arg contributionId "$contribution_id"
approved_contribution_revision="$(jq -er \
    --arg contributionId "$contribution_id" \
    '.data[] | select(.id == $contributionId) | .revision' \
    "$response_body")"
http_json GET '/api/v1/catalog-contributions?type=product_identity&limit=50&offset=0' 200
assert_json 'The approved contribution did not enter the attribution-free global feed.' '
    .data
    | any(
        .contributionType == "product_identity"
        and .payload.canonicalName == "Acceptance baked beans"
        and .payload.brand == "Providentia fixture"
    )
'
assert_json 'The published contribution exposed non-public provenance.' '
    [.data[]? | .. | objects | keys[]]
    | all(. != "homeId" and . != "sourceEntityId" and . != "userId" and . != "email")
'

promotion_body="$(jq -cn \
    --arg publishedCategoryId "$published_category_id" \
    --argjson expectedRevision "$approved_contribution_revision" '
    {publishedCategoryId:$publishedCategoryId,expectedRevision:$expectedRevision}
')"
http_json PUT "/api/v1/catalog-contributions/${contribution_id}/proposal" \
    200 "$admin_access_token" "$promotion_body"
assert_json 'The approved contribution did not create a durable governed product proposal.' '
    .contributionId == $contributionId
    and .contributionRevision == $contributionRevision
    and .publishedCategoryId == $categoryId
    and .proposalStatus == "pending"
' \
    --arg contributionId "$contribution_id" \
    --argjson contributionRevision "$approved_contribution_revision" \
    --arg categoryId "$published_category_id"
product_proposal_id="$(jq -er '.proposalId' "$response_body")"
catalog_decision_body="$(jq -cn '
    {decision:"approve",reason:"Acceptance product fixture",expectedRevision:1}
')"
http_json POST "/api/v1/catalog-admin/proposals/${product_proposal_id}/decision" \
    200 "$admin_access_token" "$catalog_decision_body"
assert_json 'Admin approval did not publish the contribution into the global product catalog.' '
    .status == "approved"
    and .entityType == "product"
    and (.entityId | type == "string" and length > 0)
'
published_product_id="$(jq -er '.entityId' "$response_body")"
http_json GET '/api/v1/catalog/products?q=Acceptance%20baked%20beans&limit=50&offset=0' 200
assert_json 'The promoted contribution was not discoverable through global catalog search.' '
    .data
    | any(
        .id == $productId
        and .canonicalName == "Acceptance baked beans"
        and .brand == "Providentia fixture"
        and .category == "Acceptance pantry"
    )
' --arg productId "$published_product_id"
http_json GET "/api/v1/catalog/products/${published_product_id}" 200
assert_json 'The promoted contribution did not resolve through the global item-master detail API.' '
    .id == $productId
    and .canonicalName == "Acceptance baked beans"
    and .brand == "Providentia fixture"
    and .categoryId == $categoryId
    and .category == "Acceptance pantry"
' --arg productId "$published_product_id" --arg categoryId "$published_category_id"

# A stock photo produces only a proposal. The write-only credential can be
# replaced, and no count or balance changes before an explicit human commit.
count_body="$(jq -cn '
    {locationId:null,notes:"Acceptance photo count",scopeComplete:false,reliability:"unassessed"}
')"
http_json POST "/api/v1/homes/${home_id}/stock-count-sessions" \
    201 "$homeowner_access_token" "$count_body"
assert_json 'The created stock-count session omitted API 1.18 required fields.' '
    .homeId == $homeId
    and .status == "open"
    and .revision == 1
    and .lines == []
' --arg homeId "$home_id"
count_session_id="$(jq -er '.id' "$response_body")"

http_json GET "/api/v1/homes/${home_id}/ai/settings" 200 "$homeowner_access_token"
assert_json 'The AI privacy baseline or deterministic provider was unavailable.' '
    .mode == "manual_only"
    and .revision == 0
    and .humanReviewRequired == true
    and .credentialEncryptionAvailable == true
    and .mediaHandling.directExtractionUpload == "transient_not_persisted"
    and (.availableServerProviders | any(.id == "openai-compatible" and .requiresCredential == true))
'

initial_credential='acceptance-ai-token-initial-1111'
credential_body="$(jq -cn --arg credential "$initial_credential" '{credential:$credential}')"
http_json PUT "/api/v1/homes/${home_id}/ai/credentials/openai-compatible" \
    200 "$homeowner_access_token" "$credential_body"
assert_json 'The initial write-only credential status was incorrect.' '
    .provider == "openai-compatible" and .configured == true and .lastFour == "1111"
'
grep -Fq "$initial_credential" "$response_body" \
    && fail 'The AI credential was returned after storage.'

replacement_credential='acceptance-ai-token-replacement-2222'
credential_body="$(jq -cn --arg credential "$replacement_credential" '{credential:$credential}')"
http_json PUT "/api/v1/homes/${home_id}/ai/credentials/openai-compatible" \
    200 "$homeowner_access_token" "$credential_body"
assert_json 'The replacement write-only credential status was incorrect.' '
    .provider == "openai-compatible" and .configured == true and .lastFour == "2222"
'
grep -Fq "$replacement_credential" "$response_body" \
    && fail 'The replacement AI credential was returned after storage.'

settings_body="$(jq -cn '
    {mode:"server_proxy",provider:"openai-compatible",model:"acceptance-vision",expectedRevision:0}
')"
http_json PUT "/api/v1/homes/${home_id}/ai/settings" \
    200 "$homeowner_access_token" "$settings_body"
assert_json 'The server-side AI provider was not enabled revision-safely.' '
    .mode == "server_proxy"
    and .provider == "openai-compatible"
    and .model == "acceptance-vision"
    and .revision == 1
'

image_file="${evidence_dir}/acceptance-stock.png"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' \
    | openssl base64 -d -A >"$image_file"
http_multipart "/api/v1/homes/${home_id}/ai/extractions" \
    201 "$homeowner_access_token" "$image_file" "$count_session_id"
assert_json 'The stock image did not produce one mandatory-review proposal.' '
    .status == "review_required" and .candidateCount == 1 and .observationCount == 1
'
extraction_id="$(jq -er '.id' "$response_body")"
rm -f "$image_file"

http_json GET "/api/v1/homes/${home_id}/ai/extractions/${extraction_id}" \
    200 "$homeowner_access_token"
assert_json 'The AI proposal did not preserve the API 1.18 quantity range.' '
    .schemaVersion == 2
    and .status == "review_required"
    and (.candidates | length == 1)
    and .candidates[0].reviewStatus == "pending"
    and .candidates[0].revision == 1
    and .candidates[0].payload.quantity == null
    and .candidates[0].payload.quantityMinimum == "6"
    and .candidates[0].payload.quantityMaximum == "8"
'
http_json GET "/api/v1/homes/${home_id}/stock-count-sessions/${count_session_id}" \
    200 "$homeowner_access_token"
assert_json 'AI extraction mutated the count session before review.' \
    '.status == "open" and (.lines | length == 0)'

review_body="$(jq -cn '{decision:"accepted",expectedRevision:1}')"
http_json PUT "/api/v1/homes/${home_id}/ai/extractions/${extraction_id}/candidates/0" \
    204 "$homeowner_access_token" "$review_body"
http_json GET "/api/v1/homes/${home_id}/stock-count-sessions/${count_session_id}" \
    200 "$homeowner_access_token"
assert_json 'Accepting an AI proposal mutated inventory automatically.' \
    '.status == "open" and (.lines | length == 0)'

count_line_id="$(uuid)"
line_body="$(jq -cn --arg homeProductId "$home_product_id" '
    {
        homeProductId:$homeProductId,
        quantity:"7",
        confidence:"0.93",
        source:"photo-confirmed",
        notes:"Human-confirmed from the bounded AI proposal",
        expectedRevision:0
    }
')"
http_json PUT "/api/v1/homes/${home_id}/stock-count-sessions/${count_session_id}/lines/${count_line_id}" \
    200 "$homeowner_access_token" "$line_body"
assert_json 'The explicit photo-confirmed count did not return a full API 1.18 line.' '
    .id == $lineId
    and .homeProductId == $productId
    and .quantity == "7"
    and .confidence == "0.93"
    and .source == "photo-confirmed"
    and .status == "confirmed"
    and .revision == 1
' --arg lineId "$count_line_id" --arg productId "$home_product_id"

close_body="$(jq -cn '{expectedRevision:2}')"
http_json POST "/api/v1/homes/${home_id}/stock-count-sessions/${count_session_id}/close" \
    200 "$homeowner_access_token" "$close_body"
assert_json 'Closing the count did not return the full API 1.18 session.' '
    .id == $sessionId
    and .homeId == $homeId
    and .status == "closed"
    and .revision == 3
    and (.closedAt | type == "string" and length > 0)
    and (.lines | length == 1)
    and .lines[0].id == $lineId
' --arg sessionId "$count_session_id" --arg homeId "$home_id" --arg lineId "$count_line_id"
http_json GET "/api/v1/homes/${home_id}/stock?homeCategoryId=${home_category_id}" \
    200 "$homeowner_access_token"
assert_json 'The explicit human count did not commit the inventory balance.' '
    .data | any(.homeProductId == $productId and (.quantity | tonumber) == 7)
' --arg productId "$home_product_id"

# Admin can inspect account metadata and suspend/reactivate the account without
# gaining access to its household data. Suspension invalidates every session.
http_json GET "/api/v1/admin/accounts/${homeowner_user_id}" 200 "$admin_access_token"
assert_json 'Admin account detail exposed no revision for controlled mutation.' \
    '.userId == $userId and .status == "active" and .revision >= 1' \
    --arg userId "$homeowner_user_id"
account_revision="$(jq -er '.revision' "$response_body")"
suspend_body="$(jq -cn --argjson expectedRevision "$account_revision" '
    {status:"suspended",reason:"Acceptance boundary verification",expectedRevision:$expectedRevision}
')"
http_json PATCH "/api/v1/admin/accounts/${homeowner_user_id}/status" \
    200 "$admin_access_token" "$suspend_body"
assert_json 'Admin could not suspend the homeowner account revision-safely.' \
    '.status == "suspended" and .revision == ($expectedRevision + 1)' \
    --argjson expectedRevision "$account_revision"
suspended_revision="$(jq -er '.revision' "$response_body")"
http_json GET '/api/v1/me' 401 "$homeowner_access_token"
assert_problem_json

activate_body="$(jq -cn --argjson expectedRevision "$suspended_revision" '
    {status:"active",reason:"Acceptance boundary verified",expectedRevision:$expectedRevision}
')"
http_json PATCH "/api/v1/admin/accounts/${homeowner_user_id}/status" \
    200 "$admin_access_token" "$activate_body"
assert_json 'Admin could not reactivate the homeowner account revision-safely.' \
    '.status == "active" and .revision == ($expectedRevision + 1)' \
    --argjson expectedRevision "$suspended_revision"

# Scan the complete runtime log and retain only a sanitized, token-free summary.
"${compose[@]}" logs --no-color >"$runtime_log"
if grep -Eq \
    'approval=[A-Za-z0-9_-]{20,}|"(pollToken|codeVerifier|accessToken|refreshToken|approvalToken)"[[:space:]]*:|acceptance-ai-token-(initial|replacement)-[0-9]+' \
    "$runtime_log"; then
    fail 'A capability or credential appeared in the deployed runtime logs.'
fi
rm -f "$runtime_log" "$response_body" "$response_headers"

contract_version="$(jq -er '.info.version' "${repo_root}/contracts/openapi/providentia-v1.json")"
contract_sha256="$(sha256sum "${repo_root}/contracts/openapi/providentia-v1.json" | awk '{print $1}')"
jq -n \
    --arg contractVersion "$contract_version" \
    --arg contractSha256 "$contract_sha256" '
    {
        contractVersion:$contractVersion,
        contractSha256:$contractSha256,
        headlessJsonOnly:true,
        metricsDisabledByDefault:true,
        applicationBoundLoginLinks:true,
        adminHasNoHousehold:true,
        privateCatalog:true,
        freePhaseBillingNotEnforced:true,
        consentBoundContribution:true,
        attributionFreeModeration:true,
        contributionPromotedToGlobalCatalog:true,
        writeOnlyAiCredentialReplacement:true,
        quantityRangeProposal:true,
        noMutationBeforeHumanCommit:true,
        photoConfirmedInventoryQuantity:"7",
        accountControlBoundary:true,
        runtimeLogSecretsDetected:false
    }
' >"$summary_file"

printf 'Headless platform acceptance passed against API %s (%s).\n' \
    "$contract_version" "$contract_sha256"
