# Production environment reference

This reference covers the variables accepted by
[`compose.production.yaml`](../../compose.production.yaml), its bind-mount
override, and [`scripts/setup-production.sh`](../../scripts/setup-production.sh).
The checked-in [env example](../../.env.production.example) is a template;
`setup-production.sh --prepare-only` creates independent secrets and a protected
file for your deployment. See [production deployment](production.md) first.

Docker Compose does not pass every host/env-file variable into every container.
The Compose `environment` mappings determine what the application receives.
Variables prefixed `PROVIDENTIA_` below often configure Compose or Caddy rather
than the PHP application. The source of application semantics is
[`config/autoload/global.php`](../../config/autoload/global.php).

**Secret handling:** keep the generated file mode `0600` and outside source
control; store an encrypted backup and restrict Docker host access. Docker
administrators can inspect container environment variables. `docker compose
config` without `--quiet` prints resolved secrets. Use a secret manager to
materialize the same configuration at deployment time when required.

**DSNs:** URL-encode user names/passwords when they contain reserved URL
characters. Quote literal `$` values using Compose env-file single-quote
syntax, so Compose does not interpolate them. Never `source` an untrusted env
file. Byte counts use bytes; durations below use seconds unless stated.

## Release, selection and storage

| Variable | Default / required | Meaning |
|---|---|---|
| `APP_VERSION` | Required; release `images.env` supplies it | Backend release displayed by runtime; must match all three selected images |
| `PROVIDENTIA_IMAGE` | Required | Digest-pinned API/CLI image |
| `PROVIDENTIA_WEB_IMAGE` | Required | Matching Caddy image digest |
| `PROVIDENTIA_MEDIA_IMAGE` | Required | Matching FFmpeg worker image digest |
| `PROVIDENTIA_SETUP_STARTED` | `0`, then `1` on first deployment | Helper recovery marker; preserve it with the original env file so setup can detect missing configuration with existing volumes |
| `PROVIDENTIA_DATABASE` | `mysql` | Helper metadata: `mysql`, `mariadb` or `external`; selects the one database topology |
| `PROVIDENTIA_MANAGED_REDIS` | `1` | Helper metadata: `1` starts local Redis, `0` uses the external `QUEUE_DSN` |
| `COMPOSE_PROFILES` | Helper selects `mysql,redis` | Local infrastructure selection: use one of `mysql` or `mariadb`; omit either when external; optional `redis`; `tools` and `backup` are one-shot profiles |
| `PROVIDENTIA_DATA_DIRECTORY` | Optional, supplied by `--data-directory` | Host parent directory used by the bind-mount override; contains separate application, database and Redis subdirectories |
| `AI_VIDEO_TMPFS_SIZE` | `512m` | `/tmp` capacity for video processing; allow space for the upload and extracted frames and budget host/container memory accordingly |

Production Compose fixes `APP_ENV=production`, `APP_DEBUG=0`,
`AUTH_COOKIE_SECURE=1`, `QUEUE_REQUIRED=1`, `METRICS_ENABLED=1` and
`BILLING_ALLOW_PRIVATE_ENDPOINTS=0`. These are intentional production policies;
changing a similarly named host variable does not override a literal Compose
value. The deployment helper clears inherited shell overrides so the reviewed
file is authoritative; direct `docker compose` commands still follow Compose
precedence, where exported shell variables can override an env file. The app's workdir is `/app`; relative filesystem roots below live there.

## Public origin, HTTP and monitoring

| Variable | Default / required | Meaning |
|---|---|---|
| `PUBLIC_BASE_URL` | Required | Public HTTPS origin, e.g. `https://api.example.net`; no path, credentials, query or fragment |
| `PROVIDENTIA_BIND_ADDRESS` | `127.0.0.1` | Explicit host IPv4 address for Caddy's HTTP port; use `--bind-address` during preparation and bind to a private interface only when the TLS proxy runs elsewhere |
| `PROVIDENTIA_HTTP_PORT` | `8080` | Host TCP port from 1 through 65535 forwarded to Caddy port 8080; use `--http-port` during preparation |
| `PROVIDENTIA_FPM_UPSTREAM` | `api:9000` | Caddy's private PHP-FPM upstream; use private routing if separating Caddy and API hosts |
| `PROVIDENTIA_TRUSTED_PROXY_CIDRS` | Required | Space-separated proxy IP/CIDR allowlist for forwarded-client-IP handling; include only actual trusted proxy hops |
| `CORS_ALLOWED_ORIGINS` | `PUBLIC_BASE_URL` | Comma-separated exact HTTPS browser origins, set with `--cors-origins`; wildcards, credentials, paths, queries and fragments are rejected; native applications do not need entries; the backend public origin is also allowed by application configuration |
| `PROVIDENTIA_METRICS_TOKEN` | Required; independent random secret | At least 32 characters; protects `web:9090/metrics` using `Authorization: Bearer ...` |

Compose maps `PROVIDENTIA_TRUSTED_PROXY_CIDRS` into the app's
`TRUSTED_PROXY_CIDRS` and supplies it to Caddy.
`PROVIDENTIA_METRICS_TOKEN` becomes PHP's `METRICS_BEARER_TOKEN` and Caddy's
matching credential. Port 9090 is internal and is not published on the host;
`/metrics` returns 404 on the public HTTP listener. Attach monitoring to the
appropriate private network and retain both the token and network boundary.

## Database and queue

The production database choices are the bundled **MySQL 8.4** profile, the
bundled **MariaDB 11.8** profile, or an external MySQL/MariaDB service using the
same PDO MySQL connection boundary. PostgreSQL is not supported by the runtime
image, connection factory, migration/SQL implementation, setup helper or CI
matrix. A PostgreSQL DSN is rejected; changing that would require a separately
implemented and tested database adapter, not an env-file substitution.

| Variable | Default / required | Meaning |
|---|---|---|
| `DATABASE_URL` | Required | One MySQL/MariaDB connection, e.g. `mysql://providentia:ENCODED_PASSWORD@mysql:3306/providentia?charset=utf8mb4`; external DB uses its private hostname |
| `MYSQL_DATABASE` | `providentia` | Initial schema created by the local MySQL container |
| `MYSQL_USER` | `providentia` | Initial application account for local MySQL |
| `MYSQL_PASSWORD` | Required when local MySQL is selected | Application password; must match the DSN |
| `MYSQL_ROOT_PASSWORD` | Required when local MySQL is selected | Independent database administration password |
| `MARIADB_DATABASE` | `providentia` | Initial schema for the alternative MariaDB profile |
| `MARIADB_USER` | `providentia` | Initial application account for local MariaDB |
| `MARIADB_PASSWORD` | Required when local MariaDB is selected | Application password; must match the DSN |
| `MARIADB_ROOT_PASSWORD` | Required when local MariaDB is selected | Independent database administration password |
| `QUEUE_DSN` | Required | Enqueue Redis DSN, e.g. `redis+phpredis://:ENCODED_PASSWORD@redis:6379`; use private hostname for an external broker |
| `QUEUE_NAME` | `providentia.default` | Queue name; keep the same value across API, relay and consumer for this deployment |
| `REDIS_PASSWORD` | Required when local Redis is selected | Broker password matching `QUEUE_DSN` |
| `OUTBOX_BATCH_SIZE` | `100` | Maximum outbox events relayed per batch |
| `OUTBOX_MAX_ATTEMPTS` | `10` | Retry budget before failed-message review |
| `OUTBOX_POLL_SECONDS` | `2` | Delay between relay invocations in the Compose shell loop |

MySQL/MariaDB initialization variables apply when the data directory is empty.
Editing them does not change accounts in an existing database. Coordinate SQL
credential changes with the secret and `DATABASE_URL`; preserve existing
secrets during upgrades. The generated configuration supplies the selected
profile; values for unused database/backup services do not have to be invented.

## Container lifecycle and local logging

Production Compose uses Docker's `json-file` log driver with `max-size=10m`
and `max-file=5` for every enabled service. This bounds local container log
files; forward required operational and audit events to controlled external
storage before the local rotation window expires. Application logging remains
content-safe and must not be changed to include provider credentials or
household media.

The API and web services have readiness health checks. Long-running workers
explicitly disable the runtime image's process-only health check because a live
PID does not prove queue, SMTP, database, or job progress. Monitor worker error
rates, lease/job age, queue/outbox lag and last-success timestamps externally.
The AI video worker has a 240-second stop grace period so an ordinary deployment
can finish or safely interrupt work within its configured processing timeout;
container termination is still not a completion guarantee.

## Authentication, email and synchronization

| Variable | Default / required | Meaning |
|---|---|---|
| `AUTH_TOKEN_PEPPER` | Required independent secret | At least 32 characters of random material; protects authentication proofs; changing it invalidates affected authentication state |
| `AUTH_ACCESS_TTL_SECONDS` | `900` | Access-token lifetime; minimum 300 |
| `AUTH_REFRESH_TTL_SECONDS` | `2592000` (30 days) | Rotating refresh-token lifetime; minimum 3600 |
| `AUTH_WEB_IDLE_TTL_SECONDS` | `0` | Browser session inactivity ceiling; zero disables the additional idle ceiling, positive values have a 900-second floor |
| `AUTH_NATIVE_IDLE_TTL_SECONDS` | `0` | Native session inactivity ceiling with the same zero/floor rules |
| `AUTH_RATE_LIMIT_RETENTION_DAYS` | `2` | Authentication rate-limit retention; bounded to 1–30 days |
| `MAIL_DSN` | Required; operator supplies SMTP credentials | Verified implicit TLS SMTP, e.g. `smtps://smtp-user:ENCODED_PASSWORD@smtp.example.net:465`; production rejects plain `smtp://` because the transport does not implement verified STARTTLS |
| `MAIL_FROM` | Required | Authorized sender mailbox, e.g. `no-reply@example.net` |
| `NOTIFICATION_PAYLOAD_KEK` | Required; base64 of exactly 32 random bytes | Encrypts queued email payloads; retain for queued/recoverable notifications |
| `NOTIFICATION_KEY_VERSION` | `1` | Positive version label for notification encryption |
| `NOTIFICATION_BATCH_SIZE` | `100` | Email batch limit, bounded to 1–500 |
| `NOTIFICATION_MAX_ATTEMPTS` | `10` | Delivery attempts, bounded to 1–50 |
| `SYNC_CURSOR_SECRET` | Required independent secret | At least 32 characters, distinct from `AUTH_TOKEN_PEPPER`; signs synchronization cursors |
| `SYNC_CURSOR_TTL_SECONDS` | `2592000` (30 days) | Cursor lifetime; minimum 3600 |
| `SYNC_OFFLINE_WINDOW_DAYS` | `90` | Supported offline horizon used by synchronization retention |
| `SYNC_TOMBSTONE_RETENTION_DAYS` | `120` | Retains deletion evidence for returning devices; keep consistent with the offline horizon |

Login uses emailed codes entered in the requesting app. The notification worker
and real SMTP must run for login to work. Zero idle TTL does not bypass logout,
revocation, account disablement or refresh-rotation security checks.

## AI provider integration

AI server proxying defaults off. The existence of configuration does not enable
platform-funded AI or billing. Stored person/home provider credentials remain
private and encrypted. Follow the current
[AI BYOK setup and provider acceptance runbook](ai-byok.md) before enabling it.

| Variable | Default / required | Meaning |
|---|---|---|
| `AI_SERVER_PROXY_ENABLED` | `0` | Enables backend provider orchestration when deliberately configured |
| `AI_CREDENTIAL_KEK` | Required when proxy enabled; base64 32-byte key | Encrypts stored provider credentials; preserve matching key versions |
| `AI_CREDENTIAL_KEY_VERSION` | `1` | Positive credential-encryption version |
| `AI_ORCHESTRATION_MAX_ATTEMPTS` | `8` | Provider orchestration retry limit, bounded to 2–8 |
| `AI_COMPATIBLE_ENDPOINT` | Empty | Optional OpenAI-compatible base endpoint; adapter appends `/v1/chat/completions`; production requires HTTPS |
| `AI_OLLAMA_ENDPOINT` | Empty | Optional deployment-level Ollama base endpoint; adapter appends `/api/chat` |
| `AI_ALLOW_PRIVATE_ENDPOINTS` | `0` | Private-endpoint policy for deployment-level provider adapters; only enable with deliberate restricted-network configuration |
| `AI_ALLOW_PRIVATE_NETWORK_ENDPOINTS` | `0` | Separate person/home-profile policy: allows Ollama profiles to use HTTP/private or loopback hosts; other profile providers remain public HTTPS |
| `AI_MAX_IMAGE_BYTES` | `8388608` (8 MiB) | Maximum image input; bounded to 1–16 MiB |
| `AI_MAX_IMAGES` | `8` | Images per request; bounded to 1–8 |

## Private media, catalog images and exports

| Variable | Default / required | Meaning |
|---|---|---|
| `AI_MEDIA_ROOT` | `var/private-media` | Filesystem root for encrypted private media; must be shared by readers/writers across hosts |
| `AI_MEDIA_KEK` | Required; base64 32-byte key | Private-media encryption key |
| `AI_MEDIA_KEY_VERSION` | `1` | Positive media-key version |
| `AI_MEDIA_DEFAULT_QUOTA_BYTES` | `2147483648` (2 GiB) | Default retained-media quota; minimum 1 MiB |
| `AI_MEDIA_TRANSIENT_TTL_SECONDS` | `86400` (24 hours) | Transient-media retention; minimum 3600 |
| `AI_MEDIA_MAX_EXPORT_BYTES` | `67108864` (64 MiB) | Media included in an export; minimum 1 MiB |
| `AI_MAX_VIDEO_BYTES` | `134217728` (128 MiB) | Video upload cap; bounded to 1–512 MiB |
| `AI_MAX_VIDEO_DURATION_SECONDS` | `300` | Maximum accepted duration; bounded to 1–3600 |
| `AI_MAX_VIDEO_FRAMES` | `12` | Extracted frame limit; bounded to 1–60 |
| `AI_VIDEO_PROCESS_TIMEOUT_SECONDS` | `180` | FFmpeg/FFprobe processing timeout; bounded to 30–900 |
| `AI_FFPROBE_BINARY` | `/usr/bin/ffprobe` | Probe binary provided by the media-worker image |
| `AI_FFMPEG_BINARY` | `/usr/bin/ffmpeg` | Frame-extraction binary provided by the media-worker image |
| `CATALOG_IMAGE_KEK` | Required; independent base64 32-byte key | Encryption for catalog-contribution images stored in the database |
| `CATALOG_IMAGE_KEY_VERSION` | `1` | Positive 32-bit key version; unique among current/previous keys |
| `CATALOG_IMAGE_PREVIOUS_KEYS_JSON` | `[]` | JSON list of retained decrypt-only keys, e.g. `[{"version":1,"kek":"BASE64_KEY"}]` when the current version is newer |
| `DATA_EXPORT_KEK` | Required; base64 32-byte key | Encrypts account-export artifacts; back up with controlled key escrow |
| `DATA_EXPORT_ROOT` | `var/data-exports` | Export artifact filesystem root, shared with API/download and governance processes |
| `DATA_EXPORT_PAGE_SIZE` | `250` | Rows per export page; bounded to 25–1000 |

Keep default filesystem roots beneath `/app/var` unless you also add persistent
mounts for new paths. Read-only root filesystems make arbitrary directories
unwritable. Video temporary space is separately controlled by
`AI_VIDEO_TMPFS_SIZE`; an increased upload cap also requires capacity review of
that memory-backed directory and the HTTP proxy request-size cap (160 MB in
the bundled Caddy configuration).

A key-version number is metadata, not a key-rotation migration. Never overwrite
an encryption key while its data or backups still require it. Catalog images
have an explicit previous-key list; other encryption domains require a
release-specific migration and restoration plan before rotation.

## Billing (disabled baseline)

Keep `BILLING_ENABLED=0`, `PAYPAL_ENABLED=0` and `HOSTED_CARD_ENABLED=0` for the
current approved release scope. These fields document the existing code; they
do not authorize enabling production billing.

| Variable | Default / required | Meaning |
|---|---|---|
| `BILLING_ENABLED` | `0` | Billing feature gate; enabling production requires an enabled checkout provider |
| `BILLING_HTTP_TIMEOUT_SECONDS` | `10` | Provider timeout, bounded to 2–30 |
| `BILLING_MAXIMUM_RESPONSE_BYTES` | `1048576` (1 MiB) | Response cap, bounded to 64 KiB–4 MiB |
| `PAYPAL_ENABLED` | `0` | PayPal gate |
| `PAYPAL_ENVIRONMENT` | `live` in production Compose | `live` or `sandbox`; selects PayPal's corresponding API |
| `PAYPAL_CLIENT_ID` | Empty; required if PayPal enabled | Provider client identifier |
| `PAYPAL_CLIENT_SECRET` | Empty; required if PayPal enabled | Provider secret |
| `PAYPAL_WEBHOOK_ID` | Empty; required if PayPal enabled | Configured alphanumeric webhook identifier |
| `HOSTED_CARD_ENABLED` | `0` | Hosted-card checkout gate |
| `HOSTED_CARD_API_BASE` | Empty | HTTPS checkout API origin/base |
| `HOSTED_CARD_CHECKOUT_PATH` | `/v1/checkout/sessions` | Session-creation endpoint path |
| `HOSTED_CARD_REDIRECT_HOSTS` | Empty | Comma-separated permitted checkout redirect hosts |
| `HOSTED_CARD_API_KEY` | Empty | Checkout provider API credential |
| `HOSTED_CARD_WEBHOOK_SECRET` | Empty; at least 32 characters if enabled | Webhook authentication secret |
| `HOSTED_CARD_WEBHOOK_SIGNATURE_HEADER` | `X-Webhook-Signature` | Signature header expected from provider |
| `HOSTED_CARD_WEBHOOK_TIMESTAMP_HEADER` | `X-Webhook-Timestamp` | Timestamp header expected from provider |
| `HOSTED_CARD_WEBHOOK_TOLERANCE_SECONDS` | `300` | Timestamp tolerance, bounded to 30–900 |

## Optional backup profile

None of these values is required to run the application without the `backup`
profile. The operator creates a consistent backup staging set before invoking
Restic; the container does not automatically dump live SQL databases or export
secret-manager contents.

| Variable | Default / required | Meaning |
|---|---|---|
| `RESTIC_REPOSITORY` | Required for backup | Restic destination, e.g. `s3:s3.example.net/providentia` |
| `RESTIC_PASSWORD_FILE` | Required for backup | Host path to protected repository-password file; mounted as `/run/secrets/restic_password` |
| `RESTIC_AWS_ACCESS_KEY_ID` | Empty | Optional S3-compatible access key; mapped to `AWS_ACCESS_KEY_ID` inside backup container |
| `RESTIC_AWS_SECRET_ACCESS_KEY` | Empty | Optional S3-compatible secret key; mapped to `AWS_SECRET_ACCESS_KEY` |
| `BACKUP_STAGING_DIRECTORY` | Required for backup | Host directory containing consistent prepared backup files; mounted read-only as `/backup-source` |
| `BACKUP_HOST` | `providentia` | Stable host label attached to the Restic snapshot |

The Docker Hub publication variable and secrets are GitHub Actions settings,
not runtime application configuration; see
[releases and registries](release-process.md#optional-docker-hub-mirror).
