# Phase 0 data dictionary and identity evidence

## Document status

| Field | Value |
|---|---|
| Overall status | **PROPOSED TARGET DICTIONARY; SOURCE FIELDS VERIFIED** |
| Evidence basis | Version 1 master implementation prompt |
| Source schemas inspected | Yes; JSON, CSV headers/rows, source types, both authoritative workbooks, and local-storage serialization |
| Identifier strategy | **PROPOSED:** UUIDv7 or another consistently applied globally unique, sortable identifier; client-created IDs survive sync |

This document distinguishes the proposed Providentia target model from the
verified historical source schemas. Target table/field names may be refined
during implementation, but ownership, raw-evidence preservation, auditability,
and tenant boundaries are mandatory.

## Ownership and classification vocabulary

| Scope | Owner/controller | Access boundary | Examples |
|---|---|---|---|
| **GLOBAL CATALOG** | Platform catalog, governed by curator/reviewer policy | Readable as product catalog; writable only through catalog permissions and moderation | canonical products, packs, sanitized aliases, icons |
| **HOME PRIVATE** | A home | Active membership plus operation-specific home role; platform role alone grants no access | stock, receipts, prices, lists, notes, AI settings |
| **ACCOUNT PRIVATE** | An individual user | Authenticated user and narrowly scoped platform processes | profile, devices, sessions |
| **PLATFORM OPERATIONS** | Platform operator | Narrow operational role; no implicit home-data visibility | queue state, schema metadata, global audit controls |
| **DEVICE LOCAL** | Originating user/device | Device security boundary; synchronized only under an explicit contract | original images by default, pending local operations |
| **DERIVED / REBUILDABLE** | Same owner as its source facts | Same boundary as source data | inventory balance, suggestion output |

Rules:

- Global catalog data must never contain home identity, household quantity, private price, receipt number, raw receipt image, private note, or AI credential.
- Home-private tables carry `home_id` wherever applicable, use compound indexes beginning with `home_id`, and use constraints that resist accidental cross-home joins.
- Platform administrators do not receive implicit home access.
- Support access is user-granted, time-limited, narrowly scoped, visible, revocable, and audited.
- Local media references do not imply that the corresponding bytes are durable or available on another device.

## Source record sets and target disposition

Status: counts are **DIRECTLY VERIFIED** against JSON and CSV exports.

| Source record set | Declared count | Proposed target concepts | Migration treatment |
|---|---:|---|---|
| Product-and-pack entries | 292 | `products`, `product_variants`, `product_packs`, `home_products` where private | Preserve source row ID and raw values; classify canonical/variant/pack without name-only deduplication |
| Current counted stock lines | 60 | opening `stock_count_session`, `stock_count_lines`, resulting `stock_movements`, `inventory_balances` | Import as one explicit opening count; preserve observed value and pack |
| Counted units or packs | 159 | sum of opening observed quantities | Reconcile exact total without combining incompatible packs |
| Recent receipt-derived purchase lines | 16 | `receipts`, `receipt_lines`, matches, price observations, approved stock-in movements where evidence permits | Preserve raw printed description; do not imply receipt bytes exist |
| Historical shopping lines | 452 | receipts/purchase history and price observations as supported by source | Preferred operational source over the 586-page PDF |
| Monthly-purchase summary rows | 261 | validation dataset only | Recalculate from source lines and compare; do not import as authoritative transactions |
| Hidden alias groups | 13 | alias-group lineage or grouped review evidence; normalized `product_aliases` | Preserve grouping evidence and approval provenance |
| Individual hidden aliases | 19 | `product_aliases` | Preserve alias text, scope, approval status, canonical/pack link, and provenance |
| Product-identity rules | 19 | `product_identity_rules` | Import all rules with stable identifiers, version, provenance, and deterministic execution semantics |
| Unresolved current-stock descriptions | 8 | quarantined import rows and/or private unresolved `home_products` | Must remain unresolved until evidence or explicit decision resolves them |

## Target entity dictionary

All entity definitions below are **PROPOSED**, based on required V1 concepts.

### Identity and tenancy

| Entity | Scope | Key fields and relationships | Source-of-truth / invariants |
|---|---|---|---|
| `users` | **ACCOUNT PRIVATE** | `id`, normalized login identifier(s), status, timestamps | Authentication principal; no active-home assumption in the record |
| `user_profiles` | **ACCOUNT PRIVATE** | `user_id`, display name, locale, timezone, preferences | One profile per user; display name never substitutes for authentication |
| `devices` | **ACCOUNT PRIVATE** | `id`, `user_id`, device label/platform, public key or token metadata, last seen, revoked at | Revocable; secrets/tokens stored only in protected form |
| `homes` | **HOME PRIVATE** | `id`, name, default locale/currency/timezone, lifecycle state | Tenant root; export/deletion/ownership operations audited |
| `home_memberships` | **HOME PRIVATE** | `home_id`, `user_id`, role, state, joined at, revision | Unique active membership per user/home; every home operation rechecks membership and policy |
| `home_invitations` | **HOME PRIVATE** | `id`, `home_id`, inviter, intended recipient, role, token hash, expiry, state | Single-use, expiring, revocable; acceptance cannot escalate role |
| `auth_sessions` | **ACCOUNT PRIVATE** | `id`, `user_id`, device/session binding, issued/expiry/revoked timestamps | Secure server-side lifecycle; supports global or device-specific revocation |
| `support_access_grants` | **HOME PRIVATE** | `id`, `home_id`, grantor, grantee/support principal, exact scope, start/end, revoked at | Explicit, visible, time-bound, revocable, audited; no standing implicit access |

### Global catalog

| Entity | Scope | Key fields and relationships | Source-of-truth / invariants |
|---|---|---|---|
| `categories` | **GLOBAL CATALOG** | `id`, parent ID, canonical name, icon, status, revision | Hierarchical category identity; merge/version history retained |
| `units` | **GLOBAL CATALOG** | `id`, symbol/name, dimension, base conversion metadata, precision | Conversions occur only within compatible dimensions |
| `products` | **GLOBAL CATALOG** | `id`, canonical family name, category, brand where identity requires it, status, revision | One permanent canonical identity per real product family |
| `product_variants` | **GLOBAL CATALOG** | `id`, `product_id`, meaningful variant attributes, canonical label, status | Meaningful distinctions remain separate; variant rules are explicit |
| `product_packs` | **GLOBAL CATALOG** | `id`, product/variant ID, original pack text, amount, `unit_id`, normalized base amount, multiplicity, status | Original pack text is immutable evidence; incompatible packs never combine implicitly |
| `product_aliases` | **GLOBAL CATALOG** or **HOME PRIVATE** | `id`, scope, optional `home_id`, raw alias, normalized alias, canonical product/variant/pack target, approval provenance, status | Sanitized approved aliases may be global; raw/private receipt terms remain home-private |
| `product_barcodes` | **GLOBAL CATALOG** | `id`, barcode/GTIN, target pack/variant, issuer/type, verification status | Exact identifier match has highest precedence; conflicts enter review |
| `product_identity_rules` | **GLOBAL CATALOG** | `id`, stable rule key, version, match dimensions, deterministic action, provenance, status | All 19 present rules retained; AI cannot silently override or merge |
| `catalog_icons` | **GLOBAL CATALOG** | `id`, product/category link, asset digest, media metadata, licence/provenance, status | Generic only; sanitized, validated, non-private |
| `catalog_proposals` | **GLOBAL CATALOG MODERATION** | `id`, sanitized proposed identity/pack/alias data, proposer pseudonymous/system reference as policy permits, state, review metadata | No home identity, price, quantity, receipt number, image, or private notes |
| `catalog_revisions` | **GLOBAL CATALOG** | `id`, entity type/ID, before/after or patch, actor, reason, timestamp | Append-only revision/audit history |
| `catalog_merge_events` | **GLOBAL CATALOG** | `id`, survivor ID, merged IDs, preview, actor, reason, reversal metadata | Merge re-links all affected records and must not orphan history; reversible by design |

### Home inventory

| Entity | Scope | Key fields and relationships | Source-of-truth / invariants |
|---|---|---|---|
| `home_locations` | **HOME PRIVATE** | `id`, `home_id`, name, type, status | Pantry, fridge, freezer, shelf, or other user-defined location |
| `home_products` | **HOME PRIVATE** | `id`, `home_id`, optional global product/variant/pack IDs, private identity data, preference/status | Makes unknown products usable immediately without forced global publication |
| `stock_count_sessions` | **HOME PRIVATE** | `id`, `home_id`, location, state, opened/closed by/at, idempotency key, notes | Explicitly opened/closed; one or more local media references; closure is audited |
| `stock_count_lines` | **HOME PRIVATE** | `id`, `home_id`, session, home product/pack, observed original amount/unit, normalized amount, confirmation state, confidence, unresolved reason | Original observation survives; possible multi-photo duplicates identified before close |
| `stock_movements` | **HOME PRIVATE** | `id`, `home_id`, home product/pack, location, movement type, original and base amounts, reason, source type/ID, effective time, reversal link, client operation ID | Auditable inventory source of truth; append-only except controlled reversals/tombstones |
| `inventory_balances` | **HOME PRIVATE; DERIVED** | `home_id`, home product/pack, location, projected quantity, revision, rebuilt at | Rebuildable from movements and closed count facts; not authoritative history |
| `stock_threshold_preferences` | **HOME PRIVATE** | `id`, `home_id`, product/category scope, desired coverage/minimum, effective dates, actor | Input to recommendations, not a universal `quantity <= 2` rule |

### Purchases and pricing

| Entity | Scope | Key fields and relationships | Source-of-truth / invariants |
|---|---|---|---|
| `stores` | **HOME PRIVATE** by default; future sanitized global store catalog only by separate design | `id`, `home_id`, display name, location/reference metadata | Avoid leaking shopping patterns through a global scope |
| `receipts` | **HOME PRIVATE** | `id`, `home_id`, store, purchase date, receipt number, currency, totals, state, raw-source metadata, idempotency fingerprint | Commit only after review; reprocessing cannot duplicate stock |
| `receipt_lines` | **HOME PRIVATE** | `id`, `home_id`, receipt, sequence, raw printed description, quantity, raw pack, unit price, line total, notes, approval state | Raw text is never discarded after canonical matching |
| `receipt_line_matches` | **HOME PRIVATE** | `id`, `home_id`, receipt line, candidate/approved product and pack, method, score, actor, decision, decision time | Match provenance retained; human decision follows AI suggestion |
| `price_observations` | **HOME PRIVATE** | `id`, `home_id`, store, product/pack, receipt line, original price/currency/unit, normalized comparison value, observed at | Remains private unless a future separately consented anonymized-sharing design is approved |
| `discounts` | **HOME PRIVATE** | `id`, `home_id`, receipt or line, type, raw label, amount/rate | Preserves printed evidence and allocation semantics |
| `tax_observations` | **HOME PRIVATE** | `id`, `home_id`, receipt or line, tax type/rate/amount, raw label | Observational data; locale-specific interpretation is explicit |
| `ai_extraction_runs` | **HOME PRIVATE** | `id`, `home_id`, use case, provider/model, prompt/schema versions, timing, confidence/warnings, structured-result reference, correction summary, cost metadata | No original image by default, no credentials, no hidden chain-of-thought; output is never a committed transaction |

### Lists and intelligence

| Entity | Scope | Key fields and relationships | Source-of-truth / invariants |
|---|---|---|---|
| `shopping_lists` | **HOME PRIVATE** | `id`, `home_id`, title/state, owner/creator, dates, revision | Offline-capable and home-scoped |
| `shopping_list_lines` | **HOME PRIVATE** | `id`, `home_id`, list, product/private text, source manual/suggestion, editable quantity/unit, checked state, rank, revision | Manual and suggested origins remain distinguishable |
| `shopping_suggestion_runs` | **HOME PRIVATE** | `id`, `home_id`, model/rule version, evidence window, run time, status | Reproducible run context; no universal prototype threshold |
| `suggestion_explanations` | **HOME PRIVATE** | `id`, `home_id`, run/line, evidence signals, confidence, limitations, human-readable explanation | Every suggestion is explainable, including weak evidence |
| `user_suggestion_feedback` | **HOME PRIVATE** | `id`, `home_id`, suggestion, user response/correction, timestamp | Feedback must not leak across homes |
| `recipes` | **FUTURE / UNDECIDED** | Not imported in initial model | Nextcloud Cookbook remains the current recipe source of truth |
| `recipe_ingredients` | **FUTURE / UNDECIDED** | Not imported in initial model | Design only with deliberate recipe integration |
| `menu_plans` | **FUTURE / UNDECIDED** | Not imported in initial model | Design only with deliberate recipe integration |

### Synchronization, audit, and delivery

| Entity | Scope | Key fields and relationships | Source-of-truth / invariants |
|---|---|---|---|
| `client_operations` | **HOME PRIVATE** where applicable | operation ID, device, user, `home_id`, command type, payload schema version/digest, state, result reference | Globally unique idempotency key; replay yields same domain result |
| `change_log` | **HOME PRIVATE** where applicable | sequence/revision, `home_id`, entity/ID, operation, changed fields, timestamp | Pull feed is tenant-filtered and ordered |
| `sync_cursors` | **ACCOUNT/HOME PRIVATE** | device/user/home, last acknowledged sequence, schema version, updated at | Cursor is scoped; cannot be reused to read another home |
| `record_tombstones` | Same as deleted record | entity/ID, `home_id`, deletion revision/time, actor/reason | Propagates deletion without erasing audit history |
| `audit_events` | Same privacy scope as event subject | actor, action, target, `home_id`, result, reason, timestamp, request/correlation metadata | Append-only, redacted, access-controlled; no images, keys, tokens, receipt/medical content |
| `outbox_events` | Same scope as originating transaction | event ID/type/version, aggregate, `home_id`, payload, state, attempts, available at | Written atomically with domain transaction; broker delivery is not database commitment |

## Canonical identity rules

Status: **DIRECTLY VERIFIED** from the 19 objects in `product-rules.json`.

The following 19 distinctions must be retained exactly in intent. Their stable rule identifiers, conditions, priority, and affected source rows must be taken from `product-rules.json` after checksum verification; none may be invented here.

| Rule no. | Required identity distinction | Migration/control implication |
|---:|---|---|
| 1 | Whole mushrooms versus pieces and stems | Separate meaningful forms; do not alias them into one interchangeable pack |
| 2 | Elbow versus straight macaroni | Shape is identity-relevant; the two unresolved elbow descriptions remain unresolved pending evidence |
| 3 | Bokomo versus Bakpro vetkoek flour | Brand/range distinguishes the product |
| 4 | Tea bags versus loose leaf, tea type, and flavour | Form, tea type, and meaningful flavour remain distinguishable |
| 5 | All Gold versus generic tomato sauce | Branded product must not collapse into a generic family without an explicit relation |
| 6 | Stain-remover brand and powder/liquid form | Brand and physical form are identity-relevant |
| 7 | Thin versus thick bleach | Viscosity/form is a meaningful variant |
| 8 | Creamstyle versus whole-kernel canned corn | Preparation/form remains distinct |
| 9 | Mild, hot, and extra-hot chakalaka | Heat level remains a meaningful variant |
| 10 | Basmati versus other rice | Rice type remains distinct |
| 11 | Brown, dark-brown, and light-brown sugar | These sugar types remain distinct |
| 12 | Instant-maize-porridge flavour | Flavour remains a meaningful variant |
| 13 | Ground, instant, and bean coffee plus meaningful brand/range | Form and meaningful brand/range remain distinct |
| 14 | Oros flavour | Flavour remains a meaningful variant |
| 15 | Candi Soda flavour | Flavour remains meaningful; unresolved `Candi Soda` is not guessed |
| 16 | Automatic versus handwash washing powder | Use mode remains distinct; unresolved washing-powder descriptions are not guessed |
| 17 | Crawling, flying, and multi-insect spray | Target/use type remains distinct; unresolved Doom description is not guessed |
| 18 | Jelly flavour | Flavour remains meaningful; unresolved Trotters Jelly is not guessed |
| 19 | Long-life, fresh, and non-dairy cream | Storage/type distinction remains meaningful |

### Matching order

Status: **VERIFIED AMENDED V1 REQUIREMENT; REQUIRED CONTROL**.

1. Exact barcode or GTIN.
2. Exact canonical product and pack.
3. Exact approved alias.
4. Normalized match after punctuation, spacing, hyphen, and singular/plural cleanup.
5. Deterministic candidate scoring using brand, variant, unit, and pack.
6. Optional AI-assisted candidate suggestion.
7. Human decision.

AI must never silently merge catalog records.

### Pack and value preservation

- One real product has one permanent canonical identity.
- Receipt, packet, and everyday wording become hidden aliases only after the applicable approval.
- Pack sizes and meaningful variants remain distinct.
- Original quantity, unit, pack text, printed description, and source-row identifier are preserved.
- Normalized base-unit values are stored separately from original values.
- Incompatible units or packs are never added without an explicit verified conversion.
- Global aliases contain only sanitized catalog data; raw receipt wording remains home-private until separately approved.

## Eight unresolved descriptions

Status: **DIRECTLY VERIFIED — RESOLUTION PROHIBITED WITHOUT EVIDENCE OR EXPLICIT USER DECISION**.

| Raw description | Current disposition | Prohibited automatic inference |
|---|---|---|
| Elbow Macaroni | Quarantine unresolved/private | Do not assume it is the same as Elbow Pasta or straight macaroni |
| Elbow Pasta | Quarantine unresolved/private | Do not assume it is the same as Elbow Macaroni |
| Tea | Quarantine unresolved/private | Do not infer bags/leaf, tea type, flavour, brand, or pack |
| Candi Soda | Quarantine unresolved/private | Do not infer flavour |
| Washing Powder - Sunlight | Quarantine unresolved/private | Do not infer automatic/handwash, pack, or form beyond raw wording |
| Washing Powder - Bio Classic | Quarantine unresolved/private | Do not infer automatic/handwash, brand relationship, pack, or form |
| Insect Spray - Doom | Quarantine unresolved/private | Do not infer crawling/flying/multi-insect type |
| Trotters Jelly | Quarantine unresolved/private | Do not infer flavour or pack |

## Migration order and reconciliation contract

Status: **VERIFIED MIGRATION ORDER; IMPORT IMPLEMENTATION BELONGS TO LATER PHASES**.

1. Categories and units.
2. Canonical products.
3. Variants and packs.
4. Aliases and identity rules.
5. Stores.
6. Historical purchases.
7. Recent purchases.
8. Opening stock-count session and stock lines.
9. Monthly summaries as validation only.

The importer must be idempotent, resumable, dry-run capable, transaction-safe, explicit about conflicts, able to emit JSON and human-readable reports, able to map every source row to its destination ID, and able to quarantine unresolved rows without data loss.

Minimum reconciliation record:

| Field | Meaning |
|---|---|
| `run_id` | Unique dry-run/import attempt |
| `source_package_sha256` | Digest of the verified ZIP |
| `source_file_sha256` | Digest of the exact source file |
| `source_record_type` | Stable record-set key |
| `source_row_reference` | Reproducible row/key reference without discarding the raw record |
| `raw_record_digest` | Digest proving which raw values were handled |
| `precedence_level` | 1–6 under the source-of-truth order |
| `decision_reference` | Explicit review/rule reference, when applicable |
| `target_entity_type` / `target_id` | Destination mapping, absent only for quarantine/rejection |
| `action` | inserted, matched, unchanged, quarantined, conflicted, or rejected |
| `reason_code` | Machine-readable disposition |
| `warnings` | Structured non-fatal issues |

## Source-verification gate

Phase 0 source verification completed:

- actual CSV/JSON headers and data types inspected;
- all 19 machine-readable rules mapped;
- authoritative workbook cells and decision states inspected;
- every declared baseline count reproduced;
- all eight unresolved descriptions preserved without inference;
- exact local-storage serialization derived from source;
- proposed entities cross-checked with the target schema draft;
- workbook/export lineage differences recorded without altering evidence.
