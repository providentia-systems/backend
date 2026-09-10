# Providentia backend

<p align="center">
  <a href="https://github.com/providentia-systems/backend/releases/latest"><img alt="Backend release" src="https://img.shields.io/github/v/release/providentia-systems/backend?filter=v*&amp;label=release"></a>
  <a href="https://github.com/providentia-systems/backend/actions/workflows/quality.yml"><img alt="CI and CLI checks" src="https://img.shields.io/github/actions/workflow/status/providentia-systems/backend/quality.yml?branch=main&amp;label=CI%20%2F%20CLI"></a>
  <a href="https://github.com/providentia-systems/backend/actions/workflows/security.yml"><img alt="Security checks" src="https://img.shields.io/github/actions/workflow/status/providentia-systems/backend/security.yml?branch=main&amp;label=security"></a>
  <a href="https://github.com/providentia-systems/backend/actions/workflows/production-image.yml"><img alt="Docker build and release" src="https://img.shields.io/github/actions/workflow/status/providentia-systems/backend/production-image.yml?branch=main&amp;label=Docker"></a>
  <a href="https://github.com/providentia-systems/backend/actions/workflows/contracts.yml"><img alt="Latest pull request contract checks" src="https://img.shields.io/github/actions/workflow/status/providentia-systems/backend/contracts.yml?event=pull_request&amp;label=PR%20contracts"></a>
</p>
<p align="center">
  <a href="Dockerfile.production"><img alt="PHP 8.5" src="https://img.shields.io/badge/PHP-8.5-777BB4"></a>
  <a href="docs/deployment/release-process.md"><img alt="Linux AMD64 and ARM64" src="https://img.shields.io/badge/Linux-amd64%20%7C%20arm64-2496ED"></a>
  <a href="LICENSE"><img alt="Proprietary license" src="https://img.shields.io/badge/license-proprietary-lightgrey"></a>
</p>

> **Proprietary software.** Copyright (c) 2026 Vast Development Method Trading
> Pty Ltd. All rights reserved. No licence is granted; see [LICENSE](LICENSE).

Providentia is a headless API for household stock control. Homeowners use the
separate [Flutter Client](https://github.com/providentia-systems/client), while
authorized staff use the Linux [Flutter Admin](https://github.com/providentia-systems/admin).
The backend serves JSON APIs and authorized media, with no browser sign-in or
management interface. It is a modular monolith built with Mezzio, selected
Laminas components, Doctrine ORM/DBAL/Migrations and an asynchronous messaging
boundary backed by Enqueue Redis.

API **2.0.0** provides email-code authentication, verified email aliases,
account and home profiles, invitation acceptance, configurable access groups,
country-specific onboarding and administrator approval. Account, home and admin
groups are separate: the backend enforces their features, limits and permissions.
The system owner can inspect all application data and delegate operator access
through administrator groups. Household access stays isolated by membership;
public catalog sharing is separate from internal operator inspection.

The stock engine includes inventory movements, physical counts, purchases,
shopping lists, reviewed imports, synchronization, catalog contributions and
optional AI intake. This is a pre-release alignment with no live customers to
migrate. Paid plans and platform-funded AI billing are future work; administrators
assign groups manually now. See the [current decisions](docs/unification-decision-record.md).

## Deploy on your server

Published images already live in GitHub Container Registry; Docker Hub can be
configured as an additional mirror. The production host needs Docker Engine and
Compose v2, Bash, Python 3, `flock`, a stable HTTPS domain and an SMTP service.
It does not need PHP or Composer installed.

New operators should start with the
[Ubuntu production server quick start](docs/deployment/server-quick-start.md).
It covers release download, generated credentials, MySQL/MariaDB selection,
OPNsense/HAProxy, firewall boundaries, SMTP, CORS and first-owner setup.

| Image | Role |
|---|---|
| `ghcr.io/providentia-systems/backend` | PHP API, CLI, migrations and ordinary workers |
| `ghcr.io/providentia-systems/backend-web` | Caddy HTTP entry point |
| `ghcr.io/providentia-systems/backend-media-worker` | Video processing with FFmpeg |

Start with a [published release](https://github.com/providentia-systems/backend/releases),
extract its deployment bundle, and prepare the configuration:

```bash
sudo install -d -m 0700 -o "$USER" -g "$(id -gn)" /etc/providentia /srv/providentia
bash scripts/setup-production.sh \
  --env-file /etc/providentia/production.env \
  --image-env images.env \
  --public-url https://api.example.net \
  --mail-from no-reply@example.net \
  --trusted-proxies 127.0.0.1/32 \
  --bind-address 127.0.0.1 \
  --http-port 8080 \
  --cors-origins https://api.example.net \
  --database mysql \
  --data-directory /srv/providentia \
  --prepare-only
```

Replace the example domain and proxy address with your infrastructure, edit
`MAIL_DSN` in the generated protected env file, then start the deployment:

```bash
bash scripts/setup-production.sh --env-file /etc/providentia/production.env
```

The helper preserves existing secrets, pulls the selected images, starts the
chosen database and Redis, runs migrations once, starts the application
processes and checks readiness. The release's `images.env` pins all three
images by digest. Put your TLS reverse proxy in front of the default
`127.0.0.1:8080` listener. MySQL and MariaDB are **alternatives**: the default is
MySQL 8.4; select MariaDB 11.8 or an external MySQL/MariaDB service when
preparing the configuration. PostgreSQL is not supported by the current image,
connection/migration implementation, helper or CI matrix.

The repository's default branch is `main`. Each successful release pipeline for
`main` creates the next patch release, starting at **0.1.0**, after its required
checks pass. Set `NEXT_RELEASE_VERSION` before a merge for a deliberate minor or major release. Backend
release versions are separate from the **2.0.0 API contract version**. Badges
link to live results; CI / CLI includes the quality suite and CLI database/queue
proofs. A green build does not replace a server acceptance or restore rehearsal.

- [Production deployment](docs/deployment/production.md): HTTPS, bootstrap, storage, operations and recovery.
- [Post-release acceptance](docs/deployment/post-release-acceptance.md): exact owner/invitee, location, private-sync, Admin and AI release proof.
- [AI BYOK](docs/deployment/ai-byok.md): provider, permission, endpoint, privacy and acceptance setup.
- [Environment reference](docs/deployment/environment-reference.md): every production Compose setting, defaults and secrets.
- [Architecture and scaling](docs/deployment/architecture-and-scaling.md): process roles, separate servers and current limits.
- [Releases and registries](docs/deployment/release-process.md): image URLs, tags, automatic versions, PHP support and Docker Hub.

## Automated contributor environment

Linux contributors and coding agents can provision the pinned PHP, Composer,
extension, container, and validation environment without reconstructing CI by
hand:

```bash
bash tools/agent-setup.sh
source .agent-env
bash tools/agent-setup.sh --doctor
```

The setup self-provisions a SHA-256-pinned project Node.js runtime before its
contract validation; `--check` remains non-mutating. The contract includes all
required network endpoints and the complete local quality/build lane. See
[agent development](docs/deployment/agent-development.md).

The separate Linux Flutter operator application and its permission-controlled API
boundary are defined in the
[administrator control-plane architecture](docs/architecture/admin-control-plane.md).

## Requirements

- PHP 8.5.9
- Composer 2.10.2
- PHP extensions: JSON, mbstring, PDO, PDO MySQL or PDO SQLite, Redis, and Sodium
- Docker Engine with Compose v2 for the full infrastructure matrix

Before using the host-PHP SQLite path, verify that PHP exposes the selected
PDO driver:

```bash
php -r 'printf("PDO drivers: %s\n", implode(", ", PDO::getAvailableDrivers()));'
```

On Ubuntu 26.04 with PHP 8.5, install a missing SQLite or MySQL driver with
`sudo apt install php8.5-sqlite3` or `sudo apt install php8.5-mysql`, then
restart the relevant PHP service. The application now stops with this same
actionable guidance before Doctrine attempts a connection.

The resolved production dependency graph is committed in `composer.lock`.
Normal CI and deployments use `composer install`; they never update
dependencies implicitly.

## Fastest local runtime: published images

No PHP, Composer, Caddy, or FFmpeg build is required on the workstation. After
authenticating to GHCR when the private packages require it, run:

```bash
bash scripts/setup-prebuilt.sh
```

This pulls the production API, web, and media-worker images, starts MySQL,
Redis, Mailpit, migrations, and all long-running workers, proves the live HTTP
runtime, verifies a local account through Mailpit, accepts the current country
policy for that test account, explicitly creates its home, and writes the protected
`.providentia-development.json` handoff for Flutter.

- API: `http://127.0.0.1:8080`
- readiness: `http://127.0.0.1:8080/health/ready`
- Mailpit: `http://127.0.0.1:8025`

See [published-image local deployment](docs/deployment/prebuilt-images.md) for
GHCR login, immutable tags, Flutter emulator/device URLs, updates, logs, reset,
and the production boundary.

For the complete account handoff, first-home ownership, ordinary test users,
household roles, the initial system-owner command, and Flutter login, use
[Client login, users, homes, and administrator testing](docs/deployment/client-user-testing.md).

## Golden development environment

The supported full local path uses MySQL, Redis, Mailpit, the API, queue worker,
outbox relay, exact catalog evidence, a verified developer account, and one
active home:

```bash
make setup PROVIDENTIA_HANDOVER_ZIP=/absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip
```

`make` does not forward command-line environment values to the script on every
platform. The portable explicit form is:

```bash
PROVIDENTIA_HANDOVER_ZIP=/absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip \
  ./scripts/setup-development.sh
```

The script verifies both source SHA-256 values before any import, performs a
dry run, imports idempotently, and writes a mode-`0600`
`.providentia-development.json` client handoff. See
[local development](docs/deployment/local-development.md) for the protected
archive location, exact required members, and the supported helper for creating
a minimal development archive from authorized checksum-verified exports. The
full handover is intentionally not committed because it contains private
household source material.

## Lightweight SQLite path

```bash
composer install
export DATABASE_URL=sqlite:///var/providentia.sqlite
export APP_ENV=development
export AUTH_TOKEN_PEPPER="$(openssl rand -hex 32)"
export SYNC_CURSOR_SECRET="$(openssl rand -hex 32)"
php bin/doctrine-migrations migrations:migrate --no-interaction
composer serve
```

Then open:

- liveness: `http://127.0.0.1:8080/health/live`
- readiness: `http://127.0.0.1:8080/health/ready`
- system information: `http://127.0.0.1:8080/api/v1/system/info`

This lightweight command starts the API. Interactive login additionally needs
mail transport and a running `notification:deliver` worker; use the complete
Compose setup above for a working local Mailpit mailbox. Verification codes are
never returned by the API, including in development.

The root returns JSON `404`. `/metrics` also returns `404` unless
`METRICS_ENABLED=1` and a dedicated `METRICS_BEARER_TOKEN` are configured, after
which it requires that bearer credential and a private network/edge policy.

## Source-build Compose profiles

```bash
make sqlite
make mysql
make mariadb
make down
```

The commands cover server-side SQLite, MySQL 8.4, MariaDB 11.8, Redis 8.2, and
Valkey 8.1. Database and broker ports are not published to the host. The known
password defaults are for isolated local development only; supply secrets for
any shared environment.

## Prove persistence and asynchronous delivery

```bash
php bin/providentia foundation:prove phase-1
php bin/providentia outbox:relay --once
php bin/providentia queue:consume --once --timeout=5000
```

The first command persists the ORM proof aggregate and its outbox record in one
Doctrine transaction. The relay publishes committed records. The worker
records a message ID before acknowledging it, so redelivery is idempotent. A
queue message is never represented as equivalent to the database commit.

## Product surface

- Eight-digit email codes entered in the originating client, with installation
  binding, ten-minute expiry, five attempts and a sixty-second resend cooldown;
  refresh rotation, logout, device sessions and cookie/bearer transports.
- Names, avatars, verified email aliases and country privacy-policy acceptance;
  home descriptions, images, optional coordinates, currency and timezone.
- Explicit home creation, invitation acceptance and decline, multiple owners,
  role defaults and individual permission overrides bounded by home groups.
- Separate account, home and administrator groups; configurable quotas preserve
  existing data after reductions while blocking further additions over the limit.
- CLI-authorized first system owner, administrator application approval,
  delegated operator access, audited data inspection, country publication and
  official country/state/city reference updates.
- Reconciled global catalog seed and public product search.
- Home/device-bound offline push, pull, bootstrap, optimistic revisions,
  immutable operation receipts, signed cursors, tombstones, outbox events, and
  duplicate-operation replay.
- Home item master, physical counts, immutable stock movements, materialized
  balances, reviewed receipt commit, purchase history, price evidence, shopping
  lists, dashboard projections, and checksum-gated baseline migration.
- Privacy-gated structured AI extraction with explicit human approval;
  original media is either short-lived or explicitly retained, encrypted,
  quota-controlled private media. BYOK provider profiles are private
  per-person by default (home-sharing is a deliberate owner choice), and
  OpenAI-compatible/Ollama profiles own their endpoints under an HTTPS/SSRF
  policy with a separate `AI_ALLOW_PRIVATE_NETWORK_ENDPOINTS` LAN opt-in for
  Ollama.
- Sanitized catalog proposals, reviewer/curator workbenches, content-addressed
  icons, reversible audited product merges, and separately consented
  product-identity, product-image, and store-price contributions.
- Movement/count-derived consumption, cadence, explainable replenishment,
  currency-isolated pack comparison, feedback, household reports, and
  leakage-safe backtesting.

The authoritative operation and schema details live in the OpenAPI contract.
Remaining phase gates and follow-up work are explicit in
[`docs/product/phases`](docs/product/phases/).
The synchronized backend/client delivery order and P0–P3 exit gates are in the
[inventory integration roadmap](docs/inventory-integration-roadmap.md).

## Quality gates

```bash
composer check
bash tests/structural/verify.sh
```

CI additionally applies, rolls back, and reapplies the same migration on
SQLite, MySQL, and MariaDB, and executes queue proofs against Redis and Valkey.
The production-image workflow smoke-tests the runtime targets before publishing
multi-architecture GHCR images with provenance, SBOM attestations, and recorded
digests.

## Contracts

The backend owns:

- `contracts/openapi/providentia-v1.json`
- `contracts/openapi/contract.lock.json`
- `contracts/design-tokens/providentia-v1.json`
- `contracts/design-tokens/contract.lock.json`

Generate the pinned Dart proof client with:

```bash
bash tool/generate-dart-client.sh
```

Generated client code is not hand-edited or committed here. API tags publish
immutable contract files for the Flutter repository to pin.

## Documentation

Start at [docs/index.md](docs/index.md) and the
[cross-repository inventory integration roadmap](docs/inventory-integration-roadmap.md).
The owner-authorized product and repository naming decision is recorded in
[docs/product/project-memory.md](docs/product/project-memory.md).

## License

Copyright (c) 2026 Vast Development Method Trading Pty Ltd. All rights reserved.

This repository is proprietary software. No licence is granted to use, copy,
modify, merge, publish, distribute, sublicense, or sell the software except as
expressly authorised in writing by Vast Development Method Trading Pty Ltd.
Viewing or forking this repository on GitHub does not grant a licence. See the
[LICENSE](LICENSE) file for the complete terms.
