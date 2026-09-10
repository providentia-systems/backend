# Production server quick start

This is the shortest supported path from a clean Ubuntu server to a released
Providentia backend. It uses the release bundle and digest-pinned images; it
does not compile PHP or modify files inside a running container. Use the full
[production runbook](production.md) for restore, scaling and troubleshooting.

Providentia is a headless API. People use the separate household Client and
Linux Admin applications. Opening the API address in a browser shows a JSON
`404`; that is expected and is not a missing web interface.

## Choose the addresses and database first

In the examples below, replace these values with your own:

| Example | Replace with |
|---|---|
| `https://api.example.net` | Stable public HTTPS API origin; do not append `/api/v1` |
| `no-reply@example.net` | Sender authorized by the SMTP provider |
| `10.20.30.10` | OPNsense/HAProxy private IPv4 address |
| `10.20.30.40` | Ubuntu/Docker host private IPv4 address |
| `8080` | Private HTTP port from HAProxy to the backend |

Use exactly one supported production SQL option:

| Option | Supported version/boundary | When to use it |
|---|---|---|
| Bundled MySQL | MySQL 8.4 | Recommended default on one self-hosted server |
| Bundled MariaDB | MariaDB 11.8 | Supported alternative on one self-hosted server |
| External SQL | MySQL or MariaDB through PDO MySQL | Existing private/managed SQL service |

PostgreSQL is **not supported** by the production PHP image, connection
configuration, SQL/migrations, setup helper or CI matrix. A PostgreSQL-style
Compose file or DSN cannot make this release PostgreSQL-compatible.

## 1. Prepare Ubuntu

Install Docker Engine, Compose v2, Python, `curl`, `flock` and checksum tools.
These Ubuntu packages are sufficient on a host where the distribution packages
meet your operations policy:

```bash
sudo apt update
sudo apt install --yes ca-certificates curl docker.io docker-compose-v2 python3 util-linux
sudo systemctl enable --now docker
sudo usermod --append --groups docker "$USER"
```

Sign out and back in once after adding the Docker group, then verify:

```bash
docker version
docker compose version
python3 --version
flock --version
```

Docker administrators can read container secrets. Grant Docker access only to
trusted server administrators.

## 2. Download and verify one release bundle

Choose an existing `vX.Y.Z` from
[GitHub Releases](https://github.com/providentia-systems/backend/releases), then
set that version in this complete example:

```bash
PROVIDENTIA_RELEASE_VERSION=0.1.0
PROVIDENTIA_RELEASE_URL="https://github.com/providentia-systems/backend/releases/download/v${PROVIDENTIA_RELEASE_VERSION}"
PROVIDENTIA_RELEASE_PARENT="/opt/providentia/releases/v${PROVIDENTIA_RELEASE_VERSION}"
sudo install -d -m 0755 -o "$USER" -g "$(id -gn)" "$PROVIDENTIA_RELEASE_PARENT"
cd "$PROVIDENTIA_RELEASE_PARENT"
curl --fail --location --output "providentia-backend-v${PROVIDENTIA_RELEASE_VERSION}.tar.gz" \
  "$PROVIDENTIA_RELEASE_URL/providentia-backend-v${PROVIDENTIA_RELEASE_VERSION}.tar.gz"
curl --fail --location --output images.env "$PROVIDENTIA_RELEASE_URL/images.env"
curl --fail --location --output release-manifest.json "$PROVIDENTIA_RELEASE_URL/release-manifest.json"
curl --fail --location --output SHA256SUMS "$PROVIDENTIA_RELEASE_URL/SHA256SUMS"
sha256sum --check SHA256SUMS
tar --extract --gzip --file "providentia-backend-v${PROVIDENTIA_RELEASE_VERSION}.tar.gz"
cd "providentia-backend-v${PROVIDENTIA_RELEASE_VERSION}"
```

Keep the archive, `images.env`, manifest and checksums together. The release's
`images.env` locks the API, web and media-worker images to the three reviewed
digests. Do not combine it with deployment files from another release.

If the GHCR packages are private, authenticate without putting the token in a
Compose file:

```bash
printf '%s' "$GHCR_TOKEN" | docker login ghcr.io \
  --username YOUR_GITHUB_LOGIN --password-stdin
```

## 3. Generate the protected configuration

Choose one of the complete preparation commands below. The helper writes a
mode-`0600` env file and generates independent database, Redis, authentication,
synchronization, metrics, notification, media, catalog, export and AI
encryption secrets. It preserves them on every later run.

### OPNsense/HAProxy on another host, bundled MySQL

```bash
sudo install -d -m 0700 -o "$USER" -g "$(id -gn)" /etc/providentia /srv/providentia
bash scripts/setup-production.sh \
  --env-file /etc/providentia/production.env \
  --image-env images.env \
  --public-url https://api.example.net \
  --mail-from no-reply@example.net \
  --trusted-proxies 10.20.30.10/32 \
  --bind-address 10.20.30.40 \
  --http-port 8080 \
  --cors-origins https://api.example.net \
  --database mysql \
  --data-directory /srv/providentia \
  --prepare-only
```

### OPNsense/HAProxy on another host, bundled MariaDB

```bash
sudo install -d -m 0700 -o "$USER" -g "$(id -gn)" /etc/providentia /srv/providentia
bash scripts/setup-production.sh \
  --env-file /etc/providentia/production.env \
  --image-env images.env \
  --public-url https://api.example.net \
  --mail-from no-reply@example.net \
  --trusted-proxies 10.20.30.10/32 \
  --bind-address 10.20.30.40 \
  --http-port 8080 \
  --cors-origins https://api.example.net \
  --database mariadb \
  --data-directory /srv/providentia \
  --prepare-only
```

### TLS proxy on this Ubuntu host, bundled MySQL

```bash
sudo install -d -m 0700 -o "$USER" -g "$(id -gn)" /etc/providentia /srv/providentia
bash scripts/setup-production.sh \
  --env-file /etc/providentia/production.env \
  --image-env images.env \
  --public-url https://api.example.net \
  --mail-from no-reply@example.net \
  --trusted-proxies 127.0.0.1/32 \
  --bind-address 127.0.0.1 \
  --http-port 8080 \
  --cors-origins https://api.example.net \
  --database mysql \
  --data-directory /srv/providentia \
  --prepare-only
```

For an external MySQL/MariaDB service, prepare without placing its credential
in shell history, then add the DSN with the protected editor in the next step:

```bash
sudo install -d -m 0700 -o "$USER" -g "$(id -gn)" /etc/providentia /srv/providentia
bash scripts/setup-production.sh \
  --env-file /etc/providentia/production.env \
  --image-env images.env \
  --public-url https://api.example.net \
  --mail-from no-reply@example.net \
  --trusted-proxies 10.20.30.10/32 \
  --bind-address 10.20.30.40 \
  --http-port 8080 \
  --cors-origins https://api.example.net \
  --database external \
  --data-directory /srv/providentia \
  --prepare-only
sudoedit /etc/providentia/production.env
```

Set `DATABASE_URL` to the private MySQL/MariaDB DSN in that file. Keep the
bundled Redis unless you also deliberately set an external `QUEUE_DSN` and
`PROVIDENTIA_MANAGED_REDIS='0'`. External services need private connectivity,
least-privilege credentials, TLS where appropriate and their own backups.

## 4. Configure SMTP and browser origins

Open the generated env file:

```bash
sudoedit /etc/providentia/production.env
```

Replace the placeholder `MAIL_DSN` with authenticated implicit-TLS SMTP:

```dotenv
MAIL_DSN='smtps://smtp-user:URL_ENCODED_PASSWORD@smtp.example.net:465'
```

Percent-encode reserved characters in the SMTP username/password. Plain
`smtp://` is rejected because the current transport does not implement verified
STARTTLS. Confirm the server may reach the SMTP host and port.

Linux, Android, iOS, Windows and macOS clients do not use CORS. For native-only
installations, leaving `CORS_ALLOWED_ORIGINS` equal to the API origin is safe.
If a browser client is hosted at `https://app.example.net`, use the exact HTTPS
origins, comma-separated:

```dotenv
CORS_ALLOWED_ORIGINS='https://app.example.net,https://admin.example.net'
```

Never use `*`, a path, a trailing API route, credentials, query or fragment.
The helper rejects malformed brackets, invalid ports and wildcards before it
contacts Docker.

Back up `/etc/providentia/production.env` securely with the data. Deleting or
regenerating it while keeping the database/media makes encrypted data and
sessions unrecoverable.

## 5. Configure OPNsense/HAProxy and the firewall

For an external OPNsense/HAProxy edge:

1. Terminate the public certificate for `api.example.net` on HAProxy.
2. Forward that hostname to `10.20.30.40:8080` over the private network.
3. Use `/health/ready` as the HTTP readiness path.
4. Preserve the request host and forwarded HTTPS/client-address headers.
5. Allow request bodies up to the intended media cap; bundled Caddy allows
   160 MB, while the application applies its lower configured media limits.

OPNsense is outside Docker, so it connects to the host's private IPv4 and
published HTTP port. It cannot use a Docker network name. Keep the official
internal `frontend`/`backend` networks; do not attach Providentia to an external
`traefik` network unless you actually deploy a Docker-based Traefik proxy and
have separately reviewed that topology.

Use narrow firewall rules:

| Direction | Allow | Do not expose |
|---|---|---|
| Internet to OPNsense | TCP 443 for the public API hostname | Direct Docker-host access |
| OPNsense `10.20.30.10` to backend `10.20.30.40` | TCP 8080 only | Any-source access to 8080 |
| Backend outbound | DNS, HTTPS, authenticated SMTP 465, required provider endpoints | Unnecessary broad management access |
| Private application network | Required SQL/Redis traffic only | Public TCP 3306, 6379, 9000 or 9090 |

If SSH is enabled, restrict it to the administrative network. Compose does not
publish SQL, Redis, PHP-FPM or metrics ports; do not add those mappings.

## 6. Deploy and verify

From the extracted release directory, run:

```bash
bash scripts/setup-production.sh \
  --env-file /etc/providentia/production.env
```

The helper validates without printing resolved secrets, pulls the locked
images, creates persistent directories, starts SQL/Redis, runs migrations once,
starts every application role and waits for readiness. Check the public route:

```bash
curl --fail-with-body https://api.example.net/health/live
curl --fail-with-body https://api.example.net/health/ready
curl --fail-with-body https://api.example.net/api/v1/system/info
```

Define the full production Compose command for later operations:

```bash
pc() {
  docker compose --env-file /etc/providentia/production.env \
    -f compose.production.yaml -f compose.production.bind.yaml "$@"
}
pc ps
pc logs --tail=200 api web notification worker outbox
```

For Docker-managed named volumes, define the same function without
`-f compose.production.bind.yaml`.

## 7. Authorize the first owner and connect the apps

Authorize one email before its first Admin sign-in:

```bash
pc exec -T api php bin/providentia system:owner owner@example.net
```

Build both apps with the same stable origin:

```text
PROVIDENTIA_API_BASE_URL=https://api.example.net
```

The household production build also sets
`PROVIDENTIA_ENVIRONMENT=production`. The API address is compiled into the apps;
changing the server env file does not change an installed client. Sign in with
the owner email in Admin, enter its delivered code, complete the profile, then
configure country publication, privacy policy and default account/home groups.

Follow the [post-release acceptance checklist](post-release-acceptance.md)
before calling the release complete. Configure optional
[AI bring-your-own-key](ai-byok.md) only after normal onboarding and stock flows
pass.

## 8. Upgrade without bringing back a hotfix

Download and verify the next release into a new directory, take a consistent
backup, then run its helper against the same protected env and data:

```bash
bash scripts/setup-production.sh \
  --env-file /etc/providentia/production.env \
  --image-env images.env
```

Do not mount replacement PHP source into `/app`, make the application filesystem
writable, edit the database manually, or build an unreviewed local image. Once a
release contains a correction, omit every temporary hotfix override and deploy
only `compose.production.yaml` plus the optional official bind-persistence file.
The application containers remain read-only; only `/app/var` is persistent.

Back up and restore SQL, `/srv/providentia/app-var`, the protected env file and
the release metadata as one state set. Follow the complete
[backup, restore, upgrade and rollback runbook](production.md#backups-and-restore).
