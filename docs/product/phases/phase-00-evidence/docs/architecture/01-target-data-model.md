# Target data model

**Status:** Phase 0 contract draft

**Authority:** `home_stock_control_master_implementation_prompt_V1.md`

**Purpose:** Define ownership, tenancy, history, and source-of-truth boundaries before schema implementation.

This is a logical model, not a Doctrine mapping or a database migration. Physical
column types, index names, retention durations, and partitioning remain Phase 1
design work. Nothing in this document changes the source-evidence precedence or
authorizes migration of unverified media.

## Model-wide rules

- Use one globally unique, client-generatable, time-sortable identifier strategy,
  preferably UUIDv7, for entity identifiers. A client-created identifier survives
  synchronization.
- Store timestamps as UTC instants. Preserve a source-local date, currency, unit,
  time zone, and raw text wherever normalization would otherwise lose evidence.
- Private operational records carry an immutable `home_id`. A client may identify
  the home it wants to operate in, but the server derives authorization from the
  authenticated identity, device session, current membership, and requested
  object. A submitted `home_id` is never authorization.
- Foreign keys between private records include or are validated against the same
  `home_id`. Repositories require the tenant scope explicitly. Cross-home
  references are prohibited.
- Global catalog records never contain a `home_id`, receipt number, home identity,
  quantity, private note, private image, or home price.
- Original purchase descriptions, pack text, quantities, and prices are retained
  alongside normalized values. Name-only deduplication is forbidden.
- Mutable aggregates have a monotonically increasing integer `revision`. API
  updates use optimistic concurrency. Append-only facts use unique source/action
  keys instead of in-place mutation.
- Deletion of auditable facts is a reversal, revocation, expiry, or tombstone, not
  physical history erasure. Account/home erasure must preserve only the minimum
  legally or operationally required audit evidence, pseudonymized where possible.
- Retention periods are policy inputs that must be approved before production.
  This draft therefore uses policy events rather than inventing durations.

## Ownership scope vocabulary

| Scope | Meaning |
|---|---|
| `global` | Shared catalog fact visible across homes; contains no private tenant data. |
| `home` | Private tenant data; an immutable `home_id` is required. |
| `user` | Private account data; owned by one user and not inherited through home membership. |
| `device` | Per-device/session state bound to a user; no implicit home access. |
| `platform` | Operational or privileged platform state. Home access is not implied. |

When a row can record events in more than one scope, the scope is explicit and a
database constraint requires exactly the permitted owner key. Such polymorphic
operational tables must not be used to bypass module-owned repositories.

## Identity and tenancy

| Entity | Module | Ownership | `home_id` rule | Source of truth | Retention/deletion | Relations and constraints |
|---|---|---|---|---|---|---|
| `users` | Identity | user | Forbidden | Server identity record | Disable first; erase or pseudonymize through the approved account-deletion workflow. Security/audit holds remain policy controlled. | Unique normalized email; password hash is never returned; one-to-one optional profile; one-to-many devices, sessions, and memberships. |
| `user_profiles` | Identity | user | Forbidden | User-confirmed profile | Removed with the user except required pseudonymized audit evidence. | Exactly one per user; display name and locale are not authentication identifiers. |
| `devices` | Identity | device | Forbidden | Server registration of a native/web device | Revoke on request; retain minimal revocation/security metadata according to policy. | Belongs to one user; stable device ID is checked against its authenticated session; no embedded home permissions. |
| `homes` | Home | home | The entity ID is the tenant ID; no separate parent `home_id` column is needed. | Server home aggregate | Soft-delete/pending-deletion, export, safeguarded owner confirmation, then policy-driven erasure. | At least one active owner membership; ownership transfer is audited; immutable ID. |
| `home_memberships` | Home | home | Required | Server-authoritative membership aggregate | Leave/revoke/expire without deleting historical privileged actions. | Unique `(home_id, user_id)` among active memberships; role is Owner, Manager, Member, or Viewer; no offline grant or role escalation. |
| `home_invitations` | Home | home | Required | Server invitation aggregate | Single use; expires or is revoked; retain minimal audit evidence. | Token stored as a hash; invited identity/address, inviter, role, expiry; acceptance transaction creates or updates membership once. |
| `auth_sessions` | Identity | device | Forbidden; active-home preference may reference a home but confers no permission | Server token/session record | Refresh rotation revokes predecessor; logout, compromise, device revocation, or expiry terminates it. Store only hashes of opaque refresh credentials. | Belongs to user and device; session family detects replay; browser cookie and native bearer transports share domain-neutral session semantics. |
| `support_access_grants` | Home | home | Required | Explicit user-granted support authorization | Time-limited, revocable, non-renewing without new consent; audit retained by policy. | Links granting home owner/manager, support operator, narrow permission set, start/expiry, reason, and revocation; never created by platform role alone. |

Identity support records for email verification, password reset, optional MFA,
rate limiting, and lockout are required by the prompt but are authentication
implementation records rather than separate domain entities named in the core
relational model. Phase 1 must model them without weakening the rules above.

## Global catalog

| Entity | Module | Ownership | `home_id` rule | Source of truth | Retention/deletion | Relations and constraints |
|---|---|---|---|---|---|---|
| `categories` | Catalog | global | Forbidden | Approved global catalog | Retire or merge; do not hard-delete while referenced. | Optional acyclic parent category; unique stable code; revisioned. |
| `units` | Catalog | global | Forbidden | Approved global catalog | Retire only; preserve conversions used by history. | Stable dimension and symbol; conversions only between compatible dimensions; exact conversion factor and rounding policy. |
| `products` | Catalog | global | Forbidden | Approved canonical catalog | Retire or merge through audited command. | One permanent canonical family identity; category and optional brand identity; canonical normalized name must not be the sole duplicate criterion. |
| `product_variants` | Catalog | global | Forbidden | Approved global catalog | Retire or merge; preserve historical links. | Belongs to one product; meaningful variant dimensions only; unique normalized identity within product subject to identity rules. |
| `product_packs` | Catalog | global | Forbidden | Approved global catalog | Retire or merge; never erase pack evidence. | Belongs to product/variant; original display text plus normalized amount/unit/base amount; incompatible units cannot be combined. |
| `product_aliases` | Catalog | global | Forbidden | Approved sanitized alias | Revoke, redirect, or supersede; retain revision history. | Points to one canonical product and optional pack; normalized alias uniqueness must account for locale and ambiguity; raw private receipt text is not automatically global. |
| `product_barcodes` | Catalog | global | Forbidden | Approved barcode assignment | Revoke/reassign only through audited conflict workflow. | Normalized GTIN/barcode is globally unique while active; check digit/type retained; links to exact product/variant/pack when known. |
| `product_identity_rules` | Catalog | global | Forbidden | Versioned approved deterministic rule | Append a successor revision; do not overwrite a rule used by past matching decisions. | Covers all 19 supplied rules; priority and active revision; AI cannot silently override it. |
| `catalog_icons` | Catalog | global | Forbidden | Curator-approved public asset metadata | Retire and garbage-collect bytes only when no revision references them. | Links to product/category; immutable object checksum, media type, dimensions, accessibility label, approval state; no private source image. |
| `catalog_proposals` | Catalog | platform | Forbidden | Sanitized moderation submission | Approve, reject, withdraw, or expire; retain moderation audit. | Contains proposed catalog fields only; must not contain home/user identity, price, quantity, receipt number, image, or private note. A home-private proposed item remains in `home_products`. |
| `catalog_revisions` | Catalog | global | Forbidden | Append-only catalog revision log | Retained while any supported client or audit/export policy requires it; compact only with a documented snapshot boundary. | Monotonic global catalog revision; records changed entity, revision, operation, and sanitized representation/tombstone. |
| `catalog_merge_events` | Catalog | global | Forbidden | Append-only audited merge command/result | Permanent audit fact subject to approved legal policy; never edited. | Surviving and merged IDs differ; relink all affected references transactionally; reversible plan/result and actor authorization are recorded. |

## Home inventory

| Entity | Module | Ownership | `home_id` rule | Source of truth | Retention/deletion | Relations and constraints |
|---|---|---|---|---|---|---|
| `home_locations` | Inventory | home | Required | Home configuration | Archive/tombstone; cannot hard-delete while referenced by count or movement history. | Unique active name within home; supports pantry, shelf, fridge, freezer, and household-supply locations. |
| `home_products` | Inventory | home | Required | Home item-master selection or private proposed product | Archive/tombstone; preserve links to movements, purchases, and lists. | Exactly one of approved global pack/product reference or private proposed identity; private raw name/pack retained; uniqueness is home-scoped and identity-aware. |
| `home_product_aliases` | Inventory | home | Required | Explicit home-approved private alias | Revoke/supersede without erasing match lineage. | Points to a same-home product; raw receipt wording remains private; never queried by global catalog administration and never promoted without the sanitized proposal workflow. |
| `home_catalog_submission_links` | Inventory | home | Required | Private immutable linkage plus a safe local projection of authoritative `catalog_proposals` moderation status | Remove with the home/private product subject to audit and retention policy; removal does not mutate the sanitized proposal. | Links a same-home private product to an opaque sanitized-proposal ID. Status is refreshed from the Catalog application contract rather than independently authored. This relationship is never exposed to curators/reviewers, and the global proposal contains no home/user identity. |
| `stock_count_sessions` | Inventory | home | Required | Count aggregate; closed session is an append-only fact | Draft may be abandoned; closed session cannot be deleted, only corrected by a new reconciliation action. | Belongs to location; state Draft/Closed/Abandoned; close once; detects concurrent sessions and possible multi-photo double counting. |
| `stock_count_lines` | Inventory | home | Required | Confirmed lines in a closed count; drafts are working state | Draft lines may change; after close they are immutable and corrected by a new movement/session. | Belongs to same-home session and home product; observed original quantity and normalized value; confidence/suggestion is not confirmation. |
| `stock_movements` | Inventory | home | Required | **Authoritative append-only inventory ledger** | Never hard-delete; reverse with a linked compensating movement and reason. | Same-home product/location; movement type and signed quantity; unique idempotency/source key prevents duplicate receipt/count effects; manual corrections require reason. |
| `inventory_balances` | Inventory | home | Required | **Rebuildable projection**, derived from stock movements and accepted count reconciliation | Can be rebuilt or replaced; never treated as audit evidence. | Unique `(home_id, home_product_id, location_id)`; stores last applied movement position/revision; cannot be mutated independently. |
| `stock_threshold_preferences` | Inventory | home | Required | Explicit home preference | Archive/tombstone; changes audited where they affect explanation. | Home product and optional location; minimum reserve, always-keep/never-suggest, preferred pack, and user override; does not recreate the prototype `quantity <= 2` rule. |

Count-photo bytes remain local by default. Local media metadata is a Drift client
concern, not a server relational entity in this initial model. Optional encrypted
backup requires a later approved entity and retention ADR.

## Purchasing and pricing

| Entity | Module | Ownership | `home_id` rule | Source of truth | Retention/deletion | Relations and constraints |
|---|---|---|---|---|---|---|
| `stores` | Purchasing | home | Required | Home-confirmed store identity | Archive/tombstone; retain purchase references. | Unique normalized store identity only within a home; no unapproved global sharing. |
| `receipts` | Purchasing | home | Required | Human-confirmed receipt header | Draft/rejected extraction may expire by policy; committed header is retained or redacted through deletion policy with audit-safe reversals. | Same-home store; original date, currency, receipt number where present; source fingerprint/idempotency key prevents duplicate commit; original image is local by default. |
| `receipt_lines` | Purchasing | home | Required | Human-confirmed purchase line | Preserve raw description; committed lines are not silently deleted. Correct through explicit amendment/reversal. | Belongs to same-home receipt; raw printed description, original quantity/unit price/line total and normalized values; can remain unresolved. |
| `receipt_line_matches` | Purchasing | home | Required | Human-approved match decision | Append/supersede rather than erase; preserve decision lineage. | Links receipt line to same-home private item or global canonical product/pack; candidate scores and AI suggestions are not approvals; alias scope is explicit. |
| `price_observations` | Purchasing | home | Required | Approved receipt line or explicit home entry | Retain privately according to home/account policy; delete/anonymize only through home deletion workflow. | Same-home store, product/pack, currency, observed date, original and normalized quantity/price; never global without separately designed consent. |
| `discounts` | Purchasing | home | Required | Confirmed receipt evidence | Follows receipt retention; correction is explicit. | Same-home receipt and optionally line; original label, amount/type, currency; totals must reconcile without fabricating missing values. |
| `tax_observations` | Purchasing | home | Required | Confirmed receipt evidence | Follows receipt retention; correction is explicit. | Same-home receipt and optionally line; jurisdiction label/rate/amount when present; unresolved values are allowed. |
| `ai_extraction_runs` | AiIntegration | home | Required | Validated provider result metadata plus user corrections | Keep structured results/metadata only for the approved policy; delete credentials and transient payloads independently; never store hidden reasoning. | Links optional receipt or count session; provider, model, prompt/schema versions, timing, confidence, warnings, cost metadata when available; no original media by default. |

## Shopping and intelligence

| Entity | Module | Ownership | `home_id` rule | Source of truth | Retention/deletion | Relations and constraints |
|---|---|---|---|---|---|---|
| `shopping_lists` | Shopping | home | Required | Home list aggregate | Archive/tombstone; preserve history used by explanations only as policy permits. | State, title, dates, creator; all lines same home. |
| `shopping_list_lines` | Shopping | home | Required | Manual entry or accepted suggestion | Tombstone on removal; checked state uses revision-based last accepted update. | Same-home list and optional home/global product pack; original free text retained if unresolved; quantity and check state independently revisioned if needed. |
| `shopping_suggestion_runs` | Shopping | home | Required | Versioned deterministic recommendation execution | Retain inputs summary/model version long enough for explanation and evaluation; policy-controlled cleanup. | Records algorithm version, horizon, data coverage, generation time; never presented as a fact without confidence. |
| `suggestion_explanations` | Shopping | home | Required | Materialized explanation for one generated suggestion | Follows its run/list retention; reproducible facts should remain available while suggestion is visible. | Links run, home product/pack, expected demand, usable stock, safety stock, pack mapping, confidence and ordinary-language explanation. |
| `user_suggestion_feedback` | Shopping | home | Required | Explicit user action | Retain or erase with user/home privacy policy; aggregate only after separately designed privacy rules. | Links suggestion/run and actor; dismiss, snooze, edited quantity, accepted, always-keep, never-suggest; actor must be an authorized member. |
| `recipes` (later) | Shopping | home | Required when integration is designed | Nextcloud Cookbook remains the present external source of truth | Deferred; local caching/deletion must follow the future integration contract. | Stable external reference and revision; do not import or fork ownership before an approved recipe ADR. |
| `recipe_ingredients` (later) | Shopping | home | Required when integration is designed | Derived from or synchronized with the external recipe | Deferred with recipes. | Same-home recipe; optional product/pack mapping, original ingredient text and unit retained. |
| `menu_plans` (later) | Shopping | home | Required when designed | Future home planning aggregate | Deferred; retention to be defined with recipe integration. | References same-home recipes and dates; may inform demand only after explicit design. |

## Synchronization and audit

| Entity | Module | Ownership | `home_id` rule | Source of truth | Retention/deletion | Relations and constraints |
|---|---|---|---|---|---|---|
| `client_operations` | Synchronization | home | Required for the Phase 4 home-sync protocol | Server receipt/idempotency record for a client operation | Retain through the supported retry/offline window plus a safety interval; compact only after no supported client can replay it. | Unique `(device_id, operation_id)`; authenticated user/device and home captured by server; request hash detects operation-ID reuse with different payload. |
| `change_log` | Synchronization | home | Required | Append-only ordered home change feed | Retain through published offline window; compact only behind a snapshot/resync boundary. | Monotonic sequence per home; entity, ID, revision, operation and safe representation/tombstone; no global catalog rows—those use `catalog_revisions`. |
| `sync_cursors` | Synchronization | device | Required as a referenced tenant scope | Server-observed per-device/per-home synchronization progress | Remove after device revocation and expiry policy, but not before tombstone/operation replay safety is satisfied. | Unique `(device_id, home_id, feed/schema version)`; cursor is opaque; device/user membership is revalidated on every sync. |
| `record_tombstones` | Synchronization | home | Required | Deletion fact for sync-visible home entity | Retain at least through the complete supported offline window; after compaction old clients must perform full resync. | Same-home entity type/ID, deletion revision/time and feed sequence; contains no deleted private payload. |
| `audit_events` | SharedKernel / Administration | platform, user, or home | Required for home actions; forbidden for purely platform/user actions; exactly one valid scope | Append-only security/domain audit event | Tamper-evident retention according to approved security/legal policy; sensitive payloads redacted; never edited. | Actor, action, target reference, outcome, correlation/request ID, privilege/support grant when applicable; platform admins gain no home scope from the table. |
| `outbox_events` | SharedKernel | platform or home | Required when the originating transaction is home-scoped; otherwise forbidden | Transactional outbox written with authoritative domain change | Delete/compact only after confirmed publication and operational retention; failed publication remains reviewable. | Unique message/event ID; aggregate revision, type/schema version, correlation/causation IDs, minimal tenant context; payload references authoritative records and excludes media/credentials. |

Queue broker messages, dead-letter review records, worker heartbeats, and rate
limit counters are infrastructure/operational records. They do not replace the
domain entities or transactional outbox and must not contain private media,
credentials, or receipt content.

## Source-of-truth and projection map

| Concern | Authoritative fact | Projection/cache | Rebuild rule |
|---|---|---|---|
| Inventory quantity | `stock_movements` plus immutable closed-count reconciliation facts | `inventory_balances` | Replay ordered movements per home/product/location; assert last applied position. |
| Purchase history | Confirmed `receipts` and `receipt_lines` | Monthly summaries and reporting views | Recalculate from source purchase lines. The supplied 261 monthly rows are validation evidence only. |
| Shopping suggestions | Versioned input facts and `shopping_suggestion_runs` | Visible suggestion/explanation lists | Regenerate with the recorded model version; never mutate stock from a suggestion. |
| Catalog state | Approved catalog aggregates plus `catalog_revisions` and merge events | Search index, normalized lookup tables, client catalog cache | Rebuild from active canonical records and ordered revisions. |
| Home sync state | Domain aggregates plus `change_log`/tombstones | Client Drift database and stored cursor | Pull from durable cursor; bootstrap a snapshot when history has expired. |
| AI scan | Human-approved receipt/count commands | Validated AI proposal/result metadata | AI output alone is never authoritative and cannot be replayed as an inventory commit. |

## Required constraints and indexes

- Every home-private primary lookup and uniqueness constraint begins with or
  includes `home_id`.
- Same-home composite foreign keys protect relationships such as receipt/line,
  list/line, count/session line, product/location/movement, and suggestion/run.
- `stock_movements` has unique idempotency/source constraints for receipt line
  approval, count-session close, reversal, and client operation.
- Active membership is unique per `(home_id, user_id)`; a home cannot lose its
  final owner without a safeguarded transfer or home-deletion transaction.
- Active GTIN/barcode assignment is globally unique and conflict-reviewed.
- Normalized catalog search fields are indexes, not destructive replacements
  for original values.
- Revision checks are atomic in the same transaction as mutation, change-log
  emission, audit where required, and outbox insertion.
- Database portability tests must validate constraints, collation behavior,
  JSON usage, index lengths, locking, and transaction semantics on SQLite,
  MySQL, and MariaDB.

## Deliberately unresolved Phase 0 values

- Exact supported offline window and all deletion/retention durations.
- Production database default (external database, bundled MySQL/MariaDB, or
  equally documented paths).
- First-release encrypted private-media backup; it is disabled by default and
  no server media entity is authorized by this draft.
- First-release locale/currency/unit/time-zone set beyond preserving `en-NA`
  and NAD as migration evidence.
- Physical Doctrine mappings, SQL types, index names, and partition strategy.

These values do not justify weakening tenant isolation, evidence preservation,
or ledger immutability while they remain undecided.
