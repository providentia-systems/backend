# Pre-release platform testing runbook

This is the path from matching backend, household-client and Admin checkouts to
a running local system. The project has no live users or deployed data to migrate;
this release aligns the code directly with the current platform decisions.
Use the coordinated PR branches until they are merged and their release checks
have passed. A running development stack is not a substitute for those checks.
After publication, run the
[post-release acceptance checklist](post-release-acceptance.md) against the
released backend, Client and Admin artifacts.

## Start the backend

From the backend checkout, with Docker Compose v2, `curl`, `jq`, `unzip`,
`openssl` and the authorized handover archive available:

```bash
bash scripts/setup-prebuilt.sh \
  --handover /absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip \
  --dev-email developer@providentia.local
```

On an `agent/*` branch this selects that commit's immutable candidate images,
checks their revision labels, generates local secrets, starts MySQL/Redis/Mailpit,
applies migrations, verifies the starter catalog import and replay, starts all
workers and proves HTTP readiness. The script verifies its development test
account through a code actually delivered to Mailpit, accepts the current Namibia
policy for that test account and explicitly creates its home. The protected
`.providentia-development.json` records the account, installation, home and session.

Use `scripts/setup-development.sh --handover PATH` to build the source instead.
That source setup also imports the authorized household inventory baseline into
its development home. A regular signup's home starts without inherited quantities.

- API: `http://127.0.0.1:8080`
- Mailpit: `http://127.0.0.1:8025`
- Readiness: `http://127.0.0.1:8080/health/ready`

Rerunning setup preserves existing accounts and uses idempotent imports.
The email-code resend cooldown still applies. `--reset-data` explicitly destroys
the local database and volumes; use it only when a clean test install is intended.

## Run the household client

From the matching client checkout:

```bash
bash tools/agent-setup.sh
source .agent-env
flutter run -d linux \
  --dart-define=PROVIDENTIA_API_BASE_URL=http://127.0.0.1:8080 \
  --dart-define=PROVIDENTIA_ENVIRONMENT=development
```

Enter your email, request a code, read it from Mailpit and enter the eight digits
in the client. No link or backend browser page is involved. A new account completes
name, country and privacy-policy acceptance. The initial standalone account group
allows one owned home; invited accounts initially receive joining access without
owned-home allowance. Home creation and invitation acceptance are explicit actions.

The API URL is compiled into each client; it is not user-editable. Remote endpoints
require trusted HTTPS. Follow [client-user-testing.md](client-user-testing.md) for
Chrome, Android tunneling and other development targets.

## Bootstrap and run Admin

From the backend checkout for the prebuilt stack:

```bash
docker compose --env-file .env.prebuilt.local -f compose.prebuilt.yaml \
  exec -T api php bin/providentia system:owner owner@example.test
```

For source Compose replace the env file with `.env.development.local`, omit
`-f compose.prebuilt.yaml` and use service `api-mysql`.

From the matching Admin checkout:

```bash
bash tools/agent-setup.sh
source .agent-env
flutter run -d linux \
  --dart-define=PROVIDENTIA_API_BASE_URL=http://127.0.0.1:8080
```

Sign in with the authorized system-owner email and its code. Other administrators
apply by signing in and await group assignment and approval. Admin permission
groups control each workspace, inspection capability and management operation.
The protected system owner has full administrative access.

## Configure and test the platform

Use Admin to configure account, home and administrator groups. Enable
`members.invite` in the selected home's group before testing invitations; it is
off in the default group. Configure role and total member limits. Raise the
account's owned-home limit before expecting a second owned home. Homeowners can
set individual member permissions only within the home's delegation ceiling.

| Test | Passing behavior |
| --- | --- |
| Email codes | Wrong, expired and replayed codes fail; another installation's binding cannot verify them. |
| Trusted sessions | Restart restores access; sign-out and device revocation remove it. |
| Profiles | Name/avatar and verified email changes persist; one verified primary email always remains. |
| Home profile | Description, image, optional location, currency and timezone persist. |
| Invitations | Recipients explicitly accept or decline; unrelated accounts cannot act on them. |
| Home switching | Data and permission rules follow the selected home. |
| Downgrades | Existing members/categories remain; additions above the reduced quota fail. |
| Disabled features | API and client controls no longer permit the operation. |
| Administrator groups | Delegated staff see only authorized workspaces; system owner can inspect all application data. |
| Country configuration | Only Namibia starts published; group defaults, currency, timezone and policy are editable. |
| Geography update | Admin queues an official dataset update and reports the worker's final status. |
| Manual stock | Categories, products, purchases, adjustments and shopping lists work with no AI configuration. |
| Reviewed import | CSV/XLSX and AI proposals require explicit review; retrying a commit does not duplicate stock. |
| Public sharing | Opted-in catalog metadata can become starter data; quantities and private history are not published. |

Operator inspection is independent of public sharing. It uses backend-authorized,
audited routes and does not expose authentication secrets or AI provider tokens.
The privacy agreement explains staff visibility and use of application data to
improve the service. Household clients stay isolated by authorized home membership.

## Finish verification

Run the backend doctor, the household client's canonical checks and
`bash tools/agent-check.sh` in Admin. The latter includes Linux packaging and
launch verification. Required GitHub checks must be green at the coordinated
final commits before merging or treating the release as complete.

Billing enforcement, paid platform AI, app-store purchasing and optional MFA are
future work. Manual access-group assignment and current stock control are the
rollout focus; this release does not require payment.
