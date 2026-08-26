#!/usr/bin/env bash

# Bootstrap for restricted agent sandboxes (no Docker daemon, filtered egress).
#
# The canonical development lane is tools/agent-setup.sh, which provisions the
# pinned PHP 8.5.9 container image. Managed agent sandboxes often cannot run
# Docker and may block codeload.github.com, which breaks ordinary Composer
# dist downloads. This script provisions the closest local-first lane instead:
#
#   - Composer dependencies installed from git sources through the sandbox
#     proxy (github.com git access is available where codeload is not).
#   - phpstan/phpstan is the one dist-only package in the lock; its lock entry
#     is temporarily pointed at the phpstan git repository for the install and
#     the lock file is restored byte-for-byte afterwards.
#   - Everything that does not need Docker then runs locally: phpunit,
#     phpstan, phpcs, the architecture gate, the structural gate, and the
#     contract materialization/verification pair.
#
# What still requires Docker (run by CI on every push instead): production
# image builds, Trivy image scans, Compose acceptance lanes, and the
# openapi-generator Dart client generation.
#
# A host PHP >= 8.4 is sufficient for the local lane; CI remains the authority
# on the pinned 8.5 runtime.

set -Eeuo pipefail

readonly root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_PROCESS_TIMEOUT="${COMPOSER_PROCESS_TIMEOUT:-3000}"

php_version="$(php -r 'echo PHP_VERSION;')"
echo "agent-sandbox-setup: host PHP ${php_version} (pinned runtime is 8.5.9; CI validates the pin)."

if composer install --no-interaction --no-progress --prefer-dist 2>/dev/null; then
  echo 'agent-sandbox-setup: ordinary dist install succeeded.'
else
  echo 'agent-sandbox-setup: dist install unavailable; installing from git sources.'
  composer config -g use-github-api false

  restore_lock() {
    git checkout -- composer.lock 2>/dev/null || true
  }
  trap restore_lock EXIT

  php -r '
    $lock = json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR);
    foreach (["packages", "packages-dev"] as $section) {
        foreach ($lock[$section] ?? [] as $index => $package) {
            if (($package["name"] ?? "") === "phpstan/phpstan" && ! isset($package["source"])) {
                $lock[$section][$index]["source"] = [
                    "type" => "git",
                    "url" => "https://github.com/phpstan/phpstan.git",
                    "reference" => $package["dist"]["reference"],
                ];
            }
        }
    }
    file_put_contents(
        "composer.lock",
        json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
  '
  composer install --no-interaction --no-progress --prefer-source --ignore-platform-req=php
  restore_lock
  trap - EXIT

  # Source installs embed each package's full git history; strip it so the
  # vendor tree matches a dist install and the session disk allowance
  # survives (the phpstan history alone is multiple gigabytes). The mirror
  # cache serves the same install only once, so drop it too.
  find vendor -name .git -type d -prune -exec rm -rf {} + 2>/dev/null || true
  rm -rf "${COMPOSER_HOME:-$HOME/.config/composer}/../.cache/composer/vcs" \
    "$HOME/.cache/composer/vcs" 2>/dev/null || true
fi

echo 'agent-sandbox-setup: running the local no-Docker verification lane.'
bash tool/materialize-openapi-contract.sh
php tests/Contract/verify-openapi.php
bash tests/structural/verify.sh
php tests/Architecture/verify.php

cat <<'SUMMARY'
agent-sandbox-setup: complete.

Local commands now available:
  vendor/bin/phpunit                     # full test suite
  vendor/bin/phpstan analyse --no-progress --memory-limit=512M
  vendor/bin/phpcs
  composer contract                      # materialize + verify the OpenAPI pin
  bash tests/structural/verify.sh        # structural/privacy gates
  php tests/Architecture/verify.php      # architecture gate

Docker-only lanes (CI runs them on every push):
  production image build, Trivy image scans, Compose acceptance,
  openapi-generator Dart client generation.
SUMMARY
