# Phases 04–06 testing-readiness report

> Historical checkpoint: this report records the API 1.13.2 paired-client
> state. The current three-repository/API 1.15.0 baseline is maintained in
> `docs/product/project-memory.md` and `docs/inventory-integration-roadmap.md`.

## Outcome

The backend contains the complete repository-owned capability needed to test
the master prompt's Phase 04 synchronization, Phase 05 household parity, and
Phase 06 privacy-controlled AI workflows. API `1.13.2` includes revision-bound
count cancellation, exhaustive non-disclosing AI/shopping home denials, and a
durable receipt-line unresolved decision through ordinary HTTP and protocol-v2
synchronization.

This is a testing-readiness statement. Production acceptance still requires
the operator-owned staging, physical-device, live-provider, backup/restore,
monitoring, and release-signing evidence listed below.

## Phase 04 — synchronization

Delivered and automated:

- device/home-bound push, paged bootstrap, ordered pull, signed cursors,
  tombstones, revisions, frozen high-water pagination, and compaction bounds;
- stable batch/operation idempotency and isolated operation-status recovery;
- typed protocol-v2 pantry commands dispatched through ordinary application
  services;
- explicit validation, authorization, conflict, and retry classifications;
- retry/offline-compatible client contract and privacy-safe metrics;
- deterministic multi-device, lost-response, cursor, retention, and
  cross-home tests.

## Phase 05 — existing feature parity

Delivered and automated:

- dashboard, typed paged item master, stock, search, category, movement, and
  balance reads; the item master exposes published packs and only authorized
  global/home aliases for offline caching;
- manual adjustments and open/close/cancel count sessions;
- approved/unresolved receipt-line decisions, idempotent receipt commit,
  purchase history, stores, approved-line-only price/stock effects, manual
  lists, checked state, and legacy-labelled suggestions;
- checksum-gated, resumable/idempotent baseline import with exact
  reconciliation and unresolved-row quarantine. Opening stock preserves 292
  catalog packs, links 32 reviewed/exact rows, retains 28 distinct private
  products, and imports all 60 count rows / 159 units without duplicate
  private identities for the nine reviewed `Pack size pending` links;
- permission and cross-home isolation at every application service.

Cancelling an open count now changes only the session status and revision. It
does not apply count lines or create stock movements. Replaying an already
cancelled session returns its authoritative terminal revision.

Receipt commit requires each raw line to be explicitly approved or left
unresolved. Approved lines alone create price observations and inbound
movements; unresolved lines remain in private receipt history without either
effect. Identical unresolved commands return the stored operation receipt.

## Phase 06 — receipt and stock-photo intelligence

Delivered and automated:

- `manual_only`, encrypted server-proxy, and client-owned strict-local policy;
- OpenAI Responses, generic OpenAI-compatible, and Ollama provider adapters;
- encrypted write-only credentials, endpoint/redirect/SSRF policy, bounded
  media validation, and explicit transmission consent;
- truthful media disclosure: direct extraction is transient and not persisted
  by application storage; optional private media requires an explicit retention
  choice and is encrypted before persistence;
- versioned receipt/stock structured schemas, refusal/invalid-output handling,
  sensitive/unrelated quarantine, and prompt-injection separation;
- revisioned human candidate decisions and a non-mutating handoff to normal
  Phase 05 receipt/count commands;
- encrypted private-media retention controls introduced by later hardening.

AI review alone never creates a receipt line, price observation, count line,
catalog match, or movement. The tester must complete the corrected normal
domain command and its final confirmation.

## Required paired-client smoke path

1. Sign in by email login link and select a disposable home.
2. Create a private product and adjustment offline, reconnect, and verify one
   movement after retry.
3. Open then cancel a count and verify `cancelled` appears on the second
   device with no balance change.
4. Open a second count, record confirmed lines, close it, and verify only the
   resulting variances create movements.
5. Create, match, approve, and commit a receipt twice; verify one set of
   inbound movements.
6. Configure AI with synthetic media, review every candidate, and then submit
   corrected Phase 05 commands explicitly.
7. Revoke the membership and verify further private reads/writes fail and the
   client purges the home projection after synchronization quiesces.

## Production evidence still owned by deployment

- staging run against the selected MySQL/MariaDB and Redis/Valkey profile;
- two physical/emulated devices plus supported-browser persistence;
- real provider credentials using synthetic/redacted media only;
- load, failover, backup/restore, alerting, and incident-response rehearsal;
- immutable artifact digests, signing, store/notarization, and rollout/rollback
  approval.

Missing operator evidence keeps the build testing-ready, not
production-accepted.
