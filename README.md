# Providentia backend

Providentia household stock-control backend and anonymous public surface. The
application is a modular monolith built with Mezzio, selected Laminas
components, Doctrine ORM/DBAL/Migrations, and a project-owned asynchronous
messaging boundary backed by Enqueue Redis.

The repository includes the Phase 1 production foundation, Phase 2
identity/home/catalog increment, Phase 4 home-scoped synchronization protocol,
Phase 5 ledger-backed household operations, Phase 6 privacy-controlled AI,
Phase 7 governed catalog administration, and Phase 8 deterministic shopping
intelligence and reporting. The generic protocol-v1 synchronization allowlist
remains limited to `home-preference` and `private-note`; API 1.11 also publishes
typed protocol-v2 pantry commands for aggregates with an explicit offline
policy.

## Requirements

- PHP 8.5
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
runtime, provisions a verified local account/home, and writes the protected
`.providentia-development.json` handoff for Flutter.

- API: `http://127.0.0.1:8080`
- readiness: `http://127.0.0.1:8080/health/ready`
- Mailpit: `http://127.0.0.1:8025`

See [published-image local deployment](docs/deployment/prebuilt-images.md) for
GHCR login, immutable tags, Flutter emulator/device URLs, updates, logs, reset,
and the production boundary.

For the complete account handoff, first-home ownership, ordinary test users,
household roles, explicit platform-administrator grant, and Flutter login, use
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
[local development](docs/deployment/local-development.md).

## Lightweight SQLite path

```bash
composer install
export DATABASE_URL=sqlite:///var/providentia.sqlite
export APP_ENV=development
export AUTH_TOKEN_PEPPER="$(openssl rand -hex 32)"
export AUTH_PASSWORD_LOGIN_ENABLED=1
export SYNC_CURSOR_SECRET="$(openssl rand -hex 32)"
php bin/doctrine-migrations migrations:migrate --no-interaction
composer serve
```

Then open:

- public site: `http://127.0.0.1:8080/`
- liveness: `http://127.0.0.1:8080/health/live`
- readiness: `http://127.0.0.1:8080/health/ready`
- system information: `http://127.0.0.1:8080/api/v1/system/info`
- Prometheus exposition: `http://127.0.0.1:8080/metrics`

`/metrics` must be private in a deployed environment. The supplied Caddy
baseline denies it at the public edge.

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

- Email-only login-link onboarding with explicit any-browser approval,
  origin-client polling and PKCE exchange, automatic first-home ownership,
  refresh rotation, logout, device sessions, and secure-cookie/bearer
  transports. The approval browser never receives the application session.
- Development-opt-in email/password compatibility for isolated loopback
  diagnostics only; production clients and acceptance jobs use login links.
- Home creation/switching, roles, invitations, membership lifecycle, explicit
  ownership transfer, tenant authorization, and audit records.
- Reconciled global catalog seed and public product search.
- Home/device-bound offline push, pull, bootstrap, optimistic revisions,
  immutable operation receipts, signed cursors, tombstones, outbox events, and
  duplicate-operation replay.
- Home item master, physical counts, immutable stock movements, materialized
  balances, reviewed receipt commit, purchase history, price evidence, shopping
  lists, dashboard projections, and checksum-gated baseline migration.
- Privacy-gated structured AI extraction with explicit human approval and no
  persisted media payload.
- Sanitized catalog proposals, reviewer/curator workbenches, content-addressed
  icons, and reversible audited product merges.
- Movement/count-derived consumption, cadence, explainable replenishment,
  currency-isolated pack comparison, feedback, household reports, and
  leakage-safe backtesting.

The authoritative operation and schema details live in the OpenAPI contract.
Remaining phase gates and follow-up work are explicit in
[`docs/product/phases`](docs/product/phases/).

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

Start at [docs/index.md](docs/index.md). The owner-authorized product and
repository naming decision is recorded in
[docs/product/project-memory.md](docs/product/project-memory.md).

Providentia is proprietary. No distribution licence is granted or selected.
