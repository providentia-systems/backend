# Providentia backend

Phase 1 production foundation for the Providentia household stock-control
backend and anonymous public surface. The application is a modular monolith
built with Mezzio, selected Laminas components, Doctrine ORM/DBAL/Migrations,
and a project-owned asynchronous messaging boundary backed by Enqueue Redis.

This repository intentionally does **not** implement Phase 2 identity, homes,
memberships, or catalog behavior.

## Requirements

- PHP 8.5
- Composer 2.10.2
- PHP extensions: JSON, mbstring, PDO, PDO SQLite and Redis
- Docker Engine with Compose v2 for the full infrastructure matrix

The production dependency graph is defined by `composer.lock`. Until the first
lock bootstrap workflow has run, the dependency versions in `composer.json`
are exact but have not been resolved together. Normal CI and deployments use
`composer install`; they never update dependencies implicitly.

## Start locally with SQLite

```bash
composer install
export DATABASE_URL=sqlite:///var/providentia.sqlite
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

No distribution licence, pricing, or commercial claim has been selected.
