# Client, home and administrator testing

This runbook tests the pre-release API 2.0.0 implementation. There are no live
customers requiring compatibility with the retired authentication model. Both
clients request a numeric email code, the backend emails it, and the person
enters the eight digits in the requesting client. The backend has no browser
login page and no account password surface.

For the mandatory published-artifact owner → preassigned invitee → manager
journey and the location/private-cache/Admin proof, use the exact
[post-release acceptance checklist](post-release-acceptance.md). This document
provides the local/development preparation behind those roles.

## Start the matching backend

From the backend checkout:

```bash
bash scripts/setup-prebuilt.sh \
  --handover /absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip \
  --dev-email developer@providentia.local
```

The prebuilt script selects the immutable candidate image for an `agent/*`
checkout unless an explicit version is supplied. It checks image revision labels,
starts the database and workers, applies migrations, verifies catalog import
replay and proves HTTP health. Supply the authorized handover for the approved
starter catalog. Without it the script reports that the catalog was not seeded.

For a source build use `scripts/setup-development.sh --handover PATH` instead.
It also imports the authorized inventory baseline into the development home;
ordinary new homes do not inherit those quantities or history.

Default local endpoints:

| Purpose | URL |
| --- | --- |
| API | `http://127.0.0.1:8080` |
| Readiness | `http://127.0.0.1:8080/health/ready` |
| Mailpit | `http://127.0.0.1:8025` |

`.providentia-development.json` contains the developer email, API/Mailpit origins,
installation, home and session credentials. It has mode `0600`; do not commit it
or pass its tokens to Flutter. The setup tooling reads the actual newly delivered
Mailpit code and accepts the current Namibia privacy policy for the local test
account. Ordinary users read and accept the policy themselves in the client.

## Sign in using the household client

From the client checkout:

```bash
bash tools/agent-setup.sh
source .agent-env
flutter run -d linux \
  --dart-define=PROVIDENTIA_API_BASE_URL=http://127.0.0.1:8080 \
  --dart-define=PROVIDENTIA_ENVIRONMENT=development
```

Enter an email, request the code, read the newest matching email in Mailpit and
enter the code. Repeating setup or requesting another code within sixty seconds
may return `429`; respect the cooldown. Codes expire after ten minutes and allow
at most five incorrect attempts. A code issued to another client/installation
cannot be used with the current request's binding proof. Successful verification
consumes the code once.

A new person enters their name, selects Namibia and accepts the current policy.
Namibia is initially the only published country. An uninvited account receives
the starter group allowing one owned home. An invited account receives the
invited group, initially allowing no owned home. Home creation is an explicit
choice where the account allowance permits it. Pending invitations can be
accepted or declined in the invitation area.

For Chrome use a consistent hostname for the API and web origin, for example
`http://localhost:8080` and `http://localhost:8081`. Add custom web origins to
`CORS_ALLOWED_ORIGINS`; credentialed CORS cannot use a wildcard. For a USB Android
device run `adb reverse tcp:8080 tcp:8080` and use the loopback API URL. Other
remote devices need a trusted HTTPS endpoint. Clients have a fixed API origin
compiled at build time.

## Authorize the system owner and run Admin

For the prebuilt stack, run from the backend checkout:

```bash
docker compose --env-file .env.prebuilt.local -f compose.prebuilt.yaml \
  exec -T api php bin/providentia system:owner owner@example.test
```

For the source stack use its actual service name:

```bash
docker compose --env-file .env.development.local \
  exec -T api-mysql php bin/providentia system:owner owner@example.test
```

The email is a positional argument. The command authorizes the first system
owner but does not create a session or bypass mailbox verification. Repeating it
for the same address is idempotent; it cannot replace an existing system owner.

From the Admin checkout:

```bash
bash tools/agent-setup.sh
source .agent-env
flutter run -d linux \
  --dart-define=PROVIDENTIA_API_BASE_URL=http://127.0.0.1:8080
```

Sign in using the authorized owner address and the email code, then complete the
profile. The protected system-owner group has every administrator permission.
Other applicants sign in normally and await approval. Create an administrator
group, select the applicant's group and approve them. Approval, group management,
people inspection, home inspection, catalog work and country configuration are
separate permissions. Privileged `401`/`403` responses clear Admin's displayed
and cached privileged state immediately.

## Configure groups and test invitations

Each account, home and administrator has one group in its own scope. Assigning
a home to a group controls its features, quotas, role defaults and the permissions
owners may delegate. Account groups separately control owned-home allowances.
Owners cannot grant a permission outside their home's feature/delegation ceiling.

The default home group has `members.invite` disabled. Before inviting a tester,
use Admin to enable that feature in a suitable home group and assign the home.
Set total and role quotas explicitly. Additional owners require an owner quota
above one and an account allowance for the recipient to own that home.

The helper provisions local accounts through email delivery and the same
invitation API, without changing administrator policy:

```bash
bash scripts/provision-development-user.sh \
  --email member@example.test --display-name 'Test Member' --role member
bash scripts/provision-development-user.sh \
  --email manager@example.test --display-name 'Test Manager' --role manager
bash scripts/provision-development-user.sh \
  --email standalone@example.test --display-name 'Standalone Tester' --role none
```

For a newly invited account, the helper creates the pending invitation before
completing country onboarding so the invited-account default is selected.
Acceptance uses the authenticated recipient, invitation ID and revision. It does
not require an invitation token from a development API response. `--role none`
creates no invitation and removes no existing membership.

To test an administrator-preassigned account group, let the new user verify an
email code and stop on **Set up your account**. Assign the account-level group
in Admin, record its revision, then submit onboarding in the Client. The existing
assignment and revision must remain unchanged; the separate home invitation may
still grant the manager/member role when accepted.

## Verify the agreed behavior

| Journey | Expected result |
| --- | --- |
| Restart a signed-in client | The trusted installation restores its session; sign-out or revocation invalidates it. |
| Wrong, expired or replayed code | No new session is issued. Starting a new request requires its own binding proof. |
| Uninvited signup | One home may be created under initial country/account defaults. |
| Invited signup | Invitations appear; no owned home is created automatically. |
| Switch homes | Each home's features, permissions and records are evaluated independently. |
| Individual permission override | Inherit uses role defaults; allow/deny remains bounded by home policy. |
| Disable invitations | Existing memberships continue; further invitations are refused. |
| Reduce category/member quota | Existing records remain; additions above the new allowance are refused. |
| Disable an operational feature | Its API operation is denied and its client controls become unavailable. |
| Add and verify email alias | Either verified address signs into the same account; no merge with another account occurs. |
| Remove primary/last address | Choose another verified primary first; the final verified address cannot be removed. |
| Edit profile/home | Names, descriptions, uploaded cropped avatars/images and country/location settings persist. |
| Optional profile location | Region/city may be omitted or cleared; selected names survive save, restart and read-back. |
| Preassigned account group | Onboarding preserves the Admin-selected account group and assignment revision. |
| Operator inspection | Authorized administrator groups can inspect home records independently of public sharing. |
| Unrelated homeowner | Another home's records remain inaccessible. |
| Public catalog contribution | Only approved shared metadata is reusable by other homes; quantities stay home data. |
| Lower administrator access | Server authorization and the client workspace both reflect the new permissions. |

The operator policy explains staff access to application data and use of that
data to improve the system. It does not promise that the system owner cannot
inspect the database. Public sharing and internal operator access remain separate.
Stored authentication proofs and AI provider credentials are never displayed.

## Countries, policies and reference updates

In Admin, configure country publication, default account/invited/home groups,
currency, timezone and privacy-policy version. Request a geography update through
the reference-data workspace. The backend imports the official dr5hn dataset;
local publication, agreements and group assignments remain administrator-owned.
Only Namibia starts published.

The long-running worker is `reference-update` in prebuilt/production Compose and
`reference-mysql`, `reference-mariadb` or `reference-sqlite` in source profiles.
It runs `php bin/providentia reference:update --watch --recover`. The Admin update
status shows queued, running and terminal results; inspect worker logs if a job
fails. Existing country policy acceptance records retain their accepted revision.

Billing plans and paid platform AI remain future features. Manual groups and
limits work without payment; do not enable billing enforcement for this rollout.

## Troubleshooting and validation

A missing code usually indicates a stopped notification worker or SMTP failure.
For the prebuilt stack:

```bash
docker compose --env-file .env.prebuilt.local -f compose.prebuilt.yaml \
  ps
docker compose --env-file .env.prebuilt.local -f compose.prebuilt.yaml \
  logs --tail=100 notification reference-update api web
```

Check the actual requested recipient and newest message in Mailpit. Do not print
codes, binding proofs, access tokens or refresh tokens in diagnostics. A `403`
on invitation or home creation means the relevant group/role allowance must be
reviewed. A revision conflict requires a canonical reload before retrying.

Run the backend doctor and each client's canonical checks before merge:

```bash
bash tools/agent-setup.sh --doctor
```

See each client repository's `AGENTS.md` for its required test, package and launch
lane. Local health checks alone do not prove all release checks pass.
