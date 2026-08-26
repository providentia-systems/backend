# Usable Pilot 0.1 — owner runbook

This is the repeatable path from three merged repositories to a running
system you can sign into and test. It assumes a Linux host with Docker
Compose v2, `curl`, `jq`, `unzip`, `openssl`, and the verified handover
archive `Pantry_Stock_Project_Handover_2026-07-29.zip`.

## 1. Start the backend (prebuilt images)

```bash
git clone https://github.com/providentia-systems/backend.git
cd backend
bash scripts/setup-prebuilt.sh \
  --handover /absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip \
  --dev-email you@example.test
```

One command gives you, in order: generated local secrets, pulled images,
healthy MySQL/Redis/Mailpit, migrations, the **starter catalog seeded and
replay-verified** (292 mapped rows, 8 quarantined for review), all
application processes, HTTP health proof, and a **passwordless developer
account** provisioned through a real login-link exchange. The handoff file
`.providentia-development.json` records the API URL, your home, device,
installation, and session credentials — never a password.

- API: `http://127.0.0.1:8080` (override `--http-port`)
- Mailpit (captured email, incl. login links): `http://127.0.0.1:8025`
- Development profiles set `EXPOSE_DEVELOPMENT_TOKENS=1`, so login-link
  responses carry `developmentApprovalToken` for non-interactive approval.
  Production deployments keep it off; approval then happens only through
  the emailed link.

Re-running the script is safe: seeding and provisioning are idempotent.
`--reset-data` wipes the local stack deliberately.

## 2. Build the homeowner client aimed at your backend

```bash
git clone https://github.com/providentia-systems/client.git
cd client
bash tools/agent-setup.sh && source .agent-env
flutter build linux --release \
  --dart-define=PROVIDENTIA_API_BASE_URL=https://api.your-host.example \
  --dart-define=PROVIDENTIA_ENVIRONMENT=production
# local development against the prebuilt stack instead:
flutter run -d linux \
  --dart-define=PROVIDENTIA_API_BASE_URL=http://127.0.0.1:8080 \
  --dart-define=PROVIDENTIA_ENVIRONMENT=development
```

The backend URL is compiled in and is not user-editable at runtime: one
backend, many fixed-aim clients. HTTPS is mandatory outside loopback
development. The same defines apply to the web/Windows/macOS/Android/iOS
targets built by CI.

## 3. Build the Admin client

```bash
git clone https://github.com/providentia-systems/admin.git
cd admin
bash tools/agent-setup.sh && source .agent-env
bash tools/agent-check.sh        # builds and verifies the Debian package
sudo apt install ./build/packages/providentia-admin_*_amd64.deb
```

Admin authenticates through its own application-bound login link. An
operator identity requires the platform role: set
`PLATFORM_BOOTSTRAP_ADMIN_EMAILS=you@example.test` in the backend
environment before that account's first login-link approval.

## 4. Acceptance journeys (pass/fail)

Sign-in and sessions
- [ ] Enter your email in the client; approve the emailed link (Mailpit in
      development); the app signs in without any password existing anywhere.
- [ ] Device sessions list shows the installation as
      "Signed in until you sign out"; restart the app — still signed in.
- [ ] Sign out; the session is revoked server-side.

Homes and membership
- [ ] Your seeded home shows the starter catalog (browse categories and
      products immediately; no other household's stock or history).
- [ ] Create a second home; switch between homes; data stays isolated.
- [ ] Invite a second email as `member`; sign in as that person in another
      profile/device: the invitation appears FIRST with an explicit
      "Create a home instead" choice — no automatic home is created.
- [ ] As owner: change the member's role; remove the member (their access
      and local private data for that home are revoked).
- [ ] Propose an ownership transfer (emailed step-up confirmation), accept
      it from the target account, verify the roles swapped.

Stock without AI
- [ ] Create categories/products, record purchases, adjust stock, build a
      shopping list — with no AI provider configured anywhere.

Spreadsheet import (desktop)
- [ ] Import a CSV/XLSX of products through pick → map → review → confirm;
      verify the reconciliation counts and that re-confirming does not
      duplicate.

AI (bring your own key)
- [ ] Add a private provider profile (default) with your token — and for
      OpenAI-compatible/Ollama, your endpoint; another member cannot see it.
- [ ] As owner, deliberately create a home-shared profile; a member can use
      it for scans; scans prefer a member's own private profile when both exist.
- [ ] Photograph shelf stock; review the proposed count lines; commit
      explicitly; verify the audited adjustment.
- [ ] Photograph a receipt; correct/match lines; commit once; verify
      purchases and stock; retry does not duplicate.

Governance
- [ ] Share a store price / product image with explicit consent; moderate
      it in Admin (no household identity visible); withdraw consent and
      verify the public fact disappears.

## 5. Known boundaries of Pilot 0.1

Optional MFA, home description/image, full Admin catalog CRUD, cross-home
reports, billing enforcement, store signing, and production cutover remain
explicitly later milestones (see `docs/unification-decision-record.md`).
