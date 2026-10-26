# Backend issues 33–38

This change set implements the backend work for issues #33–#38. The canonical
API remains owned here; paired Client and Admin workflows are maintained in
their own repositories.

## Implemented behavior

| Issue | Result | Evidence |
| --- | --- | --- |
| #33 | A dedicated atomic command adds an omitted purchase or resolves a nonapplied line on a committed receipt. Existing applied lines are retained. Immutable correction identities, receipt/line revisions, captured stock units and transaction rollback protect retries and concurrent edits. Audit history participates in sync and scoped exports. | Purchasing immediate-purchase and HTTP/sync integration tests, correction validator/dispatcher tests, migration/export tests and MySQL/MariaDB concurrency tests. |
| #34 | Reports and summaries share exact receipt spend semantics: declared totals take precedence, explicit discounted line totals are preserved, derived amounts round per line with HALF_UP, incomplete amounts remain explicitly partial or unknown, and currencies stay separate. | PurchaseAmounts unit tests, purchasing/report integration tests and typed runtime response validation. |
| #35 | Inventory reports distinguish absent balances, recorded zero and uncertain chronology; signed decimal quantities, unit, pending quantity, limitations and checkpoint metadata remain available. | Read-only report projection fixtures, numeric-affinity cases and current Client adapter acceptance. |
| #36 | Operator Stock resolves effective product, pack, category and status metadata without losing balance rows or raw identities. Missing/mismatched references and cross-home local categories remain safely diagnosable. | Operator product/stock projection tests, pagination and isolation fixtures, native Admin adapter acceptance. |
| #37 | Recovery guidance distinguishes server export from recoverable local outbox preservation, documents missing Client tooling and immutable operation provenance, and matches guarded stock-unit edits. | Source/route review and updated recovery, product and purchase workflow documentation. |
| #38 | Current Client and Admin acceptance uses full immutable commit pins and records actual backend/frontend SHAs and contract digests. Tests execute production transports, parsing, persistence/restart and revision-bound writes. Historical export regression remains explicitly separate. | `tests/fixtures/frontend-compatibility.json`, runtime/Step 2 lanes and `docs/deployment/frontend-compatibility.md`. |

API **2.4.0** and its verified archive/lock describe these changes. Migration
`Version20261026000100` adds correction audit storage; it does not reopen or
reapply existing receipts. Downgrade refuses to discard recorded audit history.

## Paired applications and release boundary

[Client PR #48](https://github.com/providentia-systems/client/pull/48) adopts the
matching contract, report adapters and evidence labels.
[Admin PR #22](https://github.com/providentia-systems/admin/pull/22) owns the
operator presentation and corresponding contract adoption. The checked-in
compatibility matrix is authoritative for the exact tested candidate commits.

Backend #33 exposes the finished-line correction through HTTP, synchronization,
capability readback and generated transport. The full user-facing Client
correction editor remains tracked in
[Client #43](https://github.com/providentia-systems/client/issues/43).
These backend changes do not claim that editor or a local backup/recovery
screen has shipped. Installed browser/native acceptance and recovery of any
existing household remain separate from source and CI validation.

## Validation

The local bootstrap/doctor could not provision its Docker toolchain because
the managed host rejects package-manager user switching. Supplemental checks
used PHP 8.5.12 through a portable runtime: focused receipt/sync, amount/report,
inventory/operator and chronology suites; full PHPStan; coding standards;
architecture, structural and canonical contract checks; and the locked
dependency audit. Actual serialized synthetic report responses also passed
JSON Schema validation, including negative controls for incompatible values.

Three image-materialization tests launch `PHP_BINARY` directly and cannot run
through the portable embedded runtime. The two real MySQL/MariaDB concurrency
tests require those databases. Required CI retains these tests, coverage,
mutation, image, security and database/broker gates. Frontend dependency
installation was blocked by automatic approval review locally; production
frontend checks run in GitHub CI. No gate was disabled or weakened.

PR readiness requires successful checks on the final candidate commits. A green
workflow does not deploy a backend or install a frontend binary.
