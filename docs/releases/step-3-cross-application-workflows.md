# Step 3 cross-application workflows

Status: implementation committed; paired release acceptance remains incomplete.
Keep draft. Do not merge or deploy merely because focused suites pass.

Companions: backend #23 (`fix/step-1-authoritative-sync`), Client #19
(`fix/step-1-authoritative-sync`), Admin #11 (`fix/step-2-response-configuration`).
The existing PRs and their Step 1/2 changes are preserved.

## Implemented scope

CAT-02: contributions remain reachable after approval, with current selection
and revision retained. Curators can link/review product proposals and publish
verified image contributions; ordinary reviewers cannot approve proposals that
publish globally. A contribution's approval is not presented as publication.
The paired backend remains authoritative for consent, withdrawal, revision and
curator checks. Household stock is not copied into global catalog facts.

AI-03: typed observation, duplicate and discrepancy decisions are exposed in the
household review flow. Unresolved evidence blocks acceptance and handoff; exact
media-digest relationships are immutable. Review writes serialize on the
home/extraction scope and use compare-and-set revisions. Accepted candidates
must be rejected before their underlying evidence can be changed. Receipt and
stock handoffs use deterministic home/extraction/candidate IDs, retain raw text
and stock ranges, resume ordinary drafts and require the normal second human
confirmation. Stock AI does not create inventory movements. Stock extraction
requires a real existing open count target before media transmission.

DATA-01: requester-bound completed exports can be retrieved using authenticated
single-use tokens carried only in POST bodies. Token/artifact responses are
private and no-store. Current authorization, home, expiry, request state and
revision are checked again before retrieval. The client validates artifact
identity/scope, refreshes once after a consumed token/revision race, and offers
separate explicit save/discard actions. Private buffers are cleared on discard,
expiry, logout, scope change and disposal. User-saved copies are user-owned.

## Contract, runtime and persistence

Canonical API version remains 2.1.0; 194 paths, 235 operations, 285 schemas.
Canonical document digest (SHA-256):

```
f6591ae866efbcc9e661528c7f595da0d7093d959d64c66c09b0c5be1dcb7c58
```

The additive `downloadEligible` projection defaults to false for new consumers
when absent. It never grants permission. All three canonical/archived sources,
materializers, generated clients/facades, locks and enforced pins were paired.
Archive hashes describe compressed bytes and are verified independently of the
canonical JSON digest. No Step 3 database migration or dependency upgrade is
introduced. Durable intake IDs use existing receipt/count identifiers; local
resume hints contain scoped identifiers, not media or cached evidence.

Executed runtime: PHP 8.5.10, Composer 2.10.2, Flutter 3.44.7, Dart 3.12.2.
The existing PHP setup requested 8.5.9 but the runner reported 8.5.10; this report
uses the actual runtime instead of assuming the request was exact.

## Executed evidence

Backend source validation run 34900268584 passed full `composer check` and the
migrated email-code authentication HTTP smoke; the full PHPUnit suite contains
588 tests. Corrected source was committed as
78aad130e8df979eb00532462bcb532d6a860f87.

Normal quality run 34901244244 passed after adding the actual export acceptance
lane at head 177685258d51ceb37e3c18034e038db7297c5fa8. Related contract, image,
headless, Step 1 and prior Step 2 three-database checks also passed at that head.
These exact runs are historical evidence, not a substitute for current-head CI.

**Real Step 3 export conformance run 34901244406 passed SQLite, MySQL 8.4.6 and
MariaDB 11.8.3.** It used actual migrated database/HTTP middleware/services,
encrypted export worker and production generated Dart repository, not fabricated
HTTP results. Backend PR merge snapshot was
9559f405540a8698470125408ac3afea07673a87, from head 1776852; the paired Dart
consumer was b45b0dce6a778db6b070f979fcac6dbe01740662. The isolated sequence:

1. Request account and home exports, cancel another request, reject other-home
   listing, then stop the API process.
2. Execute two real export-worker jobs and restart the API process.
3. Retrieve completed account/home artifacts through the production Dart
   adapter, verify IDs/scope/content, and erase application buffers.
4. Reject cancelled and foreign-requester retrieval; a foreign requester cannot
   consume the legitimate requester's token. Confirm single use (200 then 410).
5. Consume a real token between issuance and retrieval, exercising actual 410,
   authoritative revision refresh and one fresh issuance through the adapter.
6. Run complete email-code HTTP authentication again in each database mode.

The permanent command is:

```sh
bash tests/Acceptance/step3-data-http-dart.sh /absolute/path/to/paired-client
```

It requires a newly migrated isolated CI database, the locked dependencies and
Flutter setup; its seed refuses a nonempty account database. Never run it against
production. The workflow records runtime/source refs and assertions, not
session fixtures, raw private exports, provider credentials or production data.

Client source validation run 34900959515 passed strict analysis and 1007 tests,
including a real on-disk Drift receipt handoff reopen: same receipt/line IDs,
manual corrections retained, unchanged operation count and no receipt commit.
Admin run 34901033775 passed 162 tests and reproduced/fixed the ordinary queue
approval, toolbar and dialog regressions. Follow-up coverage/lifetime checks
and normal current-head platform jobs are separately required.

The historical secret scanner findings in Step 2 were inspected: one public
contract digest and one explicit synthetic CI-only notification key. Exceptions
match both the exact file and exact value; other secrets remain detectable.
The scanner also reports an existing Semgrep PHP TLS-rule parse warning. That
warning is not evidence that TLS acceptance passed and is not waived here.

## Still required before paired release acceptance

No claim of full completion is made for these unexecuted boundaries:

- Full actual Admin screen -> real backend -> second household database catalog
  identity and sanitized-image publication, including concurrent withdrawal and
  consent changes. Widget fixtures and backend integration tests are separate.
- Full real-backend/generated-repository/controller/ordinary-draft AI receipt and
  multi-image stock workflows across all three database modes, including lost
  responses and restart/revocation races through the integrated pipeline.
- Browser and each native platform's actual file picker/download/save/cancel and
  revocation behavior, including iOS protected temporary-file cleanup and
  Android in-flight picker handoff. Compilation alone is not this evidence.

## Rollout, rollback and deferred work

No production database operation, live/paid provider request, merge, deployment
or infrastructure service was performed. Deploy the compatible backend before
the paired Client/Admin builds only after outstanding acceptance and separate
deployment authorization. Preserve the current database, images and client
build identifiers first. No data rewrite or migration is needed for this batch.

Pre-Step-3 refs: backend 6cae4415a443d384b8519b30a72f374400b66db2;
Client 5b5a35ce212d488080a0442f0c2af34efc189c62;
Admin d73f3658d2ecce3852ae56ed88167718368d6815.
Rollback paired application builds without removing backend evidence blocking,
requester authorization, consent or privacy safeguards. Publication and ordinary
receipt/count drafts are persisted operations; rollback must not duplicate or
automatically undo them. Explicitly saved export copies cannot be revoked.

Earlier Step 1 general outbox provenance/dependency and permission-cache
lifecycle blockers remain. Step 4 general session/reporting/pagination resilience
is not implemented here. Limited moderation navigation, export revision lookup
and intake idempotency are prerequisites within this batch, not a Step 4 claim.
