# Reproducible frontend compatibility

`tests/fixtures/frontend-compatibility.json` selects exact Client and Admin
commits for the backend revision under test. Both workflows read that file;
changing a supported candidate requires an intentional, reviewed pin update.
No acceptance checkout follows a moving branch head.

The runtime and Step 2 lanes record the actual backend SHA, frontend SHA,
canonical contract version and SHA-256 of each application's bundled contract.
`tool/record-frontend-compatibility.py` rejects a mismatched frontend checkout or
contract before the adapter tests run. Evidence contains public build identities
and synthetic test results, never session fixtures or household data.

| Lane | Production boundary exercised |
| --- | --- |
| Current Client receipt and report adapters | Real PHP HTTP through generated transport, handwritten reporting parser, local Drift repository and durable outbox; lost responses, malformed success, immutable operation replay, calendar-date metadata saves, conflict and database restart. |
| Current Client reporting and count eligibility | Missing balances versus recorded zero, signed ledger movements, declared discounted receipt totals, incomplete known subtotals, count scope/reliability through actual synchronization and local database restart; deliberately incompatible report discriminator must fail the production parser. |
| Current Client Step 2 adapters | Real HTTP catalog import, published references, home isolation, AI settings and profiles, consent and Drift projections across backend restarts; SQLite/MySQL/MariaDB. |
| Current native Admin authenticated adapters | Actual native `ApiClient`, `OperatorInventoryRepository` and record parser; revision-bound category/product writes, stale-edit rejection, signed stock metadata, transport reopening, household isolation and resource-level authorization denial. |
| Runtime HTTP response contracts | Real serialized responses checked against the unmodified canonical JSON Schema with format assertions on SQLite/MySQL/MariaDB; negative controls reject incompatible wire values. |

The earlier Client fixture `21567ad` is historical provenance only. The
`99dd662` fixture remains in the explicitly named Historical Step 3 export
regression lane. Both have been replaced in the current runtime and Step 2
acceptance lanes; historical export checks remain required but must not be
cited as current-release evidence. Generated Dart compilation alone
is a separate contract-generation proof, not an application workflow test.

## Release and installed acceptance

A green run covers exactly the recorded commits and adapter boundaries. Merge
and build the paired Client/Admin candidates with the matching backend before
calling that combination supported in an installation. Confirm the installed
binary/build identity, endpoint, contract version and migration state; a source
checkout or health response is insufficient evidence of an upgrade.

CI does not sign in to a user's installation, restore a user's device database,
revalidate their saved operations or prove household recovery. Use the
[synchronization recovery runbook](synchronization-recovery.md) for those
separate prerequisites. Installed browser checks still need real origin/cookie
isolation, CSRF and reload/restart verification; native checks need their actual
credential store, selected account/device and pending-operation continuity.
The existing backend browser-session/revalidation security tests remain required
alongside these adapter lanes. Neither a server export nor CI replaces an
unsupported local queue backup/recovery workflow.
