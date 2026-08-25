#!/usr/bin/env bash

set -Eeuo pipefail

readonly script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly project_directory="$(cd -- "$script_directory/.." && pwd)"
readonly requirements="$script_directory/agent-requirements.json"
readonly agent_state="$project_directory/.agent-tools"
readonly agent_env="$project_directory/.agent-env"
readonly agent_image="providentia-backend-agent:php-8.5.9"
readonly agent_node_version="22.14.0"
readonly agent_node_home="$agent_state/node-v$agent_node_version"
matrix_prefix=''

usage() {
  cat <<'EOF'
Usage: tools/agent-setup.sh [--check|--matrix|--doctor]
  no argument  Install missing Linux prerequisites and build the pinned agent image.
  --check      Validate the checked-in environment contract without mutation.
  --matrix     Provision and run the SQLite/MySQL/MariaDB/Redis/Valkey compatibility lane.
  --doctor     Provision the environment and run the complete local quality/build lane.
EOF
}

require_linux() {
  if [[ "$(uname -s)" != Linux ]]; then
    echo 'The automated agent bootstrap currently supports Linux hosts.' >&2
    exit 69
  fi
}

validate_contract() {
  local node
  node="$(node_for_validation)" || {
    echo 'Node.js >=18.19.0 or a provisioned .agent-tools runtime is required for --check.' >&2
    exit 69
  }
  "$node" - "$requirements" "$project_directory" <<'NODE'
const fs = require('node:fs');
const path = require('node:path');
const [manifestPath, root] = process.argv.slice(2);
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
if (manifest.schemaVersion !== 1 || manifest.repositoryRole !== 'backend') {
  throw new Error('Unexpected agent requirements schema or repository role.');
}
const expected = {
  php: '8.5.9', composer: '2.10.2', gdWebp: 'bundled-php-8.5.9',
  redisExtension: '6.3.0', xdebug: '3.5.3',
  node: '22.14.0', nodeMinimum: '18.19.0',
};
for (const [key, value] of Object.entries(expected)) {
  if (manifest.runtime?.[key] !== value) {
    throw new Error(`Unexpected ${key} pin: ${manifest.runtime?.[key]}`);
  }
}
for (const domain of [
  'nodejs.org', 'repo.packagist.org', 'pecl.php.net', 'registry-1.docker.io', 'ghcr.io',
]) {
  if (!manifest.networkAllowlist?.includes(domain)) {
    throw new Error(`Agent network allowlist is missing ${domain}.`);
  }
}
const currentNode = process.versions.node.split('.').map(Number);
const minimumNode = manifest.runtime.nodeMinimum.split('.').map(Number);
for (let index = 0; index < 3; index += 1) {
  if (currentNode[index] > minimumNode[index]) break;
  if (currentNode[index] < minimumNode[index]) {
    throw new Error(`Node.js ${process.versions.node} is below ${manifest.runtime.nodeMinimum}.`);
  }
}
for (const [architecture, checksum] of Object.entries({
  x64: '69b09dba5c8dcb05c4e4273a4340db1005abeafe3927efda2bc5b249e80437ec',
  arm64: '08bfbf538bad0e8cbb0269f0173cca28d705874a67a22f60b57d99dc99e30050',
})) {
  if (manifest.nodeDownloads?.linux?.[architecture]?.sha256 !== checksum) {
    throw new Error(`Unexpected Node.js ${architecture} archive checksum.`);
  }
  const expectedUrl = `https://nodejs.org/download/release/v22.14.0/node-v22.14.0-linux-${architecture}.tar.xz`;
  if (manifest.nodeDownloads.linux[architecture].url !== expectedUrl) {
    throw new Error(`Unexpected Node.js ${architecture} archive URL.`);
  }
}
for (const command of [
  'composer check',
  'composer test:coverage && composer coverage:check',
  'composer test:mutation',
  'composer audit --locked',
  'bash tools/agent-setup.sh --matrix',
]) {
  if (!manifest.validation?.includes(command)) {
    throw new Error(`Agent validation lane is missing: ${command}`);
  }
}
const dockerfile = fs.readFileSync(path.join(root, 'infrastructure/agent/Dockerfile'), 'utf8');
for (const pin of [
  'php:8.5.9-cli-alpine3.23',
  'composer:2.10.2',
  'REDIS_EXTENSION_VERSION=6.3.0',
  'XDEBUG_VERSION=3.5.3',
  'docker-php-ext-configure gd --with-jpeg --with-webp',
  'docker-php-ext-install -j"$(nproc)" gd',
]) {
  if (!dockerfile.includes(pin)) throw new Error(`Agent Dockerfile is missing ${pin}.`);
}
const toolVersions = fs.readFileSync(path.join(root, '.tool-versions'), 'utf8');
if (!/^php 8\.5\.9$/m.test(toolVersions)) {
  throw new Error('The contributor PHP pin must match the 8.5.9 agent and production image.');
}
if (!/^nodejs 22\.14\.0$/m.test(toolVersions)) {
  throw new Error('The contributor Node.js pin must match the 22.14.0 agent runtime.');
}
NODE
  bash -n "$script_directory/agent-setup.sh"
  echo 'Agent development environment contract verified.'
}

node_for_validation() {
  if [[ -x "$agent_node_home/bin/node" ]]; then
    printf '%s\n' "$agent_node_home/bin/node"
    return
  fi
  command -v node
}

linux_packages() {
  awk '
    /"aptPackages"[[:space:]]*:[[:space:]]*\[/ { reading = 1; next }
    reading && /\]/ { exit }
    reading {
      value = $0
      gsub(/[",[:space:]]/, "", value)
      if (value != "") print value
    }
  ' "$requirements"
}

install_system_packages() {
  mapfile -t packages < <(linux_packages)
  local missing=()
  local package
  for package in "${packages[@]}"; do
    if ! dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -q 'ok installed'; then
      missing+=("$package")
    fi
  done
  if (( ${#missing[@]} == 0 )); then
    echo 'Linux prerequisites are already installed.'
    return
  fi
  local privilege=()
  if (( EUID != 0 )); then
    command -v sudo >/dev/null || {
      printf 'Missing Linux packages and sudo is unavailable: %s\n' "${missing[*]}" >&2
      exit 77
    }
    privilege=(sudo)
  fi
  "${privilege[@]}" apt-get update
  "${privilege[@]}" apt-get install --yes --no-install-recommends "${missing[@]}"
}

install_node_runtime() {
  if [[ -x "$agent_node_home/bin/node" ]] \
    && [[ "$($agent_node_home/bin/node --version)" == "v$agent_node_version" ]]; then
    echo "Pinned Node.js v$agent_node_version is already provisioned."
    return
  fi
  local architecture archive checksum
  case "$(uname -m)" in
    x86_64) architecture='x64'; checksum='69b09dba5c8dcb05c4e4273a4340db1005abeafe3927efda2bc5b249e80437ec' ;;
    aarch64|arm64) architecture='arm64'; checksum='08bfbf538bad0e8cbb0269f0173cca28d705874a67a22f60b57d99dc99e30050' ;;
    *) echo "Unsupported Node.js bootstrap architecture: $(uname -m)" >&2; exit 69 ;;
  esac
  mkdir -p "$agent_node_home"
  archive="$(mktemp --suffix=.tar.xz)"
  curl --fail --location --retry 3 --output "$archive" \
    "https://nodejs.org/download/release/v$agent_node_version/node-v$agent_node_version-linux-$architecture.tar.xz"
  printf '%s  %s\n' "$checksum" "$archive" | sha256sum --check --status || {
    echo 'The pinned Node.js archive checksum did not match.' >&2
    exit 65
  }
  tar --extract --xz --file "$archive" --directory "$agent_node_home" --strip-components=1
  rm -f -- "$archive"
  "$agent_node_home/bin/node" --version
}

require_docker_daemon() {
  if docker info >/dev/null 2>&1; then
    return
  fi
  if command -v systemctl >/dev/null 2>&1; then
    if (( EUID == 0 )); then
      systemctl start docker 2>/dev/null || true
    elif command -v sudo >/dev/null 2>&1; then
      sudo systemctl start docker 2>/dev/null || true
    fi
  fi
  docker info >/dev/null 2>&1 || {
    cat >&2 <<'EOF'
Docker is installed but this shell cannot reach its daemon. Start Docker and
grant the current user access to the Docker socket, then rerun the bootstrap.
EOF
    exit 69
  }
  docker compose version >/dev/null
}

write_environment() {
  mkdir -p "$agent_state"
  {
    echo '# Generated by tools/agent-setup.sh; do not commit.'
    printf 'export PROVIDENTIA_BACKEND_ROOT=%q\n' "$project_directory"
    printf 'export PROVIDENTIA_BACKEND_AGENT_IMAGE=%q\n' "$agent_image"
    printf 'export PATH=%q:$PATH\n' "$agent_node_home/bin"
  } > "$agent_env"
  echo "Generated $agent_env"
}

pull_pinned_images() {
  local node
  node="$(node_for_validation)"
  mapfile -t images < <(
    "$node" -e '
      const fs = require("node:fs");
      const manifest = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
      process.stdout.write(manifest.containerImages.join("\n"));
    ' "$requirements"
  )
  local image
  for image in "${images[@]}"; do
    docker pull "$image"
  done
}

build_agent_image() {
  docker build \
    --file "$project_directory/infrastructure/agent/Dockerfile" \
    --tag "$agent_image" \
    "$project_directory"
}

setup() {
  require_linux
  install_system_packages
  install_node_runtime
  validate_contract
  require_docker_daemon
  write_environment
  pull_pinned_images
  build_agent_image
}

run_in_agent() {
  docker run --rm "$agent_image" "$@"
}

validate_compose_model() {
  docker compose \
    --profile sqlite --profile mysql --profile mariadb \
    --profile redis --profile valkey config --quiet
}

wait_for_healthy_container() {
  local container="$1"
  local status
  local attempt

  for attempt in $(seq 1 60); do
    status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
      "$container" 2>/dev/null || true)"
    if [[ "$status" == 'healthy' ]]; then
      return
    fi
    if [[ "$status" == 'exited' || "$status" == 'dead' ]]; then
      break
    fi
    sleep 2
  done

  printf 'Compatibility service %s did not become healthy (status: %s).\n' \
    "$container" "${status:-unknown}" >&2
  docker logs "$container" >&2 || true
  return 1
}

run_sqlite_matrix_case() {
  docker run --rm \
    -e DATABASE_URL=sqlite:///var/agent-matrix.sqlite \
    -e QUEUE_REQUIRED=0 \
    "$agent_image" sh -lc '
      php bin/doctrine-migrations migrations:migrate --no-interaction
      php bin/providentia foundation:prove agent-sqlite
      php bin/doctrine-migrations migrations:migrate prev --no-interaction
      php bin/doctrine-migrations migrations:migrate latest --no-interaction
      php bin/doctrine-migrations migrations:up-to-date --no-interaction
    '
}

run_database_matrix_case() {
  local name="$1"
  local image="$2"
  local health_command="$3"
  local container="${matrix_prefix}-${name}"

  docker run --detach \
    --name "$container" \
    --network "$matrix_prefix" \
    --network-alias database \
    --env MYSQL_DATABASE=providentia \
    --env MYSQL_USER=providentia \
    --env MYSQL_PASSWORD=providentia \
    --env MYSQL_ROOT_PASSWORD=root \
    --env MARIADB_DATABASE=providentia \
    --env MARIADB_USER=providentia \
    --env MARIADB_PASSWORD=providentia \
    --env MARIADB_ROOT_PASSWORD=root \
    --health-cmd "$health_command" \
    --health-interval 2s \
    --health-timeout 5s \
    --health-retries 60 \
    "$image" >/dev/null
  wait_for_healthy_container "$container"

  docker run --rm \
    --network "$matrix_prefix" \
    -e 'DATABASE_URL=mysql://providentia:providentia@database:3306/providentia?charset=utf8mb4' \
    -e QUEUE_REQUIRED=0 \
    "$agent_image" sh -lc "
      php bin/doctrine-migrations migrations:migrate --no-interaction
      php bin/providentia foundation:prove agent-${name}
      php bin/doctrine-migrations migrations:migrate prev --no-interaction
      php bin/doctrine-migrations migrations:migrate latest --no-interaction
      php bin/doctrine-migrations migrations:up-to-date --no-interaction
      bash tests/Acceptance/development-auth-http-smoke.sh
      composer test
    "

  docker rm --force "$container" >/dev/null
}

run_queue_matrix_case() {
  local name="$1"
  local image="$2"
  local health_command="$3"
  local container="${matrix_prefix}-${name}"

  docker run --detach \
    --name "$container" \
    --network "$matrix_prefix" \
    --network-alias broker \
    --health-cmd "$health_command" \
    --health-interval 2s \
    --health-timeout 3s \
    --health-retries 60 \
    "$image" >/dev/null
  wait_for_healthy_container "$container"

  docker run --rm \
    --network "$matrix_prefix" \
    -e DATABASE_URL=sqlite:///var/agent-queue.sqlite \
    -e QUEUE_DSN=redis+phpredis://broker:6379 \
    -e QUEUE_REQUIRED=1 \
    "$agent_image" bash tests/Acceptance/outbox-recovery-smoke.sh

  docker rm --force "$container" >/dev/null
}

cleanup_compatibility_matrix() {
  local service

  for service in mysql mariadb redis valkey; do
    docker rm --force "${matrix_prefix}-${service}" >/dev/null 2>&1 || true
  done
  docker network rm "$matrix_prefix" >/dev/null 2>&1 || true
}

run_compatibility_matrix() {
  matrix_prefix="providentia-agent-matrix-$$"
  trap cleanup_compatibility_matrix EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  docker network create "$matrix_prefix" >/dev/null

  run_sqlite_matrix_case
  run_database_matrix_case \
    mysql mysql:8.4.6 \
    'mysqladmin ping -h 127.0.0.1 -uroot -proot --silent'
  run_database_matrix_case \
    mariadb mariadb:11.8.3 \
    'healthcheck.sh --connect --innodb_initialized'
  run_queue_matrix_case redis redis:8.2.1-alpine 'redis-cli ping'
  run_queue_matrix_case valkey valkey/valkey:8.1.3-alpine 'valkey-cli ping'

  cleanup_compatibility_matrix
  trap - EXIT INT TERM
  echo 'SQLite, MySQL, MariaDB, Redis, and Valkey compatibility matrix passed.'
}

matrix() {
  setup
  cd "$project_directory"
  validate_compose_model
  run_compatibility_matrix
}

doctor() {
  setup
  cd "$project_directory"
  bash tests/structural/verify.sh
  run_in_agent php -r 'exit(extension_loaded("gd") && function_exists("imagewebp") && (gd_info()["WebP Support"] ?? false) ? 0 : 1);'
  run_in_agent composer check
  docker run --rm -e XDEBUG_MODE=coverage "$agent_image" sh -lc \
    'composer test:coverage && composer coverage:check'
  docker run --rm -e XDEBUG_MODE=coverage "$agent_image" \
    composer test:mutation
  run_in_agent composer audit --locked
  validate_compose_model
  run_compatibility_matrix
  docker build --tag providentia-source-agent "$project_directory"
  docker build --file Dockerfile.production --target runtime \
    --tag providentia-runtime-agent "$project_directory"
  docker build --file Dockerfile.production --target media-worker \
    --tag providentia-media-agent "$project_directory"
  docker build --file Dockerfile.production --target web \
    --tag providentia-web-agent "$project_directory"
  echo 'Backend agent doctor passed all local quality, migration, and image-build gates.'
}

case "${1:-}" in
  '') setup ;;
  --check) validate_contract ;;
  --matrix) matrix ;;
  --doctor) doctor ;;
  --help|-h) usage ;;
  *) usage >&2; exit 64 ;;
esac
