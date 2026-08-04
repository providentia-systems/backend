# Phase 9–10 production deployment and recovery

This runbook describes the backend release topology. Flutter installers, signing,
PWA packaging, and store publication remain in the Flutter repository.

## Build and publish

Build all three targets from the same immutable commit:

```bash
docker build --file Dockerfile.production --target runtime --tag registry.example/providentia:<git-sha> .
docker build --file Dockerfile.production --target cli --tag registry.example/providentia:<git-sha>-cli .
docker build --file Dockerfile.production --target media-worker --tag registry.example/providentia:<git-sha>-media .
```

The deployment variable `PROVIDENTIA_IMAGE` must resolve to the tested runtime
image by immutable digest in production. Image publication must include an SBOM,
vulnerability scan, provenance, and signature before promotion.

## Domain-neutral edge

`compose.production.yaml` publishes Caddy on
`${PROVIDENTIA_BIND_ADDRESS:-127.0.0.1}:${PROVIDENTIA_HTTP_PORT:-8080}`.
No domain is embedded. HAProxy/OPNsense or another trusted operator edge may
terminate TLS and forward to this address. `PUBLIC_BASE_URL` and
`CORS_ALLOWED_ORIGINS` remain deployment configuration.

The database and Redis are not published to the host.

## Database profiles

For an external MySQL/MariaDB and Redis/Valkey deployment, set `DATABASE_URL`
and `QUEUE_DSN` and start only the application services:

```bash
docker compose --env-file .env.production -f compose.production.yaml pull
docker compose --env-file .env.production -f compose.production.yaml --profile tools run --rm migrate
docker compose --env-file .env.production -f compose.production.yaml up -d \
  api web worker outbox notification data-governance sync-compactor ai-video-worker
```

For self-hosted MySQL and Redis, also enable their profiles and use service DNS
names in the DSNs:

```dotenv
DATABASE_URL=mysql://providentia:URL_ENCODED_PASSWORD@mysql:3306/providentia?charset=utf8mb4
QUEUE_DSN=redis+phpredis://:URL_ENCODED_PASSWORD@redis:6379
```

```bash
docker compose --env-file .env.production -f compose.production.yaml --profile mysql --profile redis up -d mysql redis
docker compose --env-file .env.production -f compose.production.yaml --profile tools run --rm migrate
docker compose --env-file .env.production -f compose.production.yaml up -d \
  api web worker outbox notification data-governance sync-compactor ai-video-worker
```

Use the `mariadb` profile and MariaDB service DNS name for the supported
MariaDB topology. Migration is deliberately a separate release action; API and
worker replicas never race to migrate at startup.

## Secrets

Generate independent random values for authentication, synchronization, AI
credential/media encryption, data-export encryption, metrics access, Redis,
database, SMTP, and Restic. Store them through the deployment secret mechanism
with least privilege. Never commit populated environment files.

The AI credential, media, and data-export encryption keys need separately
controlled escrow. A restore without the matching keys cannot decrypt household
provider credentials, retained media, or pending export artifacts.

## Persistent-data inventory

Back up these sources; do not merely copy live database files:

| Source | Required method |
|---|---|
| MySQL/MariaDB | transactionally consistent logical or physical database backup |
| Private media/object storage | provider versioning plus encrypted Restic/object backup |
| `app-var` | only durable application artifacts documented for the release |
| Deployment configuration | encrypted configuration repository or secret-manager export |
| AI encryption keys | offline, access-controlled key escrow |
| Release metadata | image digests, SBOM, signatures, migration and rollback record |

Redis append-only persistence improves queue recovery but is not the relational
system of record. Include it in infrastructure recovery where outstanding
queued work matters; consumers must remain idempotent.

## Restic boundary

The application does not call Restic. Operators first create a consistent
database dump and object-storage/config snapshot under
`BACKUP_STAGING_DIRECTORY`, then invoke the opt-in backup profile:

```bash
docker compose --env-file .env.production -f compose.production.yaml --profile backup run --rm backup
```

Apply repository retention with an independently scheduled and reviewed
`restic forget --prune` policy. Destructive pruning is not part of this
Compose file.

## Restore rehearsal

At least once per release candidate:

1. Provision an isolated network and empty database.
2. Restore the selected database backup and object/media snapshot.
3. Supply the matching credential-encryption key version.
4. Run migrations once.
5. Start API, worker, and outbox services.
6. Verify health and readiness.
7. Verify cross-home isolation with synthetic tenants.
8. Reverse a catalog merge and reproduce a stored suggestion explanation.
9. Verify retained media access and deletion using synthetic data.
10. Replay outstanding queue/outbox work twice and prove idempotency.
11. Record elapsed recovery time, recovered point, checksums, and failures.

RPO and RTO are operational service objectives and must be approved from
measured rehearsal evidence rather than invented in application code.

## Cutover and rollback

Before cutover, freeze the release digest and migration set, complete source
reconciliation, export device-local data, validate MySQL and MariaDB profiles,
and take a pre-cutover backup.

Deploy with a canary, observe API errors/latency, DB saturation, queue lag,
outbox failures, authentication abuse, and AI/payment failure metrics. Roll back
application containers to the prior digest only when the schema is
backward-compatible. If a migration is not backward-compatible, use the
release-specific, rehearsed forward-fix or restore plan; never improvise a
production down-migration.

## Release gates still requiring evidence

This file establishes the deployable topology but does not itself close Phase
10. The final acceptance report must attach:

- current-branch quality, contract, database-matrix, and queue-profile results;
- coverage and mutation results for high-risk domain logic;
- secret, dependency, container, and dynamic security scans;
- large-home load, soak, queue recovery, and failover results;
- live SMTP retry/dead-letter proof;
- full typed offline convergence proof;
- multi-provider AI/media acceptance proof;
- payment sandbox and webhook-idempotency proof;
- backup/restore rehearsal evidence;
- monitored canary and rollback exercise;
- reconciled Phase 0–8 acceptance checklists.
