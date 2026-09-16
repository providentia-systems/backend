# Backend synchronization regression follow-up — 16 September 2026

## Exact base and preserved concurrent work

This backend-only follow-up is based on the published PR #23 branch
`fix/step-1-authoritative-sync` at
`067d5e16d39d07cfaddb3af97763eb69cf5141de`.
Its complete Git tree, `48d16770cf4f5a61b6d1c6a80f5c5c63b73290ec`, and original
commit object were recovered through the GitHub connector and verified exactly.
It includes the earlier `32fae85` reader-scoped cursor repair. Local overlapping
production implementations were discarded during rebase in favor of the
published implementations, not layered over or used to replace them.

The upstream cursor codecs, read-policy digest, result presenter, immutable
receipts, numeric validation and failure-classification tests are unchanged.
In particular, HTTP 401 remains a request-level authentication failure;
408/425/429 and 5xx remain retryable outcomes; 403/404 remain authorization failures;
409/412 remain conflicts. Transient implementation details remain redacted.
The added local 30-case alternative classification matrix was removed because it
duplicated and differed from the now-published matrix. No workflow was disabled,
quality gate weakened, branch replaced, PR merged or deployment performed.

## Additional changes on top of the published base

1. `SynchronizationService::readPermissions()` rechecks active membership,
   including its use when projecting a stored command receipt. This is the only
   production-source addition; error and cursor encodings are preserved.
2. `SyncPermissionCursorTest` adds 18 cases for grants, revocations, historical
   bootstrap, cursor transfer, session rotation, legacy cursors, permission order,
   mid-response changes and membership loss. Store doubles are identified as such.
3. The canonical headless acceptance harness retains upstream's cross-reader HTTP
   410 check and independent bootstrap. It additionally checks absence of private
   records on denial, forces one-record snapshot pages, checks cursor progress and
   bounded completion, and verifies both original records before checking later
   operator edits. It never borrows another reader's cursor.
4. `PlatformAccessWorkflowTest` adds a 47-assertion integration case covering the
   same denied transfer, paged recovery and operator-edit delta sequence through
   real PSR-15 handlers, problem middleware, login, authorization, migrations and
   SQLite adapters. It checks that denial cannot advance an acknowledgement.

The HTTP integration case injects the authenticated identity obtained from the
real login use case. It is in-process handler evidence, not a socket, Docker,
complete bearer-middleware, Flutter, or two-real-device acceptance run.

## Reproducible validation

Runtime recovered from successful backend workflow `35064232002`, artifact
`10433752166`: PHP 8.5.10, Composer 2.10.2, PHPUnit 12.5.33, locked vendor tree.
Source/runtime ZIP and internal archive checksums were verified. Initial source
was `86e36e1`; later upstream trees and commit objects were verified before rebase.
The artifact ZIP SHA-256 is
`67d76eebc0bd62a2728b69228148a0851970bcf4cf13a050ad569cbe9a1a5c98`.
Actual PHP 8.5.10 is recorded rather than incorrectly claiming the configuration's
requested 8.5.9. No lockfiles or dependency constraints were changed.

Final validation commands are unchanged project checks:

```bash
composer check --no-interaction
bash tests/structural/verify.sh </dev/null
bash -n tests/Acceptance/headless-platform-acceptance.sh
git diff --check
```

Final results on this rebased source: `composer check --no-interaction` exited 0,
including PHPCS across 544 files, PHPStan level 8 with no errors, **677 tests /
3,961 assertions**, architecture and canonical OpenAPI verification. Structural,
Bash syntax and diff checks exited 0. Exact logs and exit codes are retained in
the accompanying repair package's evidence and manifest. Earlier 662-, 678- and 679-test runs
belong to superseded local trees and are not the result for this rebased commit.

## Compatibility and remaining acceptance

No migration, household-data rewrite, cursor format, API schema, dependency lock
or workflow-configuration change. API 2.1.0 retains canonical digest
`f6591ae866efbcc9e661528c7f595da0d7093d959d64c66c09b0c5be1dcb7c58`.
The inherited HTTP 410 safe-bootstrap response still requires paired client
recovery that preserves pending/ambiguous outbox intent, removes inaccessible
cache entries, resets the appropriate cursor and fences late responses.
A committed write followed by a response fence is recovered by its immutable
operation ID/receipt, not assigned a new operation ID.

The permission digest is not a durable permission-change epoch and does not prove
a revoke/regrant cycle that returns to the same scope between observations.
Durable provenance/order, client/admin recovery, full pagination, reliable counts,
AI/publication/export/resilience acceptance and the other repair-register items
are not closed by this follow-up. No MySQL/MariaDB, Redis/Valkey, Docker,
coverage/mutation or real-device acceptance was executed in this local session.

Publication must be verified separately by remote SHA and new-head CI. A local
passing suite is not evidence that the full four-batch assignment is complete.
