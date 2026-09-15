# Step 2: response contracts and recoverable AI configuration

Scope: CAT-01, INV-01, AI-01 and AI-02 only. This does not certify the remaining
Step 1 synchronization acceptance work or implement Steps 3 and 4. Companions:
backend PR #23, client PR #19, Admin PR #11.

## Contract and rollout

Canonical API 2.1.0 JSON SHA-256:
`d8263a996b1382a0b0742ba4b3ca232e1d6f291644a2659d966e95b2abb038fc`.
Regenerate both clients from this exact backend-owned document, including
facades, locks, archived sources and every structural verification pin.
Settings now include an atomic viewer-scoped `providerProfiles`,
`orchestrationPolicy`, and nullable `transmissionPlan` snapshot. A non-null plan
contains actual saved profile IDs and positive profile revisions; a null plan
means setup/repair is required. Consent remains strict JSON booleans. Do not add
Dart truthy coercion or weaken identifier parsing to hide server defects.

Deploy the backend first, then the paired Client and Admin builds. The updated
Client requires the embedded snapshot and is incompatible with an older backend
that omits it. Schedule the paired update before asking users to test repaired
family-only inventory. Back up the database and preserve the current image
and client build identifiers. There is no schema migration or automatic rewrite.
Do not deploy merely because isolated regressions pass: normal production gates
and the real cross-database HTTP/Dart acceptance lane remain required.

## Supported inventory identities

Private items have no global product or pack. Catalog-backed family items have
a product but no selected pack; they remain valid, visible and labeled as
awaiting pack selection. Resolved items have a product and its explicitly
selected pack. Pack-only creation derives the parent from that exact pack and
emits the persisted normalized identity to synchronization, not the request.

Never resolve a family by choosing its first pack, approximate name matching, or
changing raw source wording. Keep household IDs, original pack text, balances,
stock movements, count lines, receipt rows and revision histories. Older valid
pack-only bootstrap/delta events are normalized at read time without rewriting
the raw append-only log.

## Bounded, dry-run-first reconciliation

Run in the API container, substituting an actual home UUID:

```sh
php bin/providentia inventory:reconcile-identities --home=HOME_UUID --limit=100
```

The report contains IDs, classifications, outcomes and pagination; it omits
names, quantities and raw private wording. Review `manual_review` entries
separately. They are never deleted, merged or assigned a guessed pack.
`family_without_pack` remains family-only even when multiple packs exist.

After review and backup, an authorized deployment operator may explicitly apply,
attributing changes to an **active owner of that same home**:

```sh
php bin/providentia inventory:reconcile-identities \
  --home=HOME_UUID --apply --actor=OWNER_USER_UUID --limit=100
```

For subsequent pages use `--after=NEXT_AFTER_ID` from the previous report.
Each candidate is rechecked under a transaction and optimistic revision check.
A correction advances the existing home-product revision and appends a corrected
feed/outbox event in the same transaction. Publication failure rolls back the
correction; concurrent edits report conflicts. Replay is idempotent once the
current identity is represented correctly in the feed. This is not an HTTP
endpoint: protect operator shell access. No credential is requested or logged.
The assignment does not execute this command against a user server.

## AI policy ownership, repair and consent

A shared-home policy may reference **shared profiles only**. Members retain their
private provider profiles. A usable personal same-provider profile can override
a shared source for that member, as the existing design permits, but its actual
ID, revision, provider, model and endpoint must appear in that member's plan
before consent. It never becomes a shared policy reference. Another member must
not receive its metadata through a policy, settings response or plan.

Old policies containing private, deleted or disabled references are disclosed
as an empty, non-executable policy while retaining the revision and limits for
optimistic repair. A household manager selects a shared profile and saves the
policy at that revision. No implicit migration copies credentials or promotes a
private profile into shared scope.

Manual mode, an empty provider registry, an unavailable vault, missing required
credentials or an unusable policy must not lock out settings/profile/policy
management. Extraction stays blocked until an executable plan is available.
Consent binds the member, home, settings/policy revisions, ordered extraction
and fallback profiles and validator. Permissions and the current plan are
rechecked immediately before each outbound attempt; changes require renewed
disclosure and consent. An already-sent request cannot be recalled: the guard
prevents later dispatches, not retroactive cancellation of an in-flight request.

## Verification and stopping point

```sh
composer check
bash tool/generate-dart-client.sh
bash tests/Contract/verify-generated-dart.sh var/generated/providentia_api
# In an explicitly isolated, disposable migrated test database only:
bash tests/Acceptance/step2-http-dart.sh /absolute/path/to/pinned/client
```

The read-only `Step 2 response conformance` workflow records both actual commit
SHAs and runtime versions. It runs SQLite, MySQL and MariaDB against real HTTP,
production bearer authorization, generated Dart repositories and a real Drift
file. It checks saved consent combinations/withdrawal; name-, product-, pack-
and barcode-based imports; normalized deltas/bootstrap; preserved family stock
and source wording; two members' private/shared AI plans; stale consent; and
fresh API/local database reopen. Disabled-adapter/vault states are tested both
before and after configuration. Separate email-code HTTP smoke retains the real
authentication regression. Synthetic accounts/reference catalog rows only;
fixtures refuse an existing account database, and temporary session files are
removed rather than uploaded. No paid provider or household media is used.

Seven backend regressions were committed before production changes; all failed
at `40cc6d64796fec3e4e6e134e569f5ea12a6be999`. Backend source
`64b727a7763560865123ea8a7a85d7b6e51ebb5c` passed all 584 tests and 3,530
assertions, with clean PHPStan. Final result artifacts and PR comments identify
later tested commits; a lane's existence alone is not a passing result.
Actual runtime was PHP 8.5.10 despite requested 8.5.9, Composer 2.10.2,
PHPUnit 12.5.33, Flutter 3.44.7 and Dart 3.12.2. Exact PHP 8.5.9 execution is
not asserted.

Step 3 consumes these contracts without inventing packs or changing profile
ownership. The existing SYNC-01 account/cache lifecycle, SYNC-02 durable ordering
and SYNC-03 permission-scoped cursor/cache blockers remain on the draft PRs.
Step 2 does not certify the whole application for production release.

## Rollback and temporary overrides

Roll back incompatible backend/client builds together. Do not downgrade just
the backend while retaining the new Client. Do not delete correction events or
roll revisions backwards: any reversal is an explicit domain repair or a
coordinated database restore with the usual device-cache/resync plan.

Source bind-mount hotfixes can mask code in a new image. Inspect effective Compose
configuration and container mounts. Remove an override only after confirming its
specific permanent fix in the deployed image and checking a saved-state reload.
Keep previous configuration and image digests. This batch does not certify
removal of unrelated onboarding, account-group or earlier Step 1 overrides.
No production configuration, data, merge or deployment is changed here.
