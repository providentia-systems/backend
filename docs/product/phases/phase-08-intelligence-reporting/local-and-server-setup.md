# Phase 8 local and remote setup

## Local MySQL path

Use the repository's supported setup and the verified Phase 0 handover:

```bash
./scripts/setup-development.sh \
  --handover /absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip

docker compose --env-file .env.development.local exec -T api-mysql \
  php bin/doctrine-migrations migrations:status

# Run quality tools from a host checkout with Composer development dependencies.
composer check
bash tests/structural/verify.sh
```

The application entrypoint applies `Version20260730000800`. Verify that its
tables exist and that `stock_count_sessions` has `scope_complete` and
`reliability` before enabling scheduled suggestion generation.

Use a disposable home for the smoke test:

1. create at least two complete reliable physical counts for one product;
2. commit receipt lines between the counts;
3. create a suggestion run;
4. inspect the estimate, suggestion, explanation, and price comparison;
5. edit or dismiss a suggestion and confirm feedback history remains;
6. update a policy with its expected revision;
7. request all four household reports;
8. run a fully historical backtest;
9. inspect the home audit stream for all privileged/read events.

OpenAPI 1.7 is the client source of truth. Do not infer response fields from
database columns.

## Compatibility profiles

Run the same migrations and quality gates on every supported database:

```bash
docker compose --profile sqlite --profile valkey up --build --wait
docker compose --profile mysql --profile redis up --build --wait
docker compose --profile mariadb --profile valkey up --build --wait
```

CI rolls the full migration chain down and up on SQLite, MySQL 8.4, and
MariaDB 11.8. A SQLite-only result is not a release gate.

## Remote server rollout

1. Back up the database and prove the restore in staging.
2. Deploy the same immutable application image to API, worker, and outbox
   processes.
3. Put MySQL and Redis on private networks; require TLS for remote connections
   and never publish port 3306.
4. Inject production secrets from the deployment secret manager using
   `.env.production.example` as the variable inventory.
5. Apply migrations from one release job before switching HTTP traffic.
6. Run readiness, migration status, and a synthetic home-scoped report.
7. Enable suggestion scheduling gradually and monitor duration, row counts,
   failures, and feedback.
8. Keep the previous application image available while the migration remains
   backward compatible; a database rollback requires the documented Phase 8
   data-loss decision below.

Production startup already rejects placeholder/identical secrets, exposed
development tokens, non-HTTPS public URLs, and non-SMTPS mail. Keep `/metrics`
private at the edge.

## Rollback

Application rollback is preferred while no Phase 8 writes have occurred. The
down migration drops estimates, suggestions, feedback, policy history,
backtests, and their explanations. After real usage it is destructive and must
not run automatically. Export those home-private tables, retain audit events,
obtain an explicit incident decision, and restore-test before any schema down.

## Scheduling

The current API supports explicit, synchronous home-scoped generation. A
remote scheduler may invoke that command path through an authenticated service
workflow at the approved cadence. Do not run one unbounded cross-home query;
partition work by home, enforce timeouts, and retain the run ID and audit event.
