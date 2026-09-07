# Deploy the backend on a server

Use a release's prebuilt images and deployment bundle. Your server does not
need PHP, Composer or a local image build. The initial supported topology is a
single Compose host, one SQL database (MySQL by default), Redis, Caddy, the PHP
API and one process for each background role. External SQL/Redis services and
host-mounted persistence are supported.

For the image addresses and release policy, see
[releases and registries](release-process.md). For each container's purpose
and the limits on splitting hosts or adding replicas, see
[architecture and scaling](architecture-and-scaling.md).

## 1. Prepare the server and public URL

Provide:

- Linux with Docker Engine and **Compose v2** supporting `up --wait`;
- Bash, Python 3 and `flock`; `curl` and `tar` for release downloads;
- an administrator account that can access Docker and write the selected
  configuration and data directories;
- a stable DNS name, such as `api.example.net`, and a trusted TLS reverse proxy;
- SMTP with authenticated implicit TLS (`smtps://`), an authorized sender and
  network access from the notification process;
- disk capacity for the database, retained media, exports and backup staging;
  memory/CPU capacity for API requests and FFmpeg processing.

No domain is hardcoded in the backend image. Configure DNS for your HTTPS edge,
then route that edge to the Compose host's HTTP listener. On the same host, the
default is `127.0.0.1:8080`; a proxy on another host needs an explicitly selected
private bind address and a firewall that admits the proxy. The external edge
owns certificates and renewals. Set its request-body and timeout limits to
support the intended media workload (bundled Caddy allows requests up to
160 MB).

Use `https://api.example.net` as `PUBLIC_BASE_URL` and as the compiled client
API origin. It must be an origin only, with no `/api/v1` suffix. Set
`PRODUCTION_API_BASE_URL` in both the client and admin repositories' production
build configuration. Existing installed apps retain their compiled URL;
server moves should happen behind that same hostname. See
[client build settings](architecture-and-scaling.md#keep-the-clients-url-stable).

## 2. Download one published release

Choose an actual backend `vX.Y.Z` release from
[GitHub Releases](https://github.com/providentia-systems/backend/releases).
The first automated version will be `v0.1.0`; use that example only once it
exists. Keep each release's files in its own directory. Do not combine a
release image lock with unrelated deployment files from a later `main`.

```bash
release_version=0.1.0
release_url="https://github.com/providentia-systems/backend/releases/download/v${release_version}"
mkdir -p "/opt/providentia/releases/v${release_version}"
cd "/opt/providentia/releases/v${release_version}"
for asset in "providentia-backend-v${release_version}.tar.gz" images.env release-manifest.json SHA256SUMS; do
  curl --fail --location --output "$asset" "$release_url/$asset"
done
sha256sum --check SHA256SUMS
tar -xzf "providentia-backend-v${release_version}.tar.gz"
cd "providentia-backend-v${release_version}"
```

The archive includes its matching `images.env`, which pins the runtime, web
and media-worker manifests by digest. If GHCR packages are private, perform
[registry login](release-process.md#pull-access) before deployment.

## 3. Generate and review the protected configuration

Run this from the extracted release directory. Replace the example domain,
mailbox and proxy address; `192.0.2.10/32` is documentation-only.

```bash
bash scripts/setup-production.sh \
  --env-file /etc/providentia/production.env \
  --image-env images.env \
  --public-url https://api.example.net \
  --mail-from no-reply@example.net \
  --trusted-proxies 192.0.2.10/32 \
  --data-directory /srv/providentia \
  --prepare-only
```

This creates independent random secrets and a mode-`0600` configuration file.
Preparation does not contact Docker or send mail. Edit `MAIL_DSN` in that file
using your protected administration workflow:

```dotenv
MAIL_DSN='smtps://smtp-user:URL_ENCODED_PASSWORD@smtp.example.net:465'
```

Use your actual SMTP details and URL-encode reserved characters in DSN
credentials. Production rejects plain SMTP: this mail transport does not
implement verified STARTTLS. Protect the generated file and its encryption
keys in your secret manager/backup mechanism before onboarding users.

`--prepare-only` is required for a new configuration. Reusing the same env file
preserves secrets; it does not regenerate the database password on each start.
Setup refuses a fresh configuration against detected existing data instead of
guessing replacement keys. Restore the matching original env file and preserve
its `PROVIDENTIA_SETUP_STARTED` marker.
Review all fields in the [environment reference](environment-reference.md).
For a native-only deployment, keep `CORS_ALLOWED_ORIGINS` equal to the public API
origin; when deploying a browser client, add its exact HTTPS origin.

### Choose MySQL, MariaDB or an external database

The default helper selection is **MySQL plus Redis**. To choose MariaDB, add
`--database mariadb` during initial preparation; only that SQL engine is
started. You do not need to supply credentials for an unused engine.

For an external database, use `--database external --database-url DSN` during
preparation. Set `--queue-dsn DSN` to use an external Redis/Valkey service and
omit the bundled Redis process. For credentials that must not appear in shell
history, obtain the DSN through your protected deployment mechanism or edit the
protected env file before deployment. Corresponding helper metadata is
`PROVIDENTIA_DATABASE=external` and `PROVIDENTIA_MANAGED_REDIS=0`; the helper
derives `COMPOSE_PROFILES` on its next run.

All application roles must be able to reach the selected private SQL and Redis
endpoints. Provision external schemas, least-privilege application credentials,
transport security and backups separately. The helper does not create managed
cloud database instances or configure their network policy.

### Choose host directories or named volumes

With `--data-directory /srv/providentia`, the helper applies
[`compose.production.bind.yaml`](../../compose.production.bind.yaml):

| Host path | Container path | Contents |
|---|---|---|
| `/srv/providentia/app-var` | `/app/var` | Encrypted private media, exports and application runtime files |
| `/srv/providentia/mysql` | `/var/lib/mysql` in MySQL | Data for the selected MySQL engine |
| `/srv/providentia/mariadb` | `/var/lib/mysql` in MariaDB | Alternative MariaDB data; not used alongside MySQL |
| `/srv/providentia/redis` | `/data` | Redis append-only persistence |

Select the parent directory before initial deployment. Omitting
`--data-directory` uses Docker-managed named volumes with the same container
paths. Moving an existing deployment between persistence layouts needs a
planned data migration; editing the path alone does not move its data.

The helper creates needed host directories and initializes ownership for a new
empty application mount (UID/GID **82:82**). It does not recursively rewrite
existing data ownership. Restored/shared application files must already be
readable and writable by that UID. Database container ownership is managed by
the selected database image. Keep SQL directories separate; never mount one
engine's raw data directory into the other engine.

## 4. Deploy and verify

```bash
bash scripts/setup-production.sh --env-file /etc/providentia/production.env
```

The helper validates configuration without printing resolved secrets, pulls
the selected images, initializes the application volume and waits for local
infrastructure. It then stops application processes, runs migrations **once**,
and starts all roles with readiness checks. This sequence creates a maintenance
window; it is not a zero-downtime rolling deployment. A failed migration leaves
the applications stopped for operator recovery and does not erase data.

Verify the actual public route:

```bash
curl --fail-with-body https://api.example.net/health/live
curl --fail-with-body https://api.example.net/health/ready
curl --fail-with-body https://api.example.net/api/v1/system/info
```

The server root returns a JSON 404 because the product is a headless API.
There is no browser sign-in or administration UI. Test email-code login from
the real client and verify SMTP delivery before handing the deployment to
users. Health checks alone do not prove mail delivery or all background jobs.

## Operational commands

From the selected extracted release directory, define this Bash convenience
function for the bind-mounted example above:

```bash
pc() {
  docker compose --env-file /etc/providentia/production.env \
    -f compose.production.yaml -f compose.production.bind.yaml "$@"
}
pc ps
pc logs --tail=200 api web notification worker outbox
```

For Docker-managed named volumes, omit `-f compose.production.bind.yaml` from
that function. Always supply the production Compose files: the default
`compose.yaml` is the source-development setup. The helper saves the selected
infrastructure in `COMPOSE_PROFILES` but does not set `COMPOSE_FILE` globally.

Use the CLI without installing PHP on the host:

```bash
pc exec -T api php bin/providentia list
pc exec -T api php bin/doctrine-migrations migrations:up-to-date --no-interaction
```

Authorize the intended first system owner through the server CLI:

```bash
pc exec -T api php bin/providentia system:owner owner@example.net
```

Use your actual owner's verified identity and the
[account/admin workflow](client-user-testing.md). Configure country publication,
onboarding policy and starter groups before public onboarding. A clean install
does not import private household data or invent a seed dataset. If using the
authorized starter catalog, mount only the verified catalog files:

```bash
pc --profile tools run --rm --no-deps \
  -v /srv/providentia/seed:/seed:ro seed
```

Monitor API failures/latency, DB capacity, disk space, queue lag, outbox failures,
SMTP retry counts and long-running job health. Metrics are available at
`http://web:9090/metrics` only from an attached private container network and
require the independent bearer token. Port 9090 is not published on the host,
and public `/metrics` returns 404. The public listener, SQL, Redis and PHP-FPM
ports should not be repurposed as monitoring shortcuts.

## Backups and restore

Treat SQL, application artifacts, configuration/keys and release metadata as
one recoverable state set. A copy of live `/var/lib/mysql` is not a reliable
logical database backup. Redis AOF helps broker recovery but does not replace
the database or the committed outbox.

For the single-host baseline, a maintenance backup can stop application writers,
make a transactional database dump, then copy `app-var` and the protected
configuration. For example, with MySQL and the `pc` function above:

```bash
(
set -euo pipefail
umask 077
pc stop web api worker outbox notification reference-update data-governance sync-compactor ai-video-worker
install -d -m 0700 /srv/providentia/backup-staging
pc exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --single-transaction --no-tablespaces --user="$MYSQL_USER" "$MYSQL_DATABASE"' \
  > /srv/providentia/backup-staging/database.sql
tar --numeric-owner -C /srv/providentia -czf /srv/providentia/backup-staging/app-var.tar.gz app-var
install -m 0600 /etc/providentia/production.env /srv/providentia/backup-staging/production.env
cp images.env release-manifest.json /srv/providentia/backup-staging/
pc up -d --wait api web worker outbox notification reference-update data-governance sync-compactor ai-video-worker
)
```

A failed step stops this backup sequence and leaves the application stopped for
inspection. Run this under a private administrative account with a restrictive `umask 077`.
Check every command and the dump before proceeding; a failed dump must not be
accepted as a backup. For MariaDB use `mariadb-dump` and its `MARIADB_*`
credentials. For external SQL or named application volumes, use the provider's
consistent snapshot/dump and the corresponding volume-backup procedure. Keep
Redis persistence in the infrastructure recovery plan for outstanding queued
work.

The optional Restic service uploads the **prepared** staging directory; it does
not execute the preceding snapshot steps. Configure `RESTIC_REPOSITORY`, the
protected `RESTIC_PASSWORD_FILE` and any S3 credentials in the env file. Initialize
a new repository once, then take and verify a snapshot:

```bash
pc --profile backup run --rm backup init
pc --profile backup run --rm backup
pc --profile backup run --rm backup check
```

Do not run `init` for an existing repository. Schedule backup creation, snapshot
verification and a separately reviewed retention policy. The staging files are
sensitive even when the Restic destination is encrypted; restrict access and
manage staging retention. Losing encryption keys can make restored credentials,
media, notifications or exports unreadable. Key-version numbers alone cannot
recover the data.

Rehearse restoration into an isolated environment: restore the database and
matching application files, preserve numeric ownership, restore matching keys
and release configuration, migrate deliberately if needed, start all roles and
check HTTPS readiness, real/synthetic login, retained media, exports and queue
recovery. Record the recovered point and elapsed recovery time before promising
an RPO or RTO. Historical acceptance detail remains in
[Phase 10 hardening](../operations/phase-10-hardening.md).

## Upgrade and rollback

1. Download the new release bundle, verify checksums and read its release notes
   and migration changes.
2. Take and verify a consistent backup, retaining the previous image lock and
   configuration. Preserve all existing encryption keys and DB credentials.
3. From the new release directory, apply its image lock to the same protected
   env file and deploy in the chosen maintenance window:

   ```bash
   bash scripts/setup-production.sh \
     --env-file /etc/providentia/production.env --image-env images.env
   ```

4. Verify HTTPS readiness, displayed version, login and application workflows;
   inspect worker errors and confirm retained artifacts remain accessible.

`--version X.Y.Z` is an alternative for selecting a release's three tags; the
official `images.env` is preferred because it pins content exactly. Releases
automatically published on GitHub do not automatically restart your server.

Rollback to the prior application digest set only when the current schema is
compatible with that older application. Do not blindly run the prior helper if
its migration expectations are incompatible. A breaking migration needs its
rehearsed forward fix or restoration of the matching pre-upgrade state, with
any loss of newer writes assessed explicitly. Never use `down --volumes` as an
upgrade or recovery step; it deletes managed persistent data.
