#!/usr/bin/env bash

# Sourced only by local setup tooling. Use the actual email channel; the API
# never exposes a verification code or an authentication bypass.
development_require_loopback() {
    local origin="$1"
    if [[ ! "$origin" =~ ^https?://(127\.0\.0\.1|localhost|\[::1\])(:([0-9]{1,5}))?$ ]]; then
        printf 'Development provisioning requires a loopback origin.\n' >&2
        return 1
    fi
    if [[ -n "${BASH_REMATCH[3]:-}" ]] \
        && ((10#${BASH_REMATCH[3]} < 1 || 10#${BASH_REMATCH[3]} > 65535)); then
        printf 'Development provisioning origin has an invalid port.\n' >&2
        return 1
    fi
}

development_api_json() (
    set +x
    local method="$1" origin="$2" path="$3" expected="$4"
    local token="${5:-}" payload="${6:-}" exchange status response
    development_require_loopback "$origin" || return
    local arguments=(--silent --show-error --noproxy '*' --connect-timeout 5
        --max-time 30 --write-out $'\n%{http_code}' -X "$method")
    [[ -z "$token" ]] || arguments+=(-H "Authorization: Bearer ${token}")
    if [[ -n "$payload" ]]; then
        arguments+=(-H 'Content-Type: application/json' --data-binary @-)
    fi
    exchange="$(curl "${arguments[@]}" "${origin}${path}" <<<"$payload")" || return
    status="${exchange##*$'\n'}"
    response="${exchange%$'\n'*}"
    if [[ "$status" != "$expected" ]]; then
        printf 'Development API request %s %s failed (HTTP %s).\n' "$method" "$path" "$status" >&2
        if [[ "$status" == '429' ]]; then
            printf 'Respect the email-code resend cooldown; wait before rerunning setup.\n' >&2
        elif [[ "$status" == '403' ]]; then
            printf 'Check the account or home group permissions in Admin.\n' >&2
        fi
        return 1
    fi
    printf '%s' "$response"
)

development_email_code_session() (
    set +x
    local origin="$1" mailbox="$2" email="$3" installation="$4" device="$5"
    local application="${6:-homeowner}" before challenge messages message_id='' message_text code
    local before_ids payload session attempt
    development_require_loopback "$origin" || return
    development_require_loopback "$mailbox" || return
    before="$(curl --fail --silent --show-error --noproxy '*' --connect-timeout 5 --max-time 10 \
        "${mailbox}/api/v1/messages?limit=1000")" || return
    before_ids="$(jq -ce '[.messages[]?.ID]' <<<"$before")" || return
    payload="$(jq -cn --arg email "$email" --arg applicationKind "$application" \
        --arg installationId "$installation" --arg deviceName "$device" \
        '{email:$email,applicationKind:$applicationKind,installationId:$installationId,
          deviceName:$deviceName,platform:"linux",transport:"native"}')" || return
    challenge="$(development_api_json POST "$origin" '/api/v1/auth/email-codes' 202 '' "$payload")" || return
    jq -e '.challengeId and .bindingToken and (has("code") | not)' <<<"$challenge" >/dev/null || return
    for ((attempt = 0; attempt < 120; attempt++)); do
        messages="$(curl --fail --silent --show-error --noproxy '*' --connect-timeout 5 --max-time 5 \
            "${mailbox}/api/v1/messages?limit=1000")" || return
        message_id="$(jq -r --arg email "$email" --argjson before "$before_ids" '
            [.messages[]? | select(.ID as $id | $before | index($id) | not)
             | select(any(.To[]?; (.Address | ascii_downcase) == ($email | ascii_downcase)))
             | select(.Subject == "Your Providentia verification code")][0].ID // empty
        ' <<<"$messages")" || return
        [[ -z "$message_id" ]] || break
        sleep 0.5
    done
    if [[ -z "$message_id" ]]; then
        printf 'Mailpit did not receive the new verification email; inspect the notification worker.\n' >&2
        return 1
    fi
    [[ "$message_id" =~ ^[A-Za-z0-9-]+$ ]] || return 1
    message_text="$(curl --fail --silent --show-error --noproxy '*' --connect-timeout 5 --max-time 10 \
        "${mailbox}/api/v1/message/${message_id}" | jq -er '.Text')" || return
    code="$(printf '%s\n' "$message_text" | tr -d '\r' | sed -n '/^[0-9]\{8\}$/p')"
    if [[ ! "$code" =~ ^[0-9]{8}$ ]]; then
        printf 'The verification email did not contain exactly one eight-digit code.\n' >&2
        return 1
    fi
    payload="$(jq -cn --argjson challenge "$challenge" --arg code "$code" \
        '{challengeId:$challenge.challengeId,bindingToken:$challenge.bindingToken,code:$code}')" || return
    session="$(development_api_json POST "$origin" '/api/v1/auth/email-codes/verify' 200 '' "$payload")" || return
    jq -e --arg installation "$installation" \
        '.accessToken and .refreshToken and .userId and .installationId == $installation' \
        <<<"$session" >/dev/null || return
    printf '%s' "$session"
)

development_complete_onboarding() (
    set +x
    local origin="$1" token="$2" name="$3" country="${4:-NA}" profile policy payload result
    [[ "$country" =~ ^[A-Z]{2}$ ]] || return 1
    profile="$(development_api_json GET "$origin" '/api/v1/me/profile' 200 "$token")" || return
    if jq -e '.onboardingComplete == true' <<<"$profile" >/dev/null; then
        return 0
    fi
    policy="$(development_api_json GET "$origin" "/api/v1/countries/${country}/policy" 200)" || return
    payload="$(jq -cn --arg displayName "$name" --arg countryCode "$country" \
        --argjson profile "$profile" --argjson policy "$policy" \
        '{displayName:$displayName,countryCode:$countryCode,policyAccepted:true,
          policyId:$policy.id,policyRevision:$policy.revision,expectedRevision:$profile.revision}')" || return
    result="$(development_api_json POST "$origin" '/api/v1/me/onboarding' 200 "$token" "$payload")" || return
    jq -e '.onboardingComplete == true' <<<"$result" >/dev/null
)
