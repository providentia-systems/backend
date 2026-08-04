#!/usr/bin/env bash
set -Eeuo pipefail

base_url="${PROVIDENTIA_BASE_URL:?PROVIDENTIA_BASE_URL is required}"
base_url="${base_url%/}"

if [[ ! "$base_url" =~ ^https?://[A-Za-z0-9._:-]+$ ]] || [[ "$base_url" == *"@"* ]]; then
  printf 'PROVIDENTIA_BASE_URL must be an origin without credentials, path, query, or fragment.\n' >&2
  exit 2
fi
if [[ "$base_url" == http://* ]] && [[ "${PROVIDENTIA_ALLOW_HTTP:-0}" != "1" ]]; then
  printf 'Plain HTTP is accepted only when PROVIDENTIA_ALLOW_HTTP=1 is explicit.\n' >&2
  exit 2
fi

evidence_directory="$(mktemp -d)"
trap 'rm -rf "$evidence_directory"' EXIT

request() {
  local name="$1"
  local path="$2"
  local expected_status="$3"
  local status

  status="$(curl \
    --silent --show-error \
    --connect-timeout 5 --max-time 15 \
    --output "$evidence_directory/${name}.body" \
    --dump-header "$evidence_directory/${name}.headers" \
    --write-out '%{http_code}' \
    "$base_url$path")"
  if [[ "$status" != "$expected_status" ]]; then
    printf '%s returned HTTP %s; expected %s.\n' "$path" "$status" "$expected_status" >&2
    sed -n '1,40p' "$evidence_directory/${name}.body" >&2
    exit 1
  fi
}

request live /health/live 200
request ready /health/ready 200
request system /api/v1/system/info 200
request metrics /metrics 404

grep -Eq '"status"[[:space:]]*:[[:space:]]*"alive"' "$evidence_directory/live.body"
grep -Eq '"status"[[:space:]]*:[[:space:]]*"ready"' "$evidence_directory/ready.body"
grep -Eiq '^x-content-type-options:[[:space:]]*nosniff' "$evidence_directory/live.headers"
grep -Eiq '^x-frame-options:[[:space:]]*DENY' "$evidence_directory/live.headers"
grep -Eiq '^cache-control:[[:space:]]*no-store' "$evidence_directory/live.headers"
if grep -Eiq '^server:' "$evidence_directory/live.headers"; then
  printf 'The public response exposes a Server header.\n' >&2
  exit 1
fi

printf 'Production HTTP smoke passed for %s.\n' "$base_url"
