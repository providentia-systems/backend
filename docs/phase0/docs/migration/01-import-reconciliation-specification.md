# Import and reconciliation specification

Status: **Accepted Phase 0 specification; source baseline verified; import
execution deferred to the migration phase**

## 1. Objective

Migrate the historical `StockHome` v7 prototype evidence into the new
Providentia global catalog and a designated
staging home without losing lineage, raw descriptions, unresolved records,
pack distinctions, user decisions, or browser-local changes. The importer must
be deterministic, idempotent, resumable, dry-run capable, transaction-safe, and
auditable.

This specification defines behavior; it does not claim that an import has run.

## 2. Source precedence

Conflicts are resolved in this order:

1. Explicit decisions in the V1 master prompt.
2. Explicitly approved decisions in
   `Pantry_Product_Consolidation_Review(1).xlsx`.
3. Machine-readable rules in `product-rules.json`.
4. Post-consolidation operational exports in `03_data_exports/`.
5. Current behavior and embedded data in `app/PantryApp.tsx`.
6. Earlier spreadsheets and media as lineage evidence only.

Blank review cells, `Not sure`, and equivalent non-decisions are not approvals.
An earlier workbook may not overwrite a later approved decision.

## 3. Declared baseline and acceptance targets

These targets are declared by the V1 prompt and remain **unverified** until the
ZIP is inspected:

| Record set | Expected count |
|---|---:|
| Product-and-pack item-master entries | 292 |
| Current counted stock lines | 60 |
| Units or packs represented by current stock | 159 |
| Recent receipt-derived purchase lines | 16 |
| Historical shopping lines | 452 |
| Monthly-purchase summary rows | 261 |
| Hidden alias groups | 13 |
| Individual hidden aliases | 19 |
| Product-identity rules | 19 |
| Unresolved current-stock descriptions | 8 |

The legacy fixed rule `quantity <= 2` is declared to mark 44 of the 60 stock
lines as low. The evidence run must reproduce that count as a parity invariant.
It is not the accepted future recommendation model.

The exact unresolved descriptions are:

1. Elbow Macaroni
2. Elbow Pasta
3. Tea
4. Candi Soda
5. Washing Powder - Sunlight
6. Washing Powder - Bio Classic
7. Insect Spray - Doom
8. Trotters Jelly

They must enter quarantine or remain linked to an explicit unresolved private
identity. The importer must not guess their canonical product, variant, pack,
or alias.

## 4. Evidence intake

### 4.1 Safe extraction

Use a fresh, explicit temporary directory. Reject:

- absolute archive paths;
- `..` traversal;
- symlinks or hard links escaping the extraction root;
- duplicate normalized paths;
- device files, sockets, or unsupported special entries;
- unexpectedly large expansion ratios.

Preserve the original ZIP unchanged and record its SHA-256 separately.

### 4.2 Required verification

From the extraction root, validate manifest paths before asking
`sha256sum` to resolve them, use strict checksum parsing, prove that the
expected commit exists in the verified bundle, and compare the supplied source
tree with an archive of that exact commit:

```bash
set -Eeuo pipefail

readonly EXPECTED_COMMIT='b01b5ef14783b4ad1c1bfc0be7ba0dba32629af8'
readonly CHECKSUM_FILE='00_START_HERE/SHA256SUMS.txt'
readonly SOURCE_TREE='01_app_source/vdm-pantry-stock'
readonly SOURCE_BUNDLE='06_git_history/vdm-pantry-stock.git.bundle'
readonly VERIFY_ROOT="$(mktemp -d /tmp/providentia-source-verify.XXXXXX)"

cleanup()
{
	rm -rf -- "${VERIFY_ROOT}"
}
trap cleanup EXIT

export CHECKSUM_FILE
python3 - <<'PY'
import os
import pathlib
import re

root = pathlib.Path.cwd().resolve()
manifest = pathlib.Path(os.environ["CHECKSUM_FILE"])
line_pattern = re.compile(r"^[0-9a-fA-F]{64} [ *](.+)$")

for number, line in enumerate(manifest.read_text(encoding="utf-8").splitlines(), 1):
    if not line:
        continue
    match = line_pattern.fullmatch(line)
    if match is None:
        raise SystemExit(f"Invalid checksum line {number}")
    relative = pathlib.PurePosixPath(match.group(1))
    if relative.is_absolute() or ".." in relative.parts:
        raise SystemExit(f"Unsafe checksum path on line {number}")
    candidate = (root / pathlib.Path(*relative.parts)).resolve()
    if candidate != root and root not in candidate.parents:
        raise SystemExit(f"Checksum path escapes extraction root on line {number}")
PY

sha256sum --check --strict --warn "${CHECKSUM_FILE}"
git bundle verify "${SOURCE_BUNDLE}"
git clone --quiet "${SOURCE_BUNDLE}" "${VERIFY_ROOT}/bundle-repository"
git -C "${VERIFY_ROOT}/bundle-repository" \
	cat-file -e "${EXPECTED_COMMIT}^{commit}"
git -C "${VERIFY_ROOT}/bundle-repository" \
	archive --format=tar --prefix='vdm-pantry-stock/' "${EXPECTED_COMMIT}" \
	| tar -xf - -C "${VERIFY_ROOT}"

diff -qr --exclude='.git' \
	"${SOURCE_TREE}" "${VERIFY_ROOT}/vdm-pantry-stock"

if git -C "${SOURCE_TREE}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	test "$(git -C "${SOURCE_TREE}" rev-parse HEAD)" = "${EXPECTED_COMMIT}"
	test -z "$(git -C "${SOURCE_TREE}" status --porcelain=v1 --untracked-files=all)"
fi
```

The `cat-file` check must accept this exact commit:

```text
b01b5ef14783b4ad1c1bfc0be7ba0dba32629af8
```

Any checksum failure is a hard stop. Do not selectively continue with failed
files.

### 4.3 Evidence manifest

Create `evidence-manifest.json` containing:

- archive SHA-256;
- file-relative path;
- byte size;
- file SHA-256;
- detected MIME type from bytes;
- declared extension;
- classification state;
- source section;
- privacy class;
- import eligibility;
- inspection timestamp and tool version.

Do not store image pixels, receipt text, medical content, or secrets in logs.

## 5. Import-run model

Each execution has an immutable `import_run_id`, source archive digest,
importer version, configuration digest, start/end time, mode, and status.

Supported modes:

- `verify`: checks evidence only;
- `dry-run`: parses, normalizes, matches, and reports without domain writes;
- `stage`: writes to an isolated staging home and unpublished catalog revision;
- `commit`: promotes an approved staged run;
- `resume`: continues a failed or paused run from a durable checkpoint;
- `reconcile`: recomputes reports without rematching approved mappings.

An import item is keyed by:

```text
(source_archive_sha256, source_dataset, source_row_identity, importer_schema_version)
```

Re-running the same item must return its existing outcome. It must not create a
second product, receipt line, price observation, stock movement, or alias.

## 6. Source-row envelope

Every parsed row is retained as an immutable source envelope with:

- source dataset and file path;
- worksheet/table name when applicable;
- row identity and original order;
- raw values exactly as supplied;
- normalized values used for matching;
- source date and locale interpretation;
- parse warnings;
- decision-source precedence;
- match candidates and scores;
- selected destination identity;
- decision type: deterministic, approved-source, user-approved, or unresolved;
- destination IDs after commit;
- import status and error code.

Raw purchase descriptions and original pack text are never discarded.

## 7. Import order and transaction boundaries

### Stage 1: reference data

Import categories and units. Natural names may be candidate keys, but permanent
IDs are generated once and recorded in source mappings. Unit conversions
require dimension compatibility and an explicit factor.

### Stage 2: catalog identities

Import canonical products, meaningful variants, and distinct packs. Apply all
approved identity rules. Store original pack text and normalized base-unit data
separately.

### Stage 3: aliases and rules

Import approved hidden aliases and identity rules. Alias scope is explicit:
global only when approved and sanitized; otherwise private to the staging home.
No alias is promoted from raw receipt text merely because it appears often.

### Stage 4: stores

Create normalized store identities while retaining original store text on each
receipt. Store-name similarity alone must not merge legally or geographically
distinct stores.

### Stage 5: historical purchases

Import the expected 452 historical lines. Group only where source evidence
supports a receipt or shopping event. Preserve date precision and ambiguity.
Unmatched lines remain importable as unresolved private purchase lines.

### Stage 6: recent purchases

Import the expected 16 recent receipt-derived lines. Their source status must
remain distinct from a production OCR scan; the current PWA retained filenames
only and did not retain or upload receipt bytes.

### Stage 7: opening count

Create one explicit opening stock-count session for the staging home. Import
the expected 60 lines and 159 total units/packs as observations. Closing the
approved opening count creates idempotent opening movements. Unresolved lines
remain unresolved and are not forced into global identities.

### Stage 8: validation summaries

The expected 261 monthly summary rows are validation evidence only. Recalculate
monthly summaries from the 452 source purchase lines where possible. Do not
import summary rows as additional purchases.

Each stage commits by bounded transaction or checkpoint. A failure rolls back
the current bounded transaction, not previously verified checkpoints. Promotion
from staged to committed data is separately approved and atomic where required.

## 8. Matching algorithm

Apply this order:

1. exact barcode/GTIN;
2. exact canonical product and pack;
3. exact approved alias;
4. deterministic normalized text match;
5. deterministic candidate scoring using brand, variant, unit, and pack;
6. optional AI suggestion, recorded only as a candidate;
7. explicit human decision.

Normalization may standardize case, Unicode form, whitespace, punctuation,
hyphens, and safe singular/plural forms. It may not erase meaningful brand,
variant, flavour, formulation, pack, or unit distinctions.

Hard incompatibilities receive no automatic match:

- incompatible unit dimensions;
- incompatible packs without an approved conversion;
- identity-rule conflicts;
- meaningful variant differences;
- conflicting exact barcodes;
- ambiguous scores;
- any of the eight protected unresolved descriptions.

AI may suggest but never merge, resolve, or publish automatically.

## 9. Required reconciliation reports

### 9.1 Machine-readable report

`reconciliation.json` must include:

- schema version;
- archive and importer digests;
- run status and mode;
- expected, parsed, accepted, quarantined, rejected, and committed counts;
- source-to-destination mappings;
- count deltas with reason codes;
- duplicate candidate groups;
- unresolved and ambiguous rows;
- identity-rule application results;
- alias group and alias counts;
- stock-line count and quantity sum;
- purchase line counts;
- recalculated versus supplied monthly summaries;
- media classification totals;
- browser-local import status;
- database-specific validation results;
- warnings, errors, and approval requirements.

### 9.2 Human-readable report

`reconciliation.md` presents the same facts with:

- a pass/fail gate table;
- every unexplained difference;
- affected source rows;
- proposed corrective action;
- the person and timestamp approving any accepted difference.

### 9.3 Destination map

`source-destination-map.ndjson` provides one record per source row, suitable
for streaming and independent verification. It includes source identity,
destination IDs, match method, decision authority, and status.

### 9.4 Quarantine report

`quarantine.ndjson` records unresolved, conflicting, invalid, sensitive, or
non-importable items without their sensitive byte payloads. The source
evidence remains preserved separately.

## 10. Reconciliation gates

| Gate | Pass condition |
|---|---|
| Checksums | Every manifest entry passes |
| Source commit | Exact expected commit reproduced |
| Item master | 292 entries accounted for, with any structural split/merge explained |
| Opening stock | 60 lines accounted for |
| Opening quantity | Sum is exactly 159 units/packs |
| Recent purchases | 16 lines accounted for |
| Historical purchases | 452 lines accounted for |
| Monthly validation | 261 supplied rows compared to recalculated results |
| Aliases | 13 groups and 19 aliases accounted for |
| Identity rules | All 19 preserved and test-covered |
| Unresolved stock | All eight remain explicit and data-bearing |
| Legacy low-stock parity | `quantity <= 2` reproduces 44 of 60 lines as a legacy-only check |
| Media | Every file byte-classified; medicine leaflets quarantined |
| Browser-local data | Every relevant browser export imported or explicitly declared absent |
| Idempotency | Second run creates zero new domain records or movements |
| Portability | Reconciliation passes on SQLite, MySQL, and MariaDB |

No gate may be waived without a written exception containing the evidence,
impact, approver, and rollback path.

## 11. `Shopping 2026.pdf`

Direct inspection confirms that this is an unencrypted 586-page spreadsheet
print export. Pages 1–9 contain the purchase log, page 10 and sampled page 100
contain placeholder/print-range noise, and page 586 contains repeated
`#DIV/0!` output.

Rules:

- use the verified 452-line CSV as the operational source;
- use PDF pages 1–9 only for lineage checks;
- do not OCR all 586 pages;
- do not create purchase lines from later-page noise;
- bind the lineage check to PDF SHA-256
  `b54338cb20df45eb23d27a4eb049fb379790105b6f95d9897f403cec09185dc9`.

## 12. Media migration

All media begins quarantined and non-importable. Classification uses file
signatures, safe decoder limits, metadata inspection, and visual review. A
folder named `Receipt photos` is not evidence that its files are receipts.

The four medicine-information leaflet images described by the prompt must:

- remain quarantined;
- never be sent to an AI provider;
- never enter receipt or fixture data;
- have their actual filenames recorded privately only after inspection;
- require explicit user confirmation before any deletion decision.

Production fixtures use synthetic or properly redacted media only.

## 13. Browser-local recovery

Collect and validate exports for:

- `pantry-counts`;
- `pantry-receipts`;
- `pantry-stock-photos`;
- `pantry-manual-list`;
- `pantry-list-checks`.

An export must carry schema version, origin identifier, export time, records,
and a whole-document digest. Import it only into the staging home, reconcile it
against the ZIP baseline, and obtain approval before retirement of the PWA.
Do not clear browser data or uninstall the old PWA first.

## 14. Database portability

Run the same importer and reconciliation suite on:

- SQLite for demonstration and automated tests;
- a supported MySQL release;
- a supported MariaDB release.

Tests cover UUID representation, Unicode/collation, JSON behavior, decimals,
timestamps and time zones, unique constraints, foreign keys, index lengths,
transaction isolation, concurrency, and rollback. SQLite-only success is not a
release gate.

## 15. Security and privacy

- The importer runs with least-privilege credentials.
- Source evidence is read-only.
- Logs contain IDs and reason codes, not receipt text, image contents,
  credentials, medical content, or AI keys.
- Temporary plaintext is access-restricted and removed through a documented,
  recoverable process after approval.
- Import reports containing private data are home-scoped and access-controlled.
- Queue messages reference authoritative import records and do not copy media.
- Import promotion and exception approval are audited.

## 16. Required tests

- archive traversal and decompression-bomb rejection;
- checksum failure hard stop;
- malformed CSV/JSON/workbook row quarantine;
- Unicode and Namibian locale/date/decimal cases;
- exact and ambiguous product matching;
- all 19 identity rules;
- all eight protected unresolved descriptions;
- duplicate import and resume after failure;
- lost response after committed batch;
- second-run zero-delta assertion;
- monthly-summary recalculation;
- opening stock 60-line and 159-total assertion;
- legacy `quantity <= 2` parity assertion producing exactly 44 low-stock lines;
- no OCR beyond permitted PDF lineage pages;
- sensitive media rejection;
- source-map completeness;
- SQLite/MySQL/MariaDB equivalence;
- report schema validation and deterministic ordering.

## 17. Approval sequence

1. Evidence verification passes.
2. Dry-run reports are reviewed.
3. Unresolved and count deltas are decided or explicitly quarantined.
4. Stage import completes.
5. Source-to-destination reconciliation is approved.
6. Browser-local exports are incorporated.
7. Staged home is functionally reviewed.
8. Commit/promotion is authorized.
9. Post-commit reconciliation and backup are verified.
10. Only then may legacy retirement begin.
