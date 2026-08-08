# Client login, users, homes, and administrator testing

This is the canonical local handoff between the Providentia backend and the
Flutter client. It covers password accounts only in the isolated development
profiles. Do not use these credentials, exposed email tokens, or plain HTTP in
staging or production.

## 1. Start a complete backend

### Fastest path: published images

This path needs Docker Compose v2, `curl`, `jq`, `openssl`, and access to the
three GHCR packages. It does not build PHP or FFmpeg locally.

```bash
git clone https://github.com/providentia-systems/backend.git
cd backend
bash scripts/setup-prebuilt.sh
```

If GHCR requires authentication, follow the login instructions in
[Run the published backend images locally](prebuilt-images.md). The script
starts MySQL, Redis, Mailpit, migrations, the HTTP edge, and every worker. It
then creates or reuses a verified bootstrap account and its first home.

### Source path with the verified pantry baseline

This path builds the application image from the checkout and imports the
checksum-pinned handover data:

```bash
git clone https://github.com/providentia-systems/backend.git
cd backend
bash scripts/setup-development.sh \
  --handover /absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip
```

It needs Docker Compose v2, `unzip`, `sha256sum`, `curl`, `jq`, and `openssl`.
See [Source-build local development](local-development.md) for the database
profiles and reset boundary.

Both setup scripts finish by printing the API URL, bootstrap email/password,
and home ID. The default endpoints are:

- API: `http://127.0.0.1:8080`
- readiness: `http://127.0.0.1:8080/health/ready`
- Mailpit: `http://127.0.0.1:8025`

Password registration/login and development email tokens are enabled
explicitly by these development profiles. The production Compose profile and
`.env.production.example` default `AUTH_PASSWORD_LOGIN_ENABLED` to `0` and
never expose development tokens.

## 2. Understand the protected client handoff

Setup writes `.providentia-development.json` with mode `0600`. Its bootstrap
fields are:

| Field | Meaning |
|---|---|
| `apiBaseUrl` | Loopback API origin |
| `homeId` | Home created or reused by setup |
| `userId` | Verified bootstrap-account ID |
| `email`, `password` | Credentials to enter on the client login page |
| `deviceId` | Stable development device identifier used by setup |
| `accessToken`, `refreshToken` | Sensitive setup-session credentials; they expire/rotate and are not a client bootstrap contract |

The ordinary-user script described below adds a `testUsers` array containing
the reusable test email, password, display name, device ID, user ID, and home
role. Never commit, share, or copy this file to another environment. The
repository ignores it.

Always perform a new client login with the printed email and password. Do not
paste the saved bearer tokens into Flutter launch arguments. Native login
returns bearer credentials to the native secure-storage adapter. Web login
keeps access and refresh credentials in secure `HttpOnly` cookies and returns
only session metadata plus the CSRF token in JSON.

## 3. Bootstrap owner versus platform administrator

The user who creates a home becomes that home's `owner`. This is a household
role, not a platform-administrator grant. Setup deliberately does not make any
account a platform administrator.

Household roles are scoped to one home:

| Role | Testing purpose |
|---|---|
| `owner` | Full household governance; exactly the home creator until an explicit ownership transfer |
| `manager` | Broad household operation and member invitation below the owner ceiling |
| `member` | Ordinary household use |
| `viewer` | Read-only authorization checks |

Platform catalog roles are global and separate:

- `platform_administrator`
- `catalog_curator`
- `catalog_reviewer`

Grant the initial platform administrator only after the account is active and
email-verified. For the source MySQL stack, run:

```bash
docker compose --env-file .env.development.local exec -T api-mysql \
  php bin/providentia catalog:role \
  --email=developer@providentia.local \
  --role=platform_administrator
```

For the prebuilt stack, run:

```bash
docker compose --env-file .env.prebuilt.local -f compose.prebuilt.yaml \
  exec -T api php bin/providentia catalog:role \
  --email=developer@providentia.local \
  --role=platform_administrator
```

Replace the email when setup used `--dev-email`. The same command accepts
`catalog_curator` or `catalog_reviewer`; add `--revoke` to revoke that exact
role. The command never changes a household membership.

Those are the only platform roles with a supported grant/revoke command today.
Billing authorization recognizes `billing_operator`, but no operational CLI
grant flow exists for it yet; use `platform_administrator` for current local
billing-operator acceptance. The time-limited support-operator flow remains a
planned boundary. Do not bypass either gap with ad hoc database inserts.

## 4. Create an ordinary test user

After either setup path, provision a second verified account and make it an
ordinary member of the bootstrap home:

```bash
bash scripts/provision-development-user.sh \
  --email=test-member@providentia.local \
  --role=member
```

The script:

1. reads bootstrap credentials and the home ID from
   `.providentia-development.json`;
2. refuses any API origin other than `127.0.0.1`, `localhost`, or `[::1]`;
3. logs in again as the bootstrap owner instead of trusting its saved bearer
   token;
4. creates and verifies the password account, or safely reuses it;
5. logs in as that user with a separate device ID;
6. creates and accepts an invitation, reuses an existing membership, or
   changes that membership to the requested role; and
7. prints and saves the credentials and IDs in the protected handoff.

Supported roles are `manager`, `member`, `viewer`, and `none`:

```bash
bash scripts/provision-development-user.sh --email=manager@providentia.local --role=manager
bash scripts/provision-development-user.sh --email=viewer@providentia.local --role=viewer
bash scripts/provision-development-user.sh --email=isolated@providentia.local --role=none
```

`none` creates or reuses the account without creating or changing a home
membership. It does not remove a membership made by an earlier run. Supply
`--password` when reusing an account whose password was not saved by this
script. Use `--display-name` to change the display name used only when a new
account is created.

This automation depends on development verification and invitation tokens. A
production API will not return those tokens, and the script intentionally
refuses a non-loopback API. In production, registration/verification and
invitations must use delivered email links and the normal user-facing flows.

## 5. Log in through the Flutter client

Clone the client separately and follow its current local-development guide:

```bash
git clone https://github.com/providentia-systems/client.git
cd client
flutter pub get --enforce-lockfile
dart run build_runner build --delete-conflicting-outputs
```

For Chrome, keep the browser and API on the same `localhost` site so strict
cookies work, and use the fixed origin already allowed by the backend
development profiles:

```bash
flutter run -d chrome \
  --web-hostname=localhost \
  --web-port=8081 \
  --web-header=Cross-Origin-Opener-Policy=same-origin \
  --web-header=Cross-Origin-Embedder-Policy=require-corp \
  --dart-define=PROVIDENTIA_ENVIRONMENT=development \
  --dart-define=PROVIDENTIA_API_BASE_URL=http://localhost:8080
```

Do not mix a `localhost` browser origin with a `127.0.0.1` API URL. The backend
uses credentialed CORS plus `Secure`, `SameSite=Strict` cookies, so the exact
web origin is allow-listed and the browser/API hostname pairing matters.

For Linux desktop on the backend host, use
`PROVIDENTIA_API_BASE_URL=http://127.0.0.1:8080`. For an Android debug build,
forward the emulator's loopback port first and use the same URL:

```bash
adb reverse tcp:8080 tcp:8080
```

The client's Android debug network policy permits cleartext only for loopback;
release builds remain HTTPS-only. The Android path still requires an emulator
or device smoke test on the development workstation. A USB-debuggable physical
device can use the same `adb reverse` tunnel. An untethered or LAN device
cannot use the client's loopback URL to reach the workstation; use a trusted
HTTPS development endpoint instead of exposing the loopback profile broadly.

At the login page, enter either the bootstrap credentials printed by setup or
the ordinary-user credentials printed by the provisioning script. The current
composed client can log in, restore a session, list/create/select a home, and
exercise its currently wired synchronization path.

## 6. Current manual and test boundaries

For the present client testing phase:

- registration, email verification, password reset, invitations, invitation
  acceptance, membership-role changes, and platform-role grants are not
  available as complete client UI flows; use the backend setup/provisioning
  commands above;
- the first home a user creates is owned by that user; joining the bootstrap
  home as `manager`, `member`, or `viewer` requires invitation acceptance;
- passwordless backend endpoints exist, but the current Flutter transport and
  deep-link flow are not complete, so the email/password development path is
  the supported client test path;
- several household screens still use local client persistence. A successful
  UI interaction there is not by itself proof that the corresponding backend
  inventory, purchasing, shopping, AI, or reporting route was exercised; and
- catalog-administrator authorization can be tested at the API, but the
  platform role does not grant membership in any home or imply that the
  current client exposes a catalog-admin screen.

Production defaults to passwordless sign-in, HTTPS, real transactional email,
non-exposed tokens, and a restrictive deployment-specific CORS allow-list.
Do not turn on `AUTH_PASSWORD_LOGIN_ENABLED` or
`EXPOSE_DEVELOPMENT_TOKENS` merely to work around an unfinished production
client flow.

## 7. Quick verification

Confirm the backend before diagnosing the client:

```bash
curl --fail-with-body http://127.0.0.1:8080/health/live
curl --fail-with-body http://127.0.0.1:8080/health/ready
curl --fail-with-body http://127.0.0.1:8080/api/v1/system/info
```

If Chrome reports CORS errors, confirm its origin is exactly
`http://localhost:8081` and its API is exactly `http://localhost:8080`.
Credentialed CORS must not use a wildcard. If login returns
`Password authentication disabled`, the backend was not started with one of
the development profiles described above.
