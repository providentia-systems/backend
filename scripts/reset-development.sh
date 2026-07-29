#!/usr/bin/env bash

set -Eeuo pipefail

root_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${root_dir}/.env.development.local"

if [[ "${1:-}" != '--confirm-destroy-local-data' || $# -ne 1 ]]; then
    printf 'Refusing to delete data. Re-run exactly with --confirm-destroy-local-data.\n' >&2
    exit 2
fi

cd "$root_dir"
docker compose --env-file "$env_file" \
    --profile sqlite --profile mysql --profile mariadb \
    --profile redis --profile valkey \
    down --volumes --remove-orphans

printf 'Removed Providentia development containers and named data volumes.\n'
printf 'The local secrets file remains at %s and can be removed manually if required.\n' "$env_file"
