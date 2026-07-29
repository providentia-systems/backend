# Providentia backend

Providentia household stock-control backend and anonymous public surface. The
application is a modular monolith built with Mezzio, selected Laminas
components, Doctrine ORM/DBAL/Migrations, and a project-owned asynchronous
messaging boundary backed by Enqueue Redis.

The repository now includes the Phase 1 production foundation, a bounded Phase
2 identity/home/catalog implementation increment, and a bounded Phase 4
home-scoped synchronization prototype. Phase 3 inventory and purchasing
behavior remains intentionally unimplemented; synchronization therefore covers
only its explicitly allow-listed `home-preference` and `private-note` records.

## Requirements

- PHP 8.5
- Composer 2.10.2
- PHP extensions: JSON, mbstring, PDO, PDO MySQL or PDO SQLite, and Redis
- Docker Engine with Compose v2 for the full infrastructure matrix

The resolved production dependency graph is committed in `composer.lock`.
Normal CI and deployments use `composer install`; they never update
dependencies implicitly.

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

## Compose profiles

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

## Phase 2 and Phase 4 surface

- Generic-shape email/password registration (not timing-resistant while
  verification SMTP remains synchronous), email verification,
  login, refresh rotation, password reset, logout, device sessions,
  secure-cookie and bearer transports.
- Home creation/switching, roles, invitations, membership lifecycle, explicit
  ownership transfer, tenant authorization, and audit records.
- Reconciled global catalog seed and public product search.
- Home/device-bound offline push, pull, bootstrap, optimistic revisions,
  immutable operation receipts, signed cursors, tombstones, outbox events, and
  duplicate-operation replay.

The authoritative operation and schema details live in the OpenAPI contract.
This is not full Phase 2/4 acceptance: invitation revocation, step-up
proposed/accepted ownership transfer, operation-status lookup, and consistent
paged bootstrap remain explicitly documented follow-up work.

## Quality gates

```bash
composer check
bash tests/structural/verify.sh
```

CI additionally applies, rolls back, and reapplies the same migration on
SQLite, MySQL, and MariaDB, and executes queue proofs against Redis and Valkey.

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
