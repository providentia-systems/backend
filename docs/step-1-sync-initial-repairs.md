# Step 1 — initial synchronization repairs

Status: **partial implementation; not approved for merge or deployment**.

Companion PRs: providentia-systems/backend#23 and providentia-systems/client#19.
Baselines: backend `9ce265a8e3948ce549f32537e3429b124d8ab921`, client
`a03fd4eedfc4996446789a2785f09288f521c3aa`. Exact tested heads and CI run links
are recorded in the PR discussions. Steps 2–4 are not included.

## Included changes

### SYNC-01 — first device-binding repair

The Flutter production workspace now uses the authenticated session's device ID
for new commands and receipt recovery. Installation identity remains unchanged
for login. Workspace identity includes account, device, home and permissions.
A session-bound gateway refuses mismatched operations without rewriting their
IDs, device IDs or payloads, and invalidates outgoing workspaces. The authorized
HTTP client checks account/device/session before sending and after receiving,
including streamed bodies and refresh retries. These checks do not yet prove
isolation of every late local database write.

### SYNC-03 — server-side read projection

Bootstrap, incremental pull, push results, duplicate typed-command receipts and
operation-status recovery now use the explicit `SyncReadPolicy` allowlist.
Unknown entity and command types are denied, including for owners. Home
membership and existing ordinary domain permissions remain mandatory. No
platform role grants private-home access.

| Projections | Required existing permission |
| --- | --- |
| private-note, home-preference | home.read |
| inventory location, category, product, balance, count session/line | inventory.read |
| purchasing store, receipt, receipt line | purchases.read |
| shopping preference, list, line, suggestion feedback | shopping.read |

Denied receipt responses retain only operation ID and outcome. An accepted
operation remains accepted, preventing a lost response from implying that it
should be executed again. Stored immutable receipts are never redacted in place.
The positive recovery fixture is explicitly classified; separate tests reject
unclassified receipts and deny payloads after a permission change.

Incremental position follows the last **scanned** row, not the last visible row.
Denied-only pages therefore advance, and an empty retained range finishes at
its high-water mark. Bootstrap similarly advances over raw snapshot keys even
when a page has no permitted records.

## Outstanding Step 1 work — release blockers

- SYNC-01: persisted account provenance, secure explicit recovery for legacy or
  ambiguous work, accepted/never-submitted/unknown-outcome reconciliation, and
  fencing of all local commit/cache paths across account/device changes.
- SYNC-02: monotonic enqueue sequence or explicit dependency storage, Drift
  migration, dependency-aware legacy reconciliation, reopen, concurrent writer,
  fixed/backward clock, and blocked predecessor acceptance. No queue ordering
  migration is included in these initial commits.
- SYNC-03: user/permission-aware cursor invalidation, permission-grant bootstrap
  of existing records, and removal of newly inaccessible cached data. Filtering
  future server responses is **not** a substitute for cache revocation.
- Backend-owned contract/compatibility updates for the completed lifecycle;
  regenerate affected Dart artifacts rather than editing generated files.
- Real PHP HTTP to production Dart adapters, two accounts on one installation,
  two devices, home switching, interrupted responses and duplicate effects.
- Full required checks and critical line/branch coverage; no gates are reduced.

## Test evidence and reproduction

The initial three service regressions were committed before production fixes;
all three failed on the old service and passed after the read projection fix.
Those tests use the real application service with a stub persistence port, not
HTTP/database or Flutter integration evidence. Additional tests exercise real
HomeAuthorization decisions, mixed pages, receipt revocation and no admin
membership bypass. Client tests cover source wiring, gateway boundaries and
HTTP logout races; they do not replace the outstanding two-device acceptance.

Backend commands: `composer check`, `vendor/bin/phpunit tests/Unit/Synchronization`,
`composer test:coverage`, `composer coverage:check`, and `composer test:mutation`.
Existing CI covers SQLite and MySQL 8.4.6/MariaDB 11.8.3 migration/authentication
smokes, Redis/Valkey queue proofs and image builds. Read each job's final result.
Setup-php resolved the requested 8.5.9 to **8.5.10** in the first CI runs; report
the actual runtime, not the requested patch version. Exact 8.5.9 test proof
remains outstanding. Composer stays locked at 2.10.2.

Client commands after the repository-pinned Flutter/Dart installation:
`flutter pub get --enforce-lockfile`,
`flutter test test/app/step_1_sync_binding_test.dart`,
`flutter test test/core/synchronization`,
`flutter test test/features/identity`,
`flutter test test/integration/api11_onboarding_adapters_test.dart`,
plus the canonical analysis, coverage and Linux release build gates.

## Migration, release order and rollback

These initial changes introduce **no database migration**, feature grant,
production environment change or household-data reset. They do not repair the
historical queue automatically. Legacy mismatches stop instead of being sent
under a different authenticated identity.

Do not release these draft PRs as the completed slice. Complete the account,
queue, cursor/cache and cross-language acceptance blockers first. Then freeze
companion commit IDs and deploy the backward-compatible backend protections
before the paired client under a separately authorized release plan. Do not
roll back by restoring permission-blind reads or by rebinding pending work.
An emergency rollback must retain server-side denial and pause synchronization
where compatibility cannot be demonstrated; leave receipts and household data
intact. No destructive down migration is required for the initial code changes.

There is no Step 2 handoff yet: finish and validate the remaining Step 1 work
on these PRs before beginning it.
