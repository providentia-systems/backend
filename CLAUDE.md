# Agent environment guide — Providentia backend

Read `AGENTS.md` first; it is the contributor contract. This file adds the
practical bootstrap for coding-agent sandboxes so a session can build, test,
and debug locally from the first minute.

## Fast start

- Full Linux host with Docker: `bash tools/agent-setup.sh` (canonical), then
  `bash tools/agent-setup.sh --doctor` for the complete local lane.
- Restricted sandbox (no Docker daemon, filtered egress — e.g. managed
  Claude/Codex containers): `bash tools/agent-sandbox-setup.sh`. It installs
  Composer dependencies from git sources when `codeload.github.com` is
  blocked, restores `composer.lock` untouched, and runs the no-Docker
  verification lane.

## What runs where

| Check | Local (no Docker) | CI |
| --- | --- | --- |
| phpunit suite, coverage | yes — `vendor/bin/phpunit` | yes |
| phpstan, phpcs, architecture gate | yes | yes |
| Contract materialize/verify (`composer contract`) | yes (bash+node+php) | yes |
| Structural/privacy gates (`bash tests/structural/verify.sh`) | yes | yes |
| Auth HTTP smoke (`bash tests/Acceptance/development-auth-http-smoke.sh`) | yes (php -S + sqlite) | yes |
| Production image build, Trivy image scan | no — Docker | yes |
| Compose acceptance lanes, Dart client generation | no — Docker | yes |

Validate locally before every push; CI is the authority on the pinned
PHP 8.5.9 runtime and the Docker lanes.

## Sandbox network notes

Outbound HTTPS may pass through a policy proxy. Known-good hosts:
`github.com`, `api.github.com`, `packagist.org`, `pypi.org`, `pub.dev`,
`storage.googleapis.com`. Known-blocked in some sandboxes:
`codeload.github.com` (hence the git-source Composer fallback in
`tools/agent-sandbox-setup.sh`), `dl.google.com`,
`*.blob.core.windows.net` (GitHub Actions artifact downloads). Never disable
TLS verification; use the sandbox's CA bundle.

## Authentication model (operative)

Human authentication is the email login-link exchange only — there is no
password registration, login, or reset surface anywhere. Development and
acceptance environments set `EXPOSE_DEVELOPMENT_TOKENS=1`, which makes the
login-link start response include `developmentApprovalToken` so scripts and
CI can complete the email loop non-interactively. Production never enables it.

## Contract changes

`contracts/source/providentia-v1.json.gz` is canonical. To change the API:
edit the materialized `contracts/openapi/providentia-v1.json`, regenerate the
archive with `gzip -n -9`, update the two SHA-256 pins and the
version/path/operation/schema counts in `tool/materialize-openapi-contract.sh`
and `contracts/openapi/contract.lock.json`, keep
`php tests/Contract/verify-openapi.php` green, and synchronize the generated
clients in `providentia-systems/client` and `providentia-systems/admin` from
the exact same publication.
