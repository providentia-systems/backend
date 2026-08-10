# Run the published backend images locally

This is the fastest supported path for pointing Flutter at a complete local
Providentia backend. It pulls the same production runtime, Caddy edge, and
media-worker targets that the repository builds and smoke-tests. It does not
compile PHP, Composer dependencies, Caddy, or FFmpeg on the workstation.

The local profile runs those immutable application images with MySQL 8.4,
Redis 8.2, Mailpit, migrations, the API, queue worker, outbox relay,
notification worker, data-governance worker, synchronization compactor, and
video worker. Development-only email tokens and password login are enabled
only in this loopback profile.

## Published packages and tags

| Purpose | GHCR package |
|---|---|
| PHP-FPM API, CLI, migrations, and ordinary workers | `ghcr.io/providentia-systems/backend` |
| Caddy public edge and immutable public files | `ghcr.io/providentia-systems/backend-web` |
| Isolated FFmpeg/FFprobe video worker | `ghcr.io/providentia-systems/backend-media-worker` |

Every publication first produces `sha-<12-character-commit>` candidate tags.
The workflow scans both published platforms and exercises the exact registry
digests before a merge to `main` may update `edge`, or a `vX.Y.Z` tag may
update `X.Y.Z` and `latest`. A failed candidate can therefore retain a `sha-*`
tag for investigation but is never promoted. Production deployments must pin
the three recorded successful digests; `edge` and `latest` are convenience
tags, not immutable release identities.

Trusted `agent/*` branches publish the same scanned immutable candidate tags
without promoting `edge`. When this script runs from an `agent/*` checkout and
no explicit version was supplied, it selects the checkout's own
`sha-<12-character-commit>` tag automatically and verifies the revision label
on all three pulled images before starting anything. This makes a pre-merge
test exercise the checked-out code rather than the older `edge` build.

The repository is private, so the packages normally require GitHub Container
Registry authentication. Use a token that can read the repository packages:

```bash
printf '%s' "$GHCR_TOKEN" | docker login ghcr.io \
  --username YOUR_GITHUB_LOGIN \
  --password-stdin
```

If package visibility is changed to public, authentication is not required for
pulls. Never put the token in Compose YAML, shell history, or a committed env
file.

## One-command local environment

Requirements:

- Docker Engine with the Compose v2 plugin;
- `curl`, `jq`, and `openssl`;
- access to all three GHCR packages.

Clone the repository only to obtain the deployment files, then run the
bootstrap:

```bash
git clone https://github.com/providentia-systems/backend.git
cd backend
bash scripts/setup-prebuilt.sh
```

The script:

1. generates `.env.prebuilt.local` with independent random local secrets and
   mode `0600`;
2. pulls the published production images;
3. starts and health-checks MySQL, Redis, and Mailpit;
4. applies Doctrine migrations exactly once;
5. starts every long-running application process and waits for readiness;
6. proves liveness, readiness, and system information over HTTP;
7. creates or reuses a verified developer account and active home; and
8. writes `.providentia-development.json` with the API URL, home, device, and
   protected development credentials.

Useful overrides are explicit and do not require editing YAML:

```bash
bash scripts/setup-prebuilt.sh \
  --version sha-0123456789ab \
  --dev-email developer@example.test \
  --http-port 18080 \
  --mailpit-port 18025
```

Use an immutable `sha-*` or `X.Y.Z` tag when reproducing a defect. Running the
script again is safe: it pulls the selected tag, reapplies only pending
migrations, reuses the account/home, and waits for the complete stack.

The registry and image namespace normally come from the Git `origin` remote.
Forks, transfers, and private registries may override them without editing the
script:

```bash
bash scripts/setup-prebuilt.sh \
  --registry ghcr.io \
  --image-namespace owner/repository
```

`PROVIDENTIA_REGISTRY` and `PROVIDENTIA_IMAGE_NAMESPACE` provide the equivalent
environment-variable overrides. Command-line values take precedence.

For additional users, role testing, the first platform-administrator grant,
and the exact client login commands, follow
[Client login, users, homes, and administrator testing](client-user-testing.md).

## Verify the runtime

The setup already performs these probes. They remain useful for manual checks:

```bash
curl --fail-with-body http://127.0.0.1:8080/health/live
curl --fail-with-body http://127.0.0.1:8080/health/ready
curl --fail-with-body http://127.0.0.1:8080/api/v1/system/info

PROVIDENTIA_BASE_URL=http://127.0.0.1:8080 \
PROVIDENTIA_ALLOW_HTTP=1 \
  bash tests/Acceptance/production-http-smoke.sh

docker compose \
  --env-file .env.prebuilt.local \
  -f compose.prebuilt.yaml \
  ps
```

Readiness proves database connectivity and migration state through the actual
Caddy-to-PHP-FPM path. The workflow starts freshly built images before initial
publication, then scans both platforms and repeats migration plus live, ready,
and system probes against the exact published digests before any convenience
tag is promoted.

## Point Flutter at it

Use the development email address from `.providentia-development.json`, choose
**Send login link** in Flutter, open the message in Mailpit, explicitly approve
it, and return to the originating client. Ignore the handoff password; it is
development compatibility data, not the production onboarding path. Do not
use saved setup-session bearer tokens as Flutter launch arguments.

| Flutter target | API base URL |
|---|---|
| Linux, Windows, or macOS on the Docker host | `http://127.0.0.1:8080` |
| Chrome at `http://localhost:8081` | `http://localhost:8080` |
| Android debug build after `adb reverse tcp:8080 tcp:8080` | `http://127.0.0.1:8080` |

Chrome must use one hostname consistently for the web origin and API because
web sessions use credentialed CORS plus HttpOnly, SameSite cookies. Production
HTTPS cookies are also Secure; the isolated loopback HTTP profile deliberately
relaxes only that Secure attribute. The fixed
development origin `http://localhost:8081` is allowed by default; a custom web
port must be added to `CORS_ALLOWED_ORIGINS` explicitly. Credentialed CORS
cannot use a wildcard.

Plain HTTP, exposed development tokens, and password login in this profile are
for loopback testing only. The current client rejects non-loopback plain HTTP,
so a USB-debuggable physical device needs the same `adb reverse` tunnel; an
untethered device needs a trusted HTTPS development endpoint. Never expose
`compose.prebuilt.yaml` to the internet or reuse its secrets in staging or
production.

## Operate and update

Show bounded logs:

```bash
docker compose --env-file .env.prebuilt.local -f compose.prebuilt.yaml \
  logs --tail=200 api web worker outbox
```

Pull the current `edge` images and safely re-run migrations:

```bash
bash scripts/setup-prebuilt.sh --version edge
```

`.env.prebuilt.local` and the named Docker volumes are one state set. MySQL
uses the generated application password only when it initializes an empty data
directory. Do not delete or regenerate the env file while retaining the old
volumes. Setup detects that missing-secrets/existing-volume condition and stops
instead of starting an image that cannot authenticate to MySQL.

Stop containers while preserving MySQL, Redis, and application data:

```bash
docker compose --env-file .env.prebuilt.local -f compose.prebuilt.yaml down
```

The supported reset permanently removes the local test database, queue, and
application artifacts, then starts the stack again using the existing protected
secrets file:

```bash
bash scripts/setup-prebuilt.sh --reset-data --version edge
```

It does not delete `.env.prebuilt.local`. A newly provisioned run overwrites
the Flutter handoff with valid credentials for the replacement database.

## Production boundary

`compose.prebuilt.yaml` proves deployability and provides Flutter integration;
it is intentionally a development configuration. Production uses
`compose.production.yaml`, `.env.production.example`, TLS at the trusted edge,
real SMTP, external secret management, digest-pinned images, backups, and the
Phase 10 operator acceptance gates. Follow the
[production deployment and recovery runbook](../product/phases/phase-10-production-cutover/deployment-and-recovery.md).
