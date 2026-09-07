#!/usr/bin/env bash
# Explicit installation/upgrade of a release; never a periodic auto-updater.
set -Eeuo pipefail
umask 077

root_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${root_dir}/.env.production.local"
prepare_only=0
options=()
usage() {
    cat <<'EOF'
Usage: bash scripts/setup-production.sh [options]

Prepare a protected configuration, then deploy a specific production release.
Run --prepare-only, fill MAIL_DSN in that file, and rerun with --env-file.

  --env-file PATH       Persistent secrets file (default: .env.production.local)
  --image-env PATH      Official release images.env with three immutable digests
  --version X.Y.Z       Select matching release tags instead of a digest lock
  --public-url URL      Stable HTTPS origin, e.g. https://api.your-domain.tld
  --mail-from EMAIL     Sender address verified by your SMTP provider
  --mail-dsn DSN        Authenticated smtps:// DSN; prefer editing the env file
  --trusted-proxies CIDRS  Space-separated exact TLS reverse-proxy networks
  --database ENGINE    mysql (default), mariadb, or external on first setup
  --database-url DSN   Existing SQL endpoint; requires --database external
  --queue-dsn DSN      Existing Redis endpoint; disables the bundled Redis
  --data-directory DIR Bind persistence under an absolute host parent directory
  --prepare-only       Write configuration only; do not contact Docker or SMTP
  --help               Show this help

Requires Bash, Python 3, flock, and Docker Compose v2 with up --wait support.
Existing secrets and data are preserved. Back up SQL, app-var and this env file
before upgrades. Deployment stops application processes, explicitly migrates
once, then restarts them. Failed migrations leave applications stopped for
operator recovery; never reset volumes or blindly roll schema backward.
EOF
}
while (($#)); do
    case "$1" in
        --env-file) env_file="${2:?--env-file requires a path}"; shift 2 ;;
        --version|--image-env|--public-url|--mail-from|--mail-dsn|--trusted-proxies|--database|--database-url|--queue-dsn|--data-directory)
            options+=("$1" "${2:?The option requires a value}"); shift 2 ;;
        --prepare-only) prepare_only=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done
for command_name in python3 flock; do
    command -v "$command_name" >/dev/null || { printf 'Required command missing: %s\n' "$command_name" >&2; exit 1; }
done
mkdir -p -- "$(dirname -- "$env_file")"
# Prevent concurrent upgrades, including two first-time setup invocations.
exec 9>"${env_file}.lock"
flock -n 9 || { printf 'Another production setup is using this env file.\n' >&2; exit 1; }
helper="${root_dir}/scripts/lib/production-env.py"
if [[ ! -f "$env_file" && "$prepare_only" -eq 0 ]]; then
    printf 'Create the new configuration with --prepare-only first; review SMTP, URL and persistence before deployment.\n' >&2
    exit 2
fi
python3 "$helper" prepare --env-file "$env_file" --example "${root_dir}/.env.production.example" "${options[@]}"
if ((prepare_only)); then
    printf 'Production configuration prepared: %s (mode 0600).\n' "$env_file"
    printf 'Review MAIL_DSN, public URL, proxy CIDRs, storage and external service credentials, then rerun with --env-file.\n'
    exit 0
fi
python3 "$helper" validate --env-file "$env_file"
command -v docker >/dev/null || { printf 'Docker is required for deployment.\n' >&2; exit 1; }
docker compose version >/dev/null || { printf 'Docker Compose v2 is required.\n' >&2; exit 1; }
metadata="$(python3 "$helper" metadata --env-file "$env_file")"
mapfile -t settings <<<"$metadata"
release="${settings[0]}"
database="${settings[1]}"
managed_redis="${settings[2]}"
data_directory="${settings[3]:-}"
setup_started="${settings[4]:-0}"
# The reviewed env file is authoritative. Clear only its keys and Compose's
# ambient overrides; preserve DOCKER_HOST/context/auth for the chosen daemon.
keys="$(python3 "$helper" keys --env-file "$env_file")"
clean_environment=(env)
while IFS= read -r key; do
    clean_environment+=(-u "$key")
done <<<"$keys"
while IFS= read -r key; do
    if [[ "$key" == COMPOSE_* ]]; then clean_environment+=(-u "$key"); fi
done < <(compgen -e)
compose=("${clean_environment[@]}" docker compose --env-file "$env_file" -f "${root_dir}/compose.production.yaml")
if [[ -n "$data_directory" ]]; then
    compose+=(-f "${root_dir}/compose.production.bind.yaml")
fi
# config --quiet validates without printing interpolated secrets.
"${compose[@]}" config --quiet
if [[ "$setup_started" != 1 ]]; then
    existing_volumes="$(docker volume ls --quiet --filter label=com.docker.compose.project=providentia-production)"
    if [[ -n "$existing_volumes" ]]; then
        printf 'Existing production volumes found with a new configuration. Restore the matching original env file and encryption keys before proceeding.\n' >&2
        exit 1
    fi
    # Mark before creating volumes so interrupted first deployment can retry
    # with these same secrets, but a newly generated env cannot adopt old data.
    python3 "$helper" mark-started --env-file "$env_file"
fi
python3 "$helper" directories --env-file "$env_file"
services=(api web worker outbox notification reference-update data-governance sync-compactor ai-video-worker)
infrastructure=()
[[ "$database" == external ]] || infrastructure+=("$database")
[[ "$managed_redis" == 0 ]] || infrastructure+=(redis)

printf 'Pulling production release %s before stopping any application...\n' "$release"
"${compose[@]}" --profile tools pull "${services[@]}" volume-init migrate "${infrastructure[@]}"
"${compose[@]}" --profile tools run --rm --no-deps volume-init
if ((${#infrastructure[@]})); then
    "${compose[@]}" up -d --wait --wait-timeout 180 "${infrastructure[@]}"
fi
printf 'Stopping application processes for the explicit migration step...\n'
"${compose[@]}" stop "${services[@]}"
if ! "${compose[@]}" --profile tools run --rm --no-deps migrate; then
    printf 'Migration failed; application processes remain stopped. Inspect migration output and restore/recover deliberately.\n' >&2
    exit 1
fi
"${compose[@]}" up -d --wait --wait-timeout 180 "${services[@]}"
printf 'Production release %s is running. Check HTTPS /health/ready through your TLS reverse proxy.\n' "$release"
