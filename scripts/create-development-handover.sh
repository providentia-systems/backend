#!/usr/bin/env bash

set -Eeuo pipefail

pantry_data=""
product_rules=""
output=""
archive_name='Pantry_Stock_Project_Handover_2026-07-29'
pantry_data_sha='ac2a74f267d7a48a460c8fae24515887f97632cddfb4a17f5f45dd07c9e90116'
product_rules_sha='8131bd3bf41c9b70f0e4cfe86c9e7de699ca0df827c6287fc9f2927e35827899'

usage() {
    cat <<'EOF'
Usage: bash scripts/create-development-handover.sh \
  --pantry-data /secure/path/pantry-data.json \
  --product-rules /secure/path/product-rules.json \
  --output /secure/path/Pantry_Stock_Project_Handover_2026-07-29.zip

Create the minimal checksum-pinned archive accepted by setup-development.sh.
This does not recreate the full historical project handover.
EOF
}

while (($#)); do
    case "$1" in
        --pantry-data) pantry_data="${2:?--pantry-data requires a path}"; shift 2 ;;
        --product-rules) product_rules="${2:?--product-rules requires a path}"; shift 2 ;;
        --output) output="${2:?--output requires a path}"; shift 2 ;;
        --help|-h) usage; exit 0 ;;
        *) printf 'Unknown argument: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done

for command_name in cp mkdir mktemp sha256sum zip; do
    command -v "$command_name" >/dev/null 2>&1 || {
        printf 'Required command is unavailable: %s\n' "$command_name" >&2
        exit 1
    }
done

[[ -f "$pantry_data" ]] || {
    printf 'Verified pantry-data.json not found: %s\n' "$pantry_data" >&2
    exit 1
}
[[ -f "$product_rules" ]] || {
    printf 'Verified product-rules.json not found: %s\n' "$product_rules" >&2
    exit 1
}
[[ -n "$output" ]] || {
    printf '%s\n' '--output is required.' >&2
    usage >&2
    exit 2
}

if [[ "$output" != /* ]]; then
    output="$(pwd)/${output}"
fi
if [[ -e "$output" ]]; then
    printf 'Refusing to overwrite existing output: %s\n' "$output" >&2
    exit 1
fi

printf '%s  %s\n' "$pantry_data_sha" "$pantry_data" \
    | sha256sum --check --status || {
        printf 'pantry-data.json does not match the verified Phase 0 checksum.\n' >&2
        exit 1
    }
printf '%s  %s\n' "$product_rules_sha" "$product_rules" \
    | sha256sum --check --status || {
        printf 'product-rules.json does not match the verified Phase 0 checksum.\n' >&2
        exit 1
    }

mkdir -p -- "$(dirname -- "$output")"
scratch_dir="$(mktemp -d)"
trap 'rm -rf -- "$scratch_dir"' EXIT
archive_root="${scratch_dir}/${archive_name}/03_data_exports"
mkdir -p -- "$archive_root"
cp -- "$pantry_data" "${archive_root}/pantry-data.json"
cp -- "$product_rules" "${archive_root}/product-rules.json"

(
    cd "$scratch_dir"
    zip -q -r "$output" "$archive_name"
)
chmod 0600 "$output"

printf 'Created minimal development handover: %s\n' "$output"
