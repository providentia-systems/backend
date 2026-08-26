# Local development and deployment profiles

## One-command MySQL/Redis prototype

Prerequisites are Docker with Compose v2, `unzip`, `sha256sum`, `curl`, `jq`,
and `openssl`. Run:

### Obtain or create the development handover

`Pantry_Stock_Project_Handover_2026-07-29.zip` is not stored in this Git
repository. The authoritative full archive contains private household source
files and receipt images, so it remains in the project owner's protected
handover storage. GitHub access does not grant access to that archive. Obtain
the exact file from the project owner or the designated secure project store,
then keep it outside the checkout (for example, in `~/Downloads` or another
access-controlled directory).

The source setup reads only these checksum-pinned members:

```text
Pantry_Stock_Project_Handover_2026-07-29/
└── 03_data_exports/
    ├── pantry-data.json
    └── product-rules.json
```

If an authorized tester has those two verified exports but not the full
archive, install the `zip` command and create a minimal setup archive:

```bash
bash scripts/create-development-handover.sh \
  --pantry-data /secure/path/pantry-data.json \
  --product-rules /secure/path/product-rules.json \
  --output "$HOME/Downloads/Pantry_Stock_Project_Handover_2026-07-29.zip"
```

The helper refuses edited exports and existing output files. It verifies these
Phase 0 SHA-256 digests before packaging:

| Export | SHA-256 |
|---|---|
| `pantry-data.json` | `ac2a74f267d7a48a460c8fae24515887f97632cddfb4a17f5f45dd07c9e90116` |
| `product-rules.json` | `8131bd3bf41c9b70f0e4cfe86c9e7de699ca0df827c6287fc9f2927e35827899` |

The minimal archive is sufficient only for backend development setup. It does
not recreate or replace the full historical handover.

### Run the source setup

```bash
./scripts/setup-development.sh \
  --handover "$HOME/Downloads/Pantry_Stock_Project_Handover_2026-07-29.zip"
```

The script:

1. extracts only `pantry-data.json` and `product-rules.json` to a temporary
   directory;
2. verifies their two fixed Phase 0 SHA-256 digests;
3. creates `.env.development.local` with mode `0600`, independent random
   secrets, random MySQL passwords, a stable local device ID, and
   `EXPOSE_DEVELOPMENT_TOKENS=1` only for this development profile;
4. starts MySQL 8.4, Redis 8.2, Mailpit, the HTTP API, worker, and outbox relay;
5. applies all pending Doctrine migrations through the container entrypoint;
6. runs the catalog reconciliation dry run, committed import, and a second
   zero-delta import proof;
7. provisions the developer account through the passwordless login-link flow —
   start, development-token approval, and PKCE exchange — and creates or
   reuses a home;
8. writes `.providentia-development.json` with mode `0600` for the local client.

The handoff contains loopback development credentials and must never be
committed, shared, or reused outside the local workstation. The setup is
repeatable: subsequent runs reuse the local device/account and do not duplicate
catalog identities.

Setup never overwrites an existing account. A rate-limited login-link request
stops with an actionable message; it does not blindly retry or delete local
data. The `developmentApprovalToken` is included in the login-link start
response only because the isolated development profile explicitly sets
`EXPOSE_DEVELOPMENT_TOKENS=1`; production startup forbids that setting.

To create additional verified accounts and exercise `manager`, `member`, or
`viewer` household membership, or to grant the separate initial platform
administrator role, follow
[Client login, users, homes, and administrator testing](client-user-testing.md).

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

The setup script also exposes the same destructive reset as an explicit option:

```bash
./scripts/setup-development.sh \
  --reset-data \
  --handover "$HOME/Downloads/Pantry_Stock_Project_Handover_2026-07-29.zip"
```

The secrets file and Docker volumes are one state set. MySQL applies its
generated user password only when initializing an empty data directory. If
`.env.development.local` is lost while named volumes remain, setup stops before
generating incompatible credentials and requires either restoration of the
matching file or the explicit reset above.

## Server-side SQLite

SQLite is the zero-configuration server demonstration and automated-test
profile. It is not the production high-volume database.
When running PHP directly on the host, confirm that `PDO::getAvailableDrivers()`
contains `sqlite`; on Ubuntu 26.04 with PHP 8.5 the required package is
`php8.5-sqlite3`. The supported container image already includes both SQLite
and MySQL PDO drivers.

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

Human sign-in is the email login-link exchange only. The generated
`.env.development.local` sets `EXPOSE_DEVELOPMENT_TOKENS=1` so setup tooling
can approve its own login link; `.env.example`, production Compose, and
`.env.production.example` keep it `0`, and production startup rejects it.

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
development. CORS origins must be explicit; no wildcard is configured. The
development default includes the fixed Flutter Chrome origins
`http://localhost:8081` and `http://127.0.0.1:8081`; custom ports must be added
to `CORS_ALLOWED_ORIGINS` explicitly.
