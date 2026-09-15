# Four-batch repair register

## Acceptance status

**NOT READY TO MERGE as the complete four-batch assignment.** Completed source repairs and passing individual workflows are distinguished from the full acceptance obligations below. No merge, deployment, production mutation, permission broadening, live paid AI request or data reset has been performed in this repair pass.

Companions: [Backend #23](https://github.com/providentia-systems/backend/pull/23), [Client #19](https://github.com/providentia-systems/client/pull/19), [Admin #11](https://github.com/providentia-systems/admin/pull/11).

## Verified starting points, 15 September 2026

| Repository | Existing branch | Remote head at takeover | Base |
| --- | --- | --- | --- |
| Backend | fix/step-1-authoritative-sync | f04127a17490cd1f53470e629fc89016420528a6 | 9ce265a8e3948ce549f32537e3429b124d8ab921 |
| Client | fix/step-1-authoritative-sync | d576c0a98ea71eac689caccbc742f9ef632095b3 | a03fd4eedfc4996446789a2785f09288f521c3aa |
| Admin | fix/step-2-response-configuration | 8cf417041b2c50cc7f4368143de0e5fcd1669e61 | d15e3c4743f5535517e5e507056a9782d41895f2 |

The handoff's historical local-implementation claims are not proof of preserved code. Actual remote source takes precedence. Backend reporting implementation `f04127a17490cd1f53470e629fc89016420528a6` is preserved. At takeover the Client reporting work was only staged in workflows; actual source subsequently landed in `99dd662ad6bc461d257e71c522a5e22e7c8d540d` and is preserved. No force-push or rollback of newer work was used.

## Consolidated register: all 16 items

Unclosed rows remain obligations, not completion claims. Prior implementation means the existing work is preserved; it does not certify every acceptance criterion.

| Item | Current status | Outstanding acceptance/completion |
| --- | --- | --- |
| SYNC-01 | OPEN | Persisted account/device provenance, safe legacy/ambiguous recovery, final-write/epoch fencing; actual login-to-outbox-to-receipt recovery and cross-account/device tests. |
| SYNC-02 | OPEN | Durable enqueue order and dependencies, migration/reopen/concurrent writers/backward clocks, unresolved-predecessor barriers. |
| SYNC-03 | OPEN | Equivalent read policy, permission-scoped cursor invalidation, grant bootstrap, revoked readable-cache removal and late-result fencing. |
| CAT-01 | Prior implementation preserved; acceptance not closed | All eight persisted consent combinations and strict actual PHP-to-Dart typing on all three databases. |
| INV-01 | Prior implementation preserved; acceptance not closed | Family-only and pack-normalized identity, import/read/sync consistency and dry-run idempotent reconciliation. |
| AI-01 | Prior implementation preserved; acceptance not closed | Reachable manual/unconfigured management and safe unavailable extraction through production composition. |
| AI-02 | Prior implementation preserved; acceptance not closed | Shared/private policy ownership, accurate consent recipients, reload/restart and credential/provider/revision races. |
| CAT-02 | Prior implementation preserved; integrated acceptance open | Both ordinary Admin approval/publication journeys to a second household device; curator/reviewer and consent races. |
| AI-03 | Prior implementation preserved; integrated acceptance open | Evidence decisions to durable ordinary receipt/count completion, restart/lost-response/revocation and no duplicate effects. |
| DATA-01 | Prior implementation preserved; platform acceptance open | Worker/token/retrieval to explicit save/discard, requester/scope/expiry/cleanup and actual supported platform lifecycles. |
| ADM-01 | Existing Admin slice preserved; acceptance open | Real resource 403 preserves valid session, refreshes capabilities and fences stale routes/results; revocation clears sensitive state. |
| ADM-02 | Existing Admin slice preserved; acceptance open | Pre-send/429/503/invalid/ambiguous refresh, single-flight, durable restart ambiguity and logout fences without refresh replay. |
| PAGE-01 | OPEN | Bounded reachable traversal of every named collection, 151 records each, beyond-page category selection and mutation stability. |
| INV-02 | OPEN | Explicit justified count assessment, revisioned persistence/sync and valid consumption intervals excluding partial/unassessed evidence. |
| REP-01 | Paired source now committed; integrated acceptance open | Backend `f04127a` and Client `99dd662` contain the real reporting changes. Strict actual HTTP-to-Dart timestamp acceptance across the required databases and timezones remains distinct from isolated reporting tests. |
| ERR-01 | OPEN | Semantic, truthful invalid-response/service/auth/revision/domain classification; no malformed-list empty success. |

## Separate conditional RISK-01

**Integer normalization implemented and regression-tested; full database-to-HTTP-to-Dart acceptance not closed.** Production `DbalAiStore` wiring is committed in `ed9da2642bd9b087bfeb0e923b3ae3a7789f909a`. The helper strictly normalizes known integer columns while preserving NULL key versions, missing configuration, private visibility, identifiers and arbitrary JSON. Invalid/overflowing data is rejected rather than cast to a plausible value. This remains a conditional portability risk, not a proven explanation of the original Household AI error.

Executed run [35025245142](https://github.com/providentia-systems/backend/actions/runs/35025245142): real stringifying PDO regression reproduced before the fix; complete `composer check` passed after it (**614 tests, 3643 assertions**, PHPCS, PHPStan, architecture and contracts); focused PDO/DBAL/production JsonResponse integration passed (**4 tests, 30 assertions**). Actual runtime was PHP 8.5.10 / Composer 2.10.2 / PHPUnit 12.5.33; the setup request of PHP 8.5.9 resolved to 8.5.10.

Evidence artifact [10419371711](https://github.com/providentia-systems/backend/actions/runs/35025245142/artifacts/10419371711), 90-day retention, Actions-reported SHA-256 `bdc70934f952f977be30c3e48f30ebb578855b5c90607f31863587843809c643`. The runner fast-forward pushed and verified its remote commit. Source, exact commands, failure cause, compatibility and rollback are in [ai-sql-integer-repair.md](ai-sql-integer-repair.md).

## Why the normal Backend checks temporarily failed

Commit `be3c635` added regression tests before the production store patch had been applied. Its repair job then stopped at the deliberately failing baseline because Bash's inherited `-e` was not handled. The normal suite correctly reported three failing new integration tests. `913c401` fixed the expected-failure handling without suppressing any application failure; the successful runner then published `ed9da264`. The temporary repair workflow and patch are now removed, while the production fix and tests remain.

Normal Quality, coverage, mutation, migration, security and actual HTTP conformance results on the final cleanup head are required separately. Older green checks and intermediate `action_required` statuses are not final-head evidence. The Step-2 and Step-3 HTTP lanes now pin the newer production Client `99dd662ad6bc461d257e71c522a5e22e7c8d540d` rather than old consumers, and retain their new evidence for 90 days.

## Execution constraints and release boundary

Authenticated connector publication and Actions execution are proven by the remote commits and logs above. The local execution service subsequently returned `TransportTimeoutError`, so no local test execution or persistent local checkout is claimed for this correction. Runtime-transfer artifacts are dependency setup, not application acceptance. The temporary runtime-transfer workflow is removed after successful transfer; other historical setup files are not evidence of completed features.

No fully accepted three-repository release commit set exists yet. The observed/pinned Client is `99dd662ad6bc461d257e71c522a5e22e7c8d540d`; Admin at takeover was `8cf417041b2c50cc7f4368143de0e5fcd1669e61`. Final release evidence must identify the actual Backend, Client and Admin source commits together and close every remaining row.

This integer correction requires **no migration or data rewrite** and does not alter the API 2.1.0 contract. After complete acceptance, release the compatible Backend before paired Client/Admin builds. Preserve backups and exact deployed build identifiers. Roll back compatible builds without database down-migrations, deleting pending offline intent, resetting household records or removing audit, authorization, consent and evidence guards. Rolling back the integer normalizer reintroduces driver-dependent JSON typing. Do not remove existing production hotfix overrides solely because a CI run is green. User-saved export copies remain outside application revocation.
