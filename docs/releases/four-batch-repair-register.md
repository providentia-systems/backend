# Four-batch repair register

## Acceptance status

**NOT READY TO MERGE.** This register is being populated against actual source and newly executed evidence. Previous PR descriptions and historical handoffs are not current acceptance evidence. No merge, deployment, production mutation, permission broadening, live paid AI request or data reset is authorized by this repair register.

Companions: [Backend #23](https://github.com/providentia-systems/backend/pull/23), [Client #19](https://github.com/providentia-systems/client/pull/19), [Admin #11](https://github.com/providentia-systems/admin/pull/11).

## Verified starting points, 15 September 2026

| Repository | Open draft branch | Remote head at takeover | Base |
| --- | --- | --- | --- |
| Backend | fix/step-1-authoritative-sync | f04127a17490cd1f53470e629fc89016420528a6 | 9ce265a8e3948ce549f32537e3429b124d8ab921 |
| Client | fix/step-1-authoritative-sync | d576c0a98ea71eac689caccbc742f9ef632095b3 | a03fd4eedfc4996446789a2785f09288f521c3aa |
| Admin | fix/step-2-response-configuration | 8cf417041b2c50cc7f4368143de0e5fcd1669e61 | d15e3c4743f5535517e5e507056a9782d41895f2 |

Backend and Client reporting changes newer than the supplied handoff must be preserved. The historical ZIP contains no recoverable implementation beyond its explicitly unrun test draft. Both handoff checksum manifests were verified. The optional historical interoperability audit/findings are absent from the handoff and source snapshot; the four supplied specifications remain binding.

## Register

Rows below describe obligations, not completion claims. Implementation commits, changed paths, commands, runtime/database/platform, results and evidence will be recorded as execution establishes them.

| Item | Current acceptance status | Required completion/evidence |
| --- | --- | --- |
| SYNC-01 | OPEN | Persisted account/device provenance, safe legacy/ambiguous recovery, final-write/epoch fencing; actual login-to-outbox-to-receipt recovery and cross-account/device tests. |
| SYNC-02 | OPEN | Durable enqueue order and dependencies, migration/reopen/concurrent writers/backward clocks, unresolved-predecessor barriers. |
| SYNC-03 | OPEN | Equivalent read policy, permission-scoped cursor invalidation, grant bootstrap, revoked readable-cache removal and late-result fencing. |
| CAT-01 | Prior implementation; current acceptance pending | All eight persisted consent combinations and strict actual PHP-to-Dart typing on all three databases. |
| INV-01 | Prior implementation; current acceptance pending | Family-only and pack-normalized identity, import/read/sync consistency and dry-run idempotent reconciliation. |
| AI-01 | Prior implementation; current acceptance pending | Reachable manual/unconfigured management and safe unavailable extraction through production composition. |
| AI-02 | Prior implementation; current acceptance pending | Shared/private policy ownership, accurate consent recipients, reload/restart and credential/provider/revision races. |
| CAT-02 | Prior implementation; end-to-end acceptance pending | Both ordinary Admin approval/publication journeys to second household device; curator/reviewer and consent races. |
| AI-03 | Prior implementation; end-to-end acceptance pending | Evidence decisions to durable ordinary receipt/count completion, restart/lost-response/revocation and no duplicate effects. |
| DATA-01 | Prior implementation; platform acceptance pending | Worker/token/retrieval to explicit save/discard, requester/scope/expiry/cleanup and actual supported platform lifecycles. |
| ADM-01 | Existing Admin slice; inspection and acceptance pending | Real resource 403 preserves valid session, refreshes capabilities and fences stale routes/results; revocation clears sensitive state. |
| ADM-02 | Existing Admin slice; inspection and acceptance pending | Pre-send/429/503/invalid/ambiguous refresh, single-flight, durable restart ambiguity and logout fences without refresh replay. |
| PAGE-01 | OPEN | Bounded reachable traversal of every named collection, 151 records each, beyond-page category selection and mutation stability. |
| INV-02 | OPEN | Explicit justified count assessment, revisioned persistence/sync and valid consumption intervals excluding partial/unassessed evidence. |
| REP-01 | New remote implementation; verification pending | Preserve f04127a/d576c0a reporting repairs; strict real HTTP/Dart timestamp conformance in UTC, Windhoek and negative-offset/DST zones. |
| ERR-01 | OPEN | Semantic, truthful invalid-response/service/auth/revision/domain classification; no malformed-list empty success. |

**Separate RISK-01:** OPEN pending persisted integer normalization and database-to-JSON-to-Dart proof. It is a conditional portability risk, not the established cause of the original Household AI error.

## Execution and publication

The local Git HTTPS attempt failed with `Could not resolve host: github.com`. Source was recovered using authenticated GitHub artifact download, not an invented local checkout. Snapshot artifact 10398548724 has SHA-256 `c89fd694e0ed823c009db73de2951fc7a5e4dc48574fbe3950221d740832ea62`; its tracked-source tar checksums were also verified. The snapshot itself predates the latest reporting commits and must not overwrite them.

The host supplies PHP 8.4.23, no Composer/Flutter, and no Docker daemon. Recovered PHP artifact 10392584980 executes PHP 8.5.10 (the historical setup-php request was 8.5.9); exact runtime differences must remain visible. The combined Flutter/dependency artifact exceeds the connector's 512 MiB transfer limit. A temporary read-only workflow splits that existing checksummed archive; this is dependency setup only, not feature implementation or acceptance. Remove the transfer/preparation workflows after they are no longer needed.

At takeover the latest Backend pull-request workflows reported `action_required`; that status is not a pass. No final tested three-repository commit set exists yet. Required unavailable platforms and database lanes must remain blockers until executed.

## Release boundary

Do not deploy or remove production hotfixes from this register. Final instructions must identify actual migrations, idempotent recovery, backend-first compatible release order and rollback that preserves pending intent, audit history and privacy guards. No rollback may erase household data or remove authorization/consent/evidence protections. User-saved export copies remain outside application revocation.
