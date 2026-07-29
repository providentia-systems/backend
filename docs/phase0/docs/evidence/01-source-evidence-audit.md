# Phase 0 source-evidence audit

## Document status

| Field | Value |
|---|---|
| Phase | Phase 0 — evidence, decisions, and migration safety |
| Overall status | **DIRECTLY VERIFIED — PHASE 0 EVIDENCE GATE PASSED** |
| Audit date | 2026-07-29 |
| Inputs inspected | Full amended Providentia V1 master prompt and complete extracted handover |
| Phase 1 authorization | **APPROVED by the project owner on 2026-07-29** |

### Evidence labels

| Label | Meaning |
|---|---|
| **DIRECTLY VERIFIED** | Reproduced from inspected bytes, source, structured data, workbook cells, or visual review. |
| **SOURCE-DECLARED** | A source document states the fact, but it is not independently derivable from the retained artifact. |
| **PROPOSED** | A target design or control, not a current-implementation claim. |
| **DECISION REQUIRED** | A later product/security decision remains due at its recorded deadline. |

No supplied user image or workbook is copied into this review package. Repository-safe
evidence is limited to counts, digests, classifications, source facts, and
documentation. Private handover media remains outside repository content.

## Package and manifest verification

| Check | Direct result |
|---|---|
| Archive | `Pantry_Stock_Project_Handover_2026-07-29.zip` |
| Archive SHA-256 | `c96abfc9a7cdde11da2f2484e2103101ff1044c668ce5842fc350737f9375641` |
| ZIP structural test | Passed; no compressed-data errors |
| ZIP members | 208 file/directory members |
| Extracted regular files | 161 |
| Manifested files | 159, excluding the two manifest files themselves |
| `SHA256SUMS.txt` | 159/159 digests passed |
| `FILE_MANIFEST.csv` | 159/159 paths, sizes, and SHA-256 digests independently reproduced |

The extracted package root contains exactly one copy of every required source,
export, workbook, selected visual, PDF, and Git bundle named by V1.

## Source availability and result

| Source | Status | Verified result |
|---|---|---|
| Amended Providentia master prompt V1 | **DIRECTLY VERIFIED** | Read in full; architecture, precedence, privacy boundaries, phases, official name `Providentia`, and authoritative repositories retained |
| `00_START_HERE/` | **DIRECTLY VERIFIED** | Overview, 159-row digest manifest, and 159-row CSV manifest agree with the extracted bytes |
| `documentation/` | **DIRECTLY VERIFIED** | All seven documents read in full and indexed |
| `01_app_source/vdm-pantry-stock/` | **DIRECTLY VERIFIED** | React/TypeScript/Vinext source inspected; snapshot is byte-identical to bundle commit checkout |
| `app/PantryApp.tsx` | **DIRECTLY VERIFIED** | Dashboard, stock, purchases, lists, five local keys, prototype rules, and file-picker behavior confirmed |
| `03_data_exports/` | **DIRECTLY VERIFIED** | JSON/CSV schemas inspected; every V1 baseline count reproduced |
| `product-rules.json` | **DIRECTLY VERIFIED** | 13 alias groups, 19 alias strings, 19 identity rules, and eight unresolved descriptions confirmed |
| Latest inventory workbook | **DIRECTLY VERIFIED** | Six worksheets inspected; 60 current-stock rows, 16 receipt-derived purchase rows, and 452 history rows confirmed |
| Annotated consolidation workbook | **DIRECTLY VERIFIED** | 63 match-review rows: 32 explicit decisions, 28 blank decision cells, three `Not sure`; all 22 category decisions blank |
| Selected Fresh Market visual | **DIRECTLY VERIFIED** | Warm cream/forest-green phone dashboard with compact rows, rounded controls, soft shadows, explicit low-stock state, and four-item bottom navigation |
| All supplied JPEG/PNG media | **DIRECTLY VERIFIED** | 30/30 byte-inspected and visually classified; see the restricted ledger in `04-media-classification-quarantine.md` |
| `Shopping 2026.pdf` | **DIRECTLY VERIFIED** | 586 pages; pages 1–9 contain the purchase log, page 10 and sampled later pages contain print-range noise, and page 586 contains repeated `#DIV/0!` values |
| Git bundle | **DIRECTLY VERIFIED** | Bundle valid and complete; four refs and `HEAD` resolve to the expected commit |

## Source-of-truth precedence

1. Explicit decisions in the Version 1 master prompt.
2. Explicitly approved user decisions in
   `Pantry_Product_Consolidation_Review(1).xlsx`.
3. Machine-readable product rules in `product-rules.json`.
4. Current post-consolidation data in `03_data_exports/`.
5. Current application behavior in `app/PantryApp.tsx`.
6. Earlier spreadsheets and source media as lineage and evidence.

Controls:

- Earlier workbooks cannot overwrite later decisions.
- A blank decision cell is not approval.
- `Not sure` is not approval.
- Conflicts retain both values, precedence levels, source locations, and the
  chosen disposition.
- Raw purchase/receipt descriptions survive matching.
- Name-only deduplication is prohibited.

## Reproduced baseline

The handover is application version 7 at source commit:

`b01b5ef14783b4ad1c1bfc0be7ba0dba32629af8`

| Record set | V1 baseline | Independent result | Status |
|---|---:|---:|---|
| Product-and-pack entries in current item master | 292 | 292 | **PASS** |
| Current counted stock lines | 60 | 60 | **PASS** |
| Recent receipt-derived purchase lines | 16 | 16 | **PASS** |
| Historical shopping lines | 452 | 452 | **PASS** |
| Monthly-purchase summary rows | 261 | 261 | **PASS** |
| Hidden alias groups | 13 | 13 | **PASS** |
| Individual hidden aliases | 19 | 19 | **PASS** |
| Product-identity rules | 19 | 19 | **PASS** |
| Unresolved current-stock descriptions | 8 | 8 | **PASS** |
| Sum of current quantities | 159 | 159 | **PASS** |
| Lines matching legacy `quantity <= 2` rule | 44 | 44 | **PASS** |

The app-source JSON and export JSON files are byte-identical:

| File | SHA-256 |
|---|---|
| `pantry-data.json` | `ac2a74f267d7a48a460c8fae24515887f97632cddfb4a17f5f45dd07c9e90116` |
| `product-rules.json` | `8131bd3bf41c9b70f0e4cfe86c9e7de699ca0df827c6287fc9f2927e35827899` |

The eight protected unresolved descriptions remain:

1. Elbow Macaroni
2. Elbow Pasta
3. Tea
4. Candi Soda
5. Washing Powder — Sunlight
6. Washing Powder — Bio Classic
7. Insect Spray — Doom
8. Trotters Jelly

They may remain usable as private home descriptions, but no importer may
silently link, merge, rename, or globally publish them.

## Source and Git verification

`git bundle verify` reported a complete SHA-1 history. These bundle refs all
resolve to the expected handover commit:

- `refs/heads/main`
- `refs/remotes/origin/HEAD`
- `refs/remotes/origin/main`
- `HEAD`

The head commit is:

`b01b5ef14783b4ad1c1bfc0be7ba0dba32629af8 Apply reviewed product consolidation rules`

A checkout from the bundle produced no file differences from
`01_app_source/vdm-pantry-stock/` when `.git` was excluded.

Current source facts confirmed directly:

- static `pantry-data.json` and `product-rules.json` are compiled into the client;
- `db/schema.ts` is intentionally empty;
- D1 and R2 bindings are `null`;
- the portrait-first PWA manifest is not a desktop support contract;
- `page.tsx` falls back to the name `Roline`;
- the five operational keys are read from local storage;
- receipt selection stores `{id, name, addedAt}` only;
- stock photos are locally resized to a maximum 720-pixel side and JPEG quality
  0.68, then stored as data URLs;
- the low-stock predicate is `quantity <= 2`;
- suggested buy quantity is
  `max(1, ceil(April–June average - current quantity))`;
- the purchases history UI displays only the first 60 of 452 history rows;
- CSS provides visible focus and a reduced-motion media rule.

The receipt picker does not upload, retain receipt bytes, run OCR/vision, or
durably match lines. It must not be marketed as an implemented scanner.

## Workbook verification

### Latest pre-app workbook

`Pantry_Household_Inventory_Monthly_Purchases_UPDATED.xlsx` has six worksheets:

| Worksheet | Used range | Evidence |
|---|---|---|
| `Current Stock` | `A1:H61` | 60 data rows |
| `Purchases` | `A1:J17` | 16 data rows |
| `Notes` | `A1:B16` | Lineage/workflow notes |
| `Shopping History 2026` | `A1:H453` | 452 data rows |
| `Item Master` | `A1:H265` | 264 pre-consolidation data rows plus header |
| `Monthly Purchases` | `A1:O253` | Workbook-era summary layout |

The later app consolidation expands the current item master to 292 and the
machine-readable monthly export to 261. The workbook remains lineage evidence
and cannot overwrite the later rules/exports.

### Annotated consolidation review

`Pantry_Product_Consolidation_Review(1).xlsx` has:

- 63 match rows (`M01`–`M63`);
- 32 explicit decision values;
- 28 blank decision cells;
- three `Not sure` decisions (`M03`, `M04`, `M35`);
- 22 category-review rows with zero completed decision cells.

Blank and `Not sure` cells are not approvals. Applied later identities and
aliases are verified against `product-rules.json`, not inferred afresh from
blank workbook fields.

## Media and PDF findings

All 26 JPEG and four PNG files decode with MIME types matching their bytes.
Visual classification found:

- 18 private household stock photographs;
- four private grocery receipt photographs located in `Stock control`;
- four medicine-information leaflet photographs located in `Receipt photos`;
- one selected Fresh Market UI direction PNG;
- three spreadsheet/review preview PNGs.

The four medical filenames are recorded only in the restricted evidence
section of the media report. They are rejected from grocery import and must
never be uploaded to an AI provider, repository, fixture set, log, or support
export.

`Shopping 2026.pdf` has SHA-256
`b54338cb20df45eb23d27a4eb049fb379790105b6f95d9897f403cec09185dc9`.
It is an unencrypted 586-page A4 PDF. Pages 1–9 provide lineage; the verified
452-line CSV remains the operational source. Bulk OCR of all 586 pages is
prohibited.

## Phase 0 acceptance

The evidence gate is closed:

- package integrity passed;
- all required documentation was read;
- source and Git history match;
- all baseline totals were reproduced;
- workbook decisions/ambiguities were classified;
- every JPEG/PNG was structurally and visually classified;
- the PDF limitation was verified;
- exact five-key source schemas were derived;
- the project owner explicitly directed the work to proceed to Phase 1.

Browser-only operational values are intentionally not present in the handover.
Their later export and production reconciliation remain a cutover requirement,
not a Phase 0 evidence blocker.
