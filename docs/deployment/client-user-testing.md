# Client login-link, homes, invitations, and administrator testing

This is the canonical acceptance handoff between the Providentia backend and
the Flutter client. It tests the production-shaped email-only **login-link**
workflow on web, Android, iOS, Windows, macOS, and Linux. It also covers session
restoration, multiple homes, invitations, and platform-administrator safety.

Password registration and login remain available only as explicitly enabled
development or migration-compatibility surfaces. A password login is not
evidence that the product onboarding flow works. Do not enable password login
or exposed development tokens in staging or production to bypass a failed
login-link test.

The backend does not provide an authenticated administration GUI. Its browser
surface is limited to the public site and the login-link review/result pages.
All signed-in account, home, invitation, device-session, and platform-admin
actions below must be reachable through the Flutter client, including its web
build, subject to the current user's permissions.

## 1. Acceptance invariants

Every test run must preserve these boundaries:

- the person enters their email address in the originating client, not in the
  browser;
- the browser that opens the email may be on another device;
- merely opening the emailed URL never approves a request;
- the browser review page requires a deliberate **Approve** or **Deny** POST;
- the browser never receives the originating client's session;
- the originating client owns the poll token and PKCE verifier, polls for the
  result, and exchanges an approved request;
- an app/universal link may help the person return to the client, but is never
  required for success and is not the authoritative handoff;
- access, refresh, session, poll, and PKCE credentials never appear in an email
  URL, browser address bar, referrer, page, analytics event, or application log;
- the response to starting a request does not reveal whether the email is
  already registered;
- an unknown address is not provisioned just because a request was started;
- approving the first request for an unknown address creates exactly one
  editable `My home` with that person as its `owner`; the originating client's
  successful exchange then selects it as the active home;
- signing an existing person in never creates another default home; and
- a global platform role never grants access to a home.

Stop the test and record a defect if any invariant fails, even if the client
eventually displays its home screen.

## 2. Start the complete backend and email delivery

### Published images

This path needs Docker Compose v2, `curl`, `jq`, `openssl`, and access to the
three GHCR packages. It does not build PHP or FFmpeg locally.

```bash
git clone https://github.com/providentia-systems/backend.git
cd backend
bash scripts/setup-prebuilt.sh
```

If GHCR requires authentication, follow
[Run the published backend images locally](prebuilt-images.md).

### Source checkout with the verified pantry baseline

This path builds the application image and imports the checksum-pinned handover
data:

```bash
git clone https://github.com/providentia-systems/backend.git
cd backend
bash scripts/setup-development.sh \
  --handover /absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip
```

It needs Docker Compose v2, `unzip`, `sha256sum`, `curl`, `jq`, and `openssl`.
See [Source-build local development](local-development.md) for database
profiles and the destructive reset boundary.

Both setup paths start the HTTP API, database, queue, migrations, Mailpit, and
the notification-delivery worker. The default local endpoints are:

- API: `http://127.0.0.1:8080`
- readiness: `http://127.0.0.1:8080/health/ready`
- Mailpit: `http://127.0.0.1:8025`

The acceptance policy is controlled by these backend settings. Keep the
defaults unless the specific test is verifying a shorter deployment policy:

| Setting | Product default |
|---|---:|
| `AUTH_ACCESS_TTL_SECONDS` | `900` (15 minutes) |
| `AUTH_LOGIN_LINK_TTL_SECONDS` | `900` (15 minutes to approve) |
| `AUTH_LOGIN_LINK_EXCHANGE_TTL_SECONDS` | `120` (2 minutes to exchange after approval) |
| `AUTH_LOGIN_LINK_POLL_INTERVAL_SECONDS` | `3` |
| `AUTH_LOGIN_LINK_RETENTION_DAYS` | `30` (terminal request metadata; no usable capabilities) |
| `AUTH_RATE_LIMIT_RETENTION_DAYS` | `2` (inactive hashed throttling buckets) |
| `AUTH_WEB_IDLE_TTL_SECONDS` | `2592000` (30 days) |
| `AUTH_NATIVE_IDLE_TTL_SECONDS` | `5184000` (60 days) |
| `ONBOARDING_HOME_NAME` | `My home` |
| `ONBOARDING_HOME_LOCALE` | `en-NA` |
| `ONBOARDING_HOME_CURRENCY` | `NAD` |
| `ONBOARDING_HOME_TIMEZONE` | `Africa/Windhoek` |
| `PLATFORM_BOOTSTRAP_ADMIN_EMAILS` | Empty, or a comma-separated exact-email list |

`AUTH_REFRESH_TTL_SECONDS` is a legacy compatibility setting; it does not
replace the transport-specific idle limits above.

Confirm all three before launching a client:

```bash
curl --fail-with-body http://127.0.0.1:8080/health/live
curl --fail-with-body http://127.0.0.1:8080/health/ready
curl --fail-with-body http://127.0.0.1:8080/api/v1/system/info
```

Open Mailpit and leave it visible. Starting a login link writes an encrypted
outbox record; the notification worker leases it, sends it through SMTP, and
records delivery or retry state. An API `202` without an email is not a
successful end-to-end result. Check the notification worker and Mailpit before
diagnosing the client.

For source MySQL logs:

```bash
docker compose --env-file .env.development.local logs -f notification-mysql
```

For the prebuilt stack:

```bash
docker compose --env-file .env.prebuilt.local -f compose.prebuilt.yaml \
  logs -f notification
```

### Real SMTP acceptance

Run the same workflow at least once against the deployment's real transactional
SMTP service. Configure the production profile from `.env.production.example`
using secrets supplied by the deployment secret manager. At minimum, verify
the `smtps://` mail transport, sender address, HTTPS public base URL, and running
notification worker. Never paste SMTP credentials into test notes or client
configuration.

The delivered message must:

- use the same neutral wording for registered and unregistered addresses;
- call the feature a **login link**;
- point at the deployment's HTTPS public origin;
- contain no access, refresh, session, poll, or PKCE credential; and
- expire and become unusable after its one permitted approval decision.

Mailpit is appropriate only for isolated local development. A local Mailpit
delivery does not prove DNS, TLS, sender reputation, or real mailbox delivery.

## 3. Client and browser protocol reference

The generated client contract is authoritative. The behavioral responsibilities
below help testers distinguish a UI problem from a contract or security defect.

### Originating-client API

| Operation | Request proof | Expected result |
|---|---|---|
| `POST /api/v1/auth/login-links` | Client-generated `requestId`, email, SHA-256 `pollChallenge`, PKCE S256 `codeChallenge`, `state`, `installationId`, device name, platform, `transport`, and optional shorter `requestedSessionIdleSeconds` | Generic `202` with `requestId`, expiry, and polling interval; never returns the poll token |
| `POST /api/v1/auth/login-links/{requestId}/status` | Private `pollToken` in the JSON body | Only `pending`, `approved`, `denied`, `expired`, `cancelled`, or `exchanged`, with no session secret |
| `POST /api/v1/auth/login-links/{requestId}/exchange` | Private `pollToken`, PKCE `codeVerifier`, and original `state` in the JSON body | Session for the originating installation only |
| `POST /api/v1/auth/login-links/{requestId}/cancel` | Private `pollToken` in the JSON body | Cancels a pending request without revealing account existence |
| `GET /api/v1/me` | Current authenticated session | Verified profile, current session, `activeHomeId`, and every home membership/role required to restore the client |

Before the start request, the client generates and retains:

- a fresh request ID;
- a high-entropy poll token and only sends its SHA-256 challenge;
- an RFC 7636 PKCE verifier and only sends its S256 challenge; and
- a fresh state value bound to that pending request.

The client stores those private values only in its protected, short-lived
pending-login state. It never puts them in query parameters, logs, analytics,
the clipboard, or unprotected browser storage. A web client may use encrypted,
origin-bound storage solely so the pending request survives an ordinary page
reload; it must erase that state on success, cancellation, or expiry. Multiple
installations and concurrent requests must have independent values; starting
one request must not invalidate another.

### Browser approval

The email initially opens a fragment URL. The approval credential after `#`
is browser-local and is never included in an HTTP request target or access log:

```text
/login-links/{requestId}#approval=<single-use-browser-approval-credential>
```

The scanner-safe GET renders a narrowly CSP-nonced capture page but does not
approve or mutate the request. In an interactive browser, that page removes the
fragment from browser history and POSTs the credential in the request body:

```text
POST /login-links/{requestId}/capture
```

Capture only moves the credential into a secure, HttpOnly, request-scoped
cookie and redirects to a clean URL:

```text
GET /login-links/{requestId}/review
```

The clean review page may show a masked email, requesting device, request age,
and expiry. It must not show a session or private client proof. Approval and
denial are explicit form submissions:

```text
POST /login-links/{requestId}/approve
POST /login-links/{requestId}/deny
```

The completion page tells the person whether the request was approved, denied,
expired, or already used and that the browser may be closed. It may offer an
allowlisted app link as a convenience. Closing the browser and returning to the
originating client manually must work just as well.

## 4. New-person cross-device acceptance

Use a mailbox address that has never appeared in this environment. Use a
unique suffix for every clean run.

1. Launch the client on device A and choose **Sign in with email**.
2. Enter the new email address. Do not enter a display name or password in a
   browser.
3. Confirm the client shows a pending-login screen, persists its protected
   pending state, and polls no faster than `pollIntervalSeconds`.
4. Confirm Mailpit or the real mailbox receives one login-link message.
5. Before approving, confirm polling remains `pending`. Where database-level
   acceptance access is available, also confirm no account or `My home` has
   been created yet.
6. Open the email on device B, or in a browser profile that has never used the
   client. Confirm the launch page captures the browser fragment by POST and
   redirects to the clean review URL; refreshing the clean GET does not approve
   it.
7. Choose **Approve** once. Confirm the browser says the request is approved,
   the browser may be closed, and no authenticated application session was
   created in that browser.
8. Return to device A. It must observe `approved` through polling and exchange
   its own poll token, state, and PKCE verifier.
9. Confirm the client calls `GET /api/v1/me` and displays the new account with
   exactly one home named `My home`, role `owner`, and that home selected.
10. Rename `My home` through `PATCH /api/v1/homes/{homeId}` with its current
    expected revision, close the client, and launch it again. The client must
    restore the same session, account, renamed home, and active-home choice
    without asking for email again.

Repeat once with the email opened on the same device and once with it opened on
a genuinely separate device. Neither run may depend on a deep link.

## 5. Existing-person and concurrent-device acceptance

Sign in with the same verified email from a second client installation:

1. record the person's existing home IDs and roles;
2. start and approve a new login-link request;
3. confirm the new installation receives its own session and device record;
4. confirm `GET /api/v1/me` returns the same account and memberships; and
5. confirm no new `My home` was created.

Then start two requests for the same email from two independent installations.
Approve both messages in either order. Each installation must exchange only its
own request and receive its own device session. A poll token, state, or verifier
from one request must never complete the other.

## 6. Session restoration, duration, and revocation

The backend, not the client, enforces the maximum session policy:

| Credential/session | Backend policy | Client acceptance |
|---|---|---|
| Access credential | Approximately 15 minutes | Refresh before/after expiry without exposing the refresh credential |
| Web | Sliding 30-day inactivity | Secure persistent cookies survive normal browser restart; active use moves `idleExpiresAt`, never beyond policy |
| Android/iOS native | Sliding 60-day inactivity | Refresh credential stays in OS secure storage and survives normal app restart |
| Windows/macOS/Linux native | Sliding 60-day inactivity | Refresh credential stays in platform-protected storage and survives normal app restart |

A client may request a shorter idle period with
`requestedSessionIdleSeconds`; the server caps or rejects a longer request.
Successful use/refresh extends the sliding inactivity deadline. Logout,
explicit revocation, refresh replay, account/security action, or reaching the
inactivity deadline ends the session regardless of the nominal maximum.

Web cookies are host-only and `SameSite=Strict`. A deployed Flutter web client
and API must therefore use HTTPS origins on the same site, normally sibling
subdomains under one registrable domain; a CORS allowlist alone does not make
unrelated sites cookie-compatible. `localhost:8081` and `localhost:8080` are
same-site for local testing.

After signing in on at least two installations:

1. call `GET /api/v1/auth/sessions` through the client and verify each row has
   the expected device name, platform, `transport`, `current`, `lastSeenAt`,
   `accessExpiresAt`, `refreshExpiresAt`, and `idleExpiresAt` values but no
   credential material;
2. revoke the non-current session using
   `DELETE /api/v1/auth/sessions/{sessionId}`;
3. confirm that installation can no longer refresh or call an authenticated
   endpoint;
4. confirm the current installation remains usable; and
5. log out the current installation and confirm its local secrets are removed
   and its server session is revoked.

For web, close every browser window and reopen the application before testing
restore. For native, terminate the process rather than merely navigating away.
Do not simulate persistence by copying a refresh token between installations.

## 7. Homes, roles, invitations, and switching

Household roles are scoped independently to each home:

| Role | Expected boundary |
|---|---|
| `owner` | Full home governance, role policy, and ownership-transfer authority |
| `manager` | Broad household operation and invitation authority below the owner ceiling |
| `member` | Ordinary inventory, purchasing, and shopping work |
| `viewer` | Read-only household access |

There is no global `homeowner` or `stockkeeper` account type. A person is an
`owner`, `manager`, `member`, or `viewer` of a specific home and can have a
different role in every other home.

### Invite a new person

1. Sign in as the owner of home A and invite a never-used email as `member`.
2. Confirm an invitation email is delivered, but do not copy an invitation
   token into the client.
3. On the invitee's client, complete the ordinary login-link workflow with the
   exact invited email. As a new person, the invitee first owns their own
   `My home`.
4. Confirm `GET /api/v1/me/home-invitations` lists only the invite addressed to
   that verified email, including its ID, home, proposed role, expiry, and
   revision.
5. Accept it deliberately with
   `POST /api/v1/me/home-invitations/{invitationId}/accept`, sending the current
   expected revision.
6. Confirm the invite disappears from pending invitations and the invitee now
   owns `My home` and is a `member` of home A.

An invitation email may guide the recipient back to the client, but possession
of its URL is not a substitute for signing in as the exact verified recipient.

### Prove multiple homes and role isolation

Build this minimum matrix for one person:

- owner of their `My home`;
- manager of home A; and
- viewer of home B.

Confirm `GET /api/v1/me` and `GET /api/v1/homes` return the same three home IDs
and roles. Switch using `POST /api/v1/homes/{homeId}/switch`, then restore the
client and confirm the chosen `activeHomeId` persists. Verify manager actions
work only in home A, viewer mutations fail in home B, and data from one home is
never displayed or synchronized under another home's active context.

Also verify:

- a revoked invitation cannot be accepted;
- an expired invitation reports expiry rather than creating membership;
- a different verified email cannot list or accept the invitation;
- accepting twice is safe and does not duplicate or silently change a current
  membership;
- leaving or losing access to the active home selects a valid fallback or no
  active home, never a stale unauthorized ID; and
- accepting an invitation never changes ownership of the invitee's `My home`.

## 8. Platform-administrator bootstrap and delegation

Platform administration is global and is separate from home membership. The
bootstrap configuration is a comma-separated list of exact email addresses:

```text
PLATFORM_BOOTSTRAP_ADMIN_EMAILS=admin1@example.test,admin2@example.test
```

The deployment validates and normalizes the list at startup. An address in the
list does not become an active administrator until that person successfully
verifies it through the ordinary login-link workflow. The grant is idempotent
and audited. It grants no membership in another person's home; a new account
may still receive its own normal `My home` through onboarding.

Administrator APIs require an authenticated active platform administrator.
Web mutations also require the session's CSRF protection; native clients use
their bearer session. The v1.11 administrator grant/revoke contract does not
claim a separate step-up flow.

Test bootstrap and delegated administration as follows:

1. configure one unused bootstrap address and restart the backend;
2. complete login-link onboarding for that exact address;
3. confirm `GET /api/v1/platform/administrators` lists the active grant with
   `id`, email, status, revision, and audit timestamps;
4. use `POST /api/v1/platform/administrators` to add an unused second email;
5. confirm that entry is pending, the exact address receives a non-token email
   telling them to open Providentia, and repeating the same grant is idempotent
   without sending another email;
6. complete login-link onboarding for the second email and confirm its pending
   grant becomes active;
7. use `POST /api/v1/platform/administrators/{administratorId}/revoke` with its
   `expectedRevision` to revoke one administrator;
8. confirm a stale revision fails without changing the grant;
9. attempt to revoke the final active administrator and confirm the backend
   rejects it; and
10. confirm the revoked administrator immediately loses platform-administrator
    API access but retains only their legitimate home memberships.

Every grant and revoke must record the acting administrator, target email/grant,
revision, action, and time. Direct database edits and the legacy catalog-role
CLI are not product acceptance paths.

To prove no-home-access, create a home owned by an ordinary person and do not
invite the platform administrator. Platform-administrator listing and grant
operations may succeed, while every attempt to read or mutate that unrelated
home must fail. A platform role is never a tenancy bypass.

## 9. Denial, expiry, replay, and recovery cases

Run at least the following negative cases. Responses must be generic where a
more specific response would reveal account existence or secret validity.

| Case | Expected result |
|---|---|
| Email link fetched by a preview/scanner | A basic scanner sees only the launch GET; a JavaScript-capable scanner may reach clean review; neither approves because no deliberate approve POST occurred |
| Browser chooses **Deny** | Origin observes `denied`; exchange cannot create an account or session |
| Origin chooses cancel | Request becomes `cancelled`; later browser approval and exchange fail safely |
| Request expires before approval | Review and polling report expiry; no account, home, or session is created |
| Approval is replayed | No second decision or session is created |
| Wrong poll token | Status, cancel, and exchange fail without revealing the real state |
| Wrong state or PKCE verifier | Exchange fails; attacker receives no session; correct proof remains subject to single-use policy |
| Exchange repeated after success | No additional session, account, or home is created |
| Exchange response is lost or ambiguous | Client discards the pending proof and starts a fresh login-link request; backend never reconstructs or replays a native credential grant |
| Client restarts while request is pending | Protected pending state resumes polling until terminal state or expiry |
| Polling exceeds advised cadence | Backend throttles without disclosing account state; client backs off |
| Notification delivery temporarily fails | Outbox retries; starting the request still does not disclose account existence |
| Refresh credential is replayed | Compromised session is revoked and both installations are required to sign in again as policy dictates |
| Revoked device tries to restore | Refresh and authenticated requests fail; other devices remain valid |
| Expired web/native idle deadline | Restore fails and the client returns to email entry |
| Logout after access expiry | Native sends its current refresh token; web sends the refresh cookie plus matching CSRF proof; the proven session is revoked and local cookies are cleared |

Inspect API, edge, worker, browser, and client logs after the run. Email
addresses may be redacted according to operational policy; raw approval
credentials, poll tokens, PKCE verifiers, access tokens, refresh tokens, and
cookies must be absent.

Run `php bin/providentia login-link:purge` from scheduled maintenance at least
daily. It expires stale pending requests and removes terminal records older
than `AUTH_LOGIN_LINK_RETENTION_DAYS`, and removes inactive authentication
throttling buckets older than `AUTH_RATE_LIMIT_RETENTION_DAYS`; use
`--limit=1000` for bounded catch-up batches.

## 10. Flutter launch notes

Clone the client separately and use its current generated contract:

```bash
git clone https://github.com/providentia-systems/client.git
cd client
flutter pub get --enforce-lockfile
dart run build_runner build --delete-conflicting-outputs
```

For Chrome, use the fixed development origin and the same `localhost` site as
the API:

```bash
flutter run -d chrome \
  --web-hostname=localhost \
  --web-port=8081 \
  --web-header=Cross-Origin-Opener-Policy=same-origin \
  --web-header=Cross-Origin-Embedder-Policy=require-corp \
  --dart-define=PROVIDENTIA_ENVIRONMENT=development \
  --dart-define=PROVIDENTIA_API_BASE_URL=http://localhost:8080
```

Do not mix a `localhost` browser origin with a `127.0.0.1` API URL. The exact
web origin is allowlisted, and credentialed CORS must never use a wildcard.

For Linux desktop on the backend host, use
`PROVIDENTIA_API_BASE_URL=http://127.0.0.1:8080`. For Android debug, forward the
emulator or USB device loopback port first:

```bash
adb reverse tcp:8080 tcp:8080
```

An untethered/LAN device cannot reach a workstation through its own loopback.
Use a trusted HTTPS development deployment for genuine cross-device email and
browser testing. Release builds remain HTTPS-only.

Record a separate pass for Chrome/web, Android, iOS, Windows, macOS, and Linux.
For every platform, capture the client version, backend contract version,
transport (`web` or `native`), originating device, email-opening device,
expected/actual session expiry fields, home/role matrix, and result. Redact all
credentials from screenshots and reports.

## 11. Development-password compatibility boundary

The setup scripts may still print a development account/password or write them
to the mode-`0600` `.providentia-development.json` handoff for older smoke
scripts. `scripts/provision-development-user.sh` may also exercise password and
exposed-token endpoints on loopback. Those are isolated tooling aids only.

They must not be used to sign off any item in Sections 4–9. Production keeps
`AUTH_PASSWORD_LOGIN_ENABLED=0` and `EXPOSE_DEVELOPMENT_TOKENS=0`. If a product
test cannot proceed without changing either value, record the login-link flow
as failed and fix that flow.

The OpenAPI document in `contracts/openapi/providentia-v1.json` is the backend
contract. Publish its new version and regenerate/pin the Flutter client before
cross-repository acceptance; handwritten endpoint substitutions are not valid
evidence.
