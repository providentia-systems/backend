# Local development and deployment profiles

## One-command MySQL/Redis prototype

Prerequisites are Docker with Compose v2, `unzip`, `sha256sum`, `curl`, `jq`,
and `openssl`. Run:

```bash
./scripts/setup-development.sh \
  --handover /absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip
```

The script:

1. extracts only `pantry-data.json` and `product-rules.json` to a temporary
   directory;
2. verifies their two fixed Phase 0 SHA-256 digests;
3. creates `.env.development.local` with mode `0600`, independent random
   secrets, random MySQL passwords, and a stable local device ID;
4. starts MySQL 8.4, Redis 8.2, Mailpit, the HTTP API, worker, and outbox relay;
5. applies both Doctrine migrations through the container entrypoint;
6. runs the catalog reconciliation dry run, committed import, and a second
   zero-delta import proof;
7. provisions and verifies a developer account, safely recovers a prior
   unverified account through the throttled resend flow, and creates or reuses
   a home;
8. writes `.providentia-development.json` with mode `0600` for the local client.

The handoff contains loopback development credentials and must never be
committed, shared, or reused outside the local workstation. The setup is
repeatable: subsequent runs reuse the local device/account and do not duplicate
catalog identities.

Setup never overwrites an existing verified account. A wrong password or
locked/rate-limited account stops with an actionable message; it does not
blindly retry registration or delete local data. Development verification
tokens are returned only because the isolated Compose profile explicitly sets
`EXPOSE_DEVELOPMENT_TOKENS=1`; production startup forbids that setting.

Default endpoints:

- API: `http://127.0.0.1:8080`
- readiness: `http://127.0.0.1:8080/health/ready`
- Mailpit UI: `http://127.0.0.1:8025`

To override ports or developer identity, use `PROVIDENTIA_HTTP_PORT`,
`PROVIDENTIA_MAILPIT_PORT`, `PROVIDENTIA_DEV_EMAIL`, or the corresponding
script options. The verified archive can also be supplied through
`PROVIDENTIA_HANDOVER_ZIP`.

Reset is intentionally destructive and requires an exact confirmation:

```bash
./scripts/reset-development.sh --confirm-destroy-local-data
```

It removes the Providentia containers and named development volumes. The
protected secrets/handoff files remain for explicit manual handling.

## Server-side SQLite

SQLite is the zero-configuration server demonstration and automated-test
profile. It is not the production high-volume database.

```bash
docker compose --profile sqlite --profile valkey up --build --wait
```

The SQLite API starts even when the optional queue is unavailable because
`QUEUE_REQUIRED=0`. A worker requires an active broker.

## MySQL plus Redis

```bash
docker compose --profile mysql --profile redis up --build --wait
docker compose run --rm api-mysql php bin/providentia outbox:relay
docker compose run --rm api-mysql php bin/providentia queue:consume
```

## MariaDB plus Valkey

```bash
docker compose --profile mariadb --profile valkey up --build --wait
docker compose run --rm api-mariadb php bin/providentia outbox:relay
docker compose run --rm api-mariadb php bin/providentia queue:consume
```

The database and queue services have no host port publication. MySQL and Redis
are the preferred production profiles; MariaDB, SQLite, and Valkey remain
verified compatibility/test profiles. For remote production services, use TLS
and private networking, supply backend-only DSNs, and do not include them in
client configuration.

The bundled database passwords are known local-development defaults. Override
all password variables before using a shared host.

## Production configuration fail-closed rules

Start from `.env.production.example` and inject values from the deployment
secret manager. With `APP_ENV=production`, startup rejects:

- placeholder, short, or identical authentication/cursor secrets;
- exposed development tokens;
- non-`smtps://` mail transport (verified STARTTLS is not implemented by this
  minimal SMTP adapter);
- a non-HTTPS public base URL.

The production SMTP connection verifies the certificate, hostname, and SNI.
Plain `smtp://mailpit:1025` is permitted only for isolated non-production
development. CORS origins must be explicit; no wildcard is configured.
