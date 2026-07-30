# OpenAPI domain outline

**Status:** Phase 0 contract-design deliverable

**Authority:** `home_stock_control_master_implementation_prompt_V1.md`

**Target:** Authoritative backend-owned OpenAPI contract for generated Flutter
client/model bindings.

This is the domain and file-layout plan for the Phase 1 OpenAPI 3.1 contract. It
is intentionally not a fabricated "complete" YAML file: operations that depend
on unresolved authentication, AI image-path, proposal-consent, or retention
decisions must be finalized after those decisions. Phase 1 must turn every
included operation into a validated server conformance test; it must not publish
fake or no-op handlers.

## Contract ownership and release rules

- The backend repository owns `contracts/openapi` and related JSON Schemas.
- Each backend API tag publishes an immutable, bundled OpenAPI artifact with an
  exact semantic contract version and checksum.
- The Flutter repository pins that artifact and generates its transport client
  and DTOs. Generated sources are never hand-edited.
- API URI major version is `/api/v1`; compatible additions increment the
  contract version without changing the URI major.
- CI compares the candidate bundle with the latest supported release and fails
  on an unapproved breaking change.
- Server request validation, server response conformance, examples, and the
  generated Dart client's serialization tests all consume the same bundle.
- OpenAPI schemas are transport DTOs, not Doctrine entities and not domain
  aggregate serialization.

## Proposed contract layout

```text
contracts/openapi/
  openapi.yaml
  paths/
    auth.yaml
    me.yaml
    homes.yaml
    catalog.yaml
    inventory.yaml
    purchasing.yaml
    shopping.yaml
    ai.yaml
    synchronization.yaml
    administration.yaml
    reporting.yaml
    health.yaml
  components/
    common.yaml
    security.yaml
    problems.yaml
    identity.yaml
    home.yaml
    catalog.yaml
    inventory.yaml
    purchasing.yaml
    shopping.yaml
    ai.yaml
    synchronization.yaml
    administration.yaml
  examples/
    problems/
    synchronization/
    workflows/
```

Only the bundled `openapi.yaml` is published as the immutable consumer
artifact. Source splitting is a maintainer convenience and must not rely on
network references.

## OpenAPI document baseline

| Property | Contract |
|---|---|
| Specification | OpenAPI 3.1 using JSON Schema 2020-12 semantics supported by the selected validators/generator. |
| `info.version` | Full semantic contract version; never `latest`. |
| Servers | Explicit environment values supplied by release/deployment integration; no production credentials or private hosts in source. |
| Media types | `application/json`; `application/problem+json` for errors. Any later streamed image proxy gets a separately bounded media contract. |
| IDs | String, UUID format where tool compatibility is verified, with UUIDv7 semantics documented. |
| Instants | RFC 3339 UTC `date-time`; calendar dates use `date`. |
| Decimals | Patterned decimal strings for money and quantities; no binary floating point. |
| Currency | String constrained to supported ISO 4217 values; `NAD` is migration baseline, not a permanent only value. |
| Revisions | Non-negative/positive integer as defined per resource; exposed in body and ETag. |
| Unknown fields | Request objects set `additionalProperties: false` unless a specifically documented extension map exists. |
| Nullability | JSON Schema union types; omission and `null` have distinct documented meanings. |

Descriptions must distinguish:

- original/raw evidence from normalized values;
- global catalog identifiers from home-private identifiers;
- authoritative facts from projections;
- draft/AI-proposed values from human-confirmed values;
- server-derived fields from client-writable fields.

## Tags and module mapping

| OpenAPI tag | Backend module | Scope |
|---|---|---|
| `Auth`, `Me` | Identity | Registration, verification, sessions, account/profile, devices. |
| `Homes`, `Memberships`, `Invitations`, `SupportAccess` | Home | Home aggregate, roles, invitations, ownership, support grants. |
| `Catalog` | Catalog | Public/authenticated global catalog reads and revision feed. |
| `HomeProducts` | Inventory/Catalog application boundary | Home item master and private proposed identities. |
| `Inventory`, `StockCounts` | Inventory | Locations, ledger, balance projection, preferences, counts. |
| `Receipts`, `Prices` | Purchasing | Stores, receipt review/commit, match decisions, private price history. |
| `ShoppingLists`, `Suggestions` | Shopping | Lists, deterministic suggestions, explanations, feedback. |
| `AiIntegration` | AiIntegration | Provider settings and structured extraction proposals. |
| `Synchronization` | Synchronization | Push, pull, bootstrap, operation recovery. |
| `CatalogAdministration` | Administration/Catalog | Moderation, catalog revision commands, icons, merge plans. |
| `Reporting`, `Exports` | Reporting | Home-private projections and export jobs. |
| `Health` | SharedKernel | Safe liveness/readiness only. |

No path handler may call another module's Infrastructure layer or expose its
tables. Cross-module results use published application DTOs.

## Security schemes and operation requirements

Components:

```yaml
components:
  securitySchemes:
    NativeBearer:
      type: http
      scheme: bearer
    BrowserSession:
      type: apiKey
      in: cookie
      name: providentia_session
    CsrfToken:
      type: apiKey
      in: header
      name: X-CSRF-Token
```

The bearer format remains opaque in the contract unless the selected
implementation deliberately publishes JWT semantics. Refresh credentials never
appear as bearer examples or response fields beyond the strictly required
native session response and are never logged.

Security requirements:

- public health and explicitly public metadata may use `security: []`;
- protected reads accept either `NativeBearer` or `BrowserSession`;
- cookie-authenticated state changes require `BrowserSession` **and**
  `CsrfToken`;
- native state changes require `NativeBearer`;
- OpenAPI operation descriptions also name the server-side role/domain policy,
  because a security scheme alone does not describe authorization;
- platform roles and support grants are not encoded as API keys or trusted
  client headers.

OpenAPI cannot express "either bearer, or cookie plus CSRF" perfectly for all
generator behaviors without validation. Phase 1 must verify the chosen security
requirement encoding and enforce the stricter rule in middleware tests.

## Reusable common components

### Identifiers and metadata

| Schema | Required fields and semantics |
|---|---|
| `UuidV7` | String identifier; client-created IDs accepted where the operation permits. |
| `ResourceMeta` | `id`, `revision`, `createdAt`, `updatedAt`; server-owned. |
| `HomeScopedMeta` | `ResourceMeta` plus `homeId` only where the client needs explicit context; never writable. |
| `RequestContext` | Response-only `requestId`; optional `correlationId` only if safe and useful to clients. |
| `PageInfo` | `nextCursor`, `hasMore`; cursor opaque. |
| `DecimalValue` | Canonical decimal string with explicit precision/scale constraints selected per field. |
| `Money` | `amount: DecimalValue`, `currency`; no implicit currency conversion. |
| `OriginalAndNormalizedQuantity` | Original value/text/unit/pack plus optional normalized base value/unit and conversion evidence. |
| `RevisionPrecondition` | Header parameter `If-Match`; documented `428`/`412` responses. |
| `IdempotencyKey` | Required header parameter for retryable create/command operations. |
| `RequestId` | Optional request and required response header, bounded and validated. |

### Problem components

| Schema | Fields |
|---|---|
| `ProblemDetails` | Required `type`, `title`, `status`, `code`, `requestId`; optional safe `detail`, `instance`, `retryable`, `retryAfter`, and documented extensions. |
| `ValidationProblem` | `ProblemDetails` plus `errors[]`. |
| `FieldError` | `pointer` as JSON Pointer, stable `code`, safe `message`. |
| `ConflictProblem` | `ProblemDetails` plus conflict type, safe current revision/representation, and closed allowed-action enum when relevant. |
| `ResyncRequiredProblem` | `ProblemDetails` with `sync_resync_required` and authorized bootstrap relation. |

Every operation explicitly references its possible Problem Details responses.
A wildcard error response may supplement but cannot replace documented domain
errors.

### Pagination components

```json
{
  "items": [],
  "nextCursor": "opaque-or-null",
  "hasMore": false
}
```

Each collection defines a concrete `items` type and allowlisted filters/sorts.
Cursors are not a generic string usable across resource, home, user, or catalog
feeds.

## Domain representation schemas

The following are transport schema families. `Create` and `Update` inputs expose
only writable fields; `View` objects add server-owned identity, revision, state,
and timestamps. Fields marked "original" are never replaced by normalization.

### Identity and home

| Schema family | Essential contract content |
|---|---|
| `RegistrationCreate`, `RegistrationAccepted` | Normalized email input, password input under the approved policy, locale preference; response never echoes password or reveals whether an unrelated account exists. |
| `SessionCreate`, `SessionView`, `RefreshRequest` | Authentication input, safe user/device/session metadata, access expiry, native refresh rotation result as selected; cookie response uses headers, not readable secret fields. |
| `UserView`, `UserProfileUpdate` | User ID, verified-email state, profile display name/locale; account security state is limited to safe flags. |
| `DeviceView`, `AuthSessionView` | Device/session IDs, label/platform, created/last-used/expiry/revocation state; no token hash. |
| `HomeCreate`, `HomeUpdate`, `HomeView` | Name, selected locale/time-zone/currency/unit preferences once approved, revision and safe deletion state. |
| `MembershipView`, `MembershipRoleChange` | User-safe display fields, role, status, revision; home-scoped and server-authoritative. |
| `InvitationCreate`, `InvitationView`, `InvitationAccept` | Intended identity/address, role, expiry and safe state; raw token appears only in the invitation link/input, never list output. |
| `OwnershipTransferCreate`, `OwnershipTransferConfirm` | Target active member, expected home/membership revisions, explicit confirmation. |
| `SupportGrantCreate`, `SupportGrantView` | Support operator identity, closed permission set, reason, start/expiry, visibility and revocation state. |

### Global catalog

| Schema family | Essential contract content |
|---|---|
| `CategoryView` | ID, revision, stable code, localized/display name, optional parent, status, approved icon reference. |
| `UnitView` | ID, revision, stable code/symbol, dimension, conversion definition and rounding policy. |
| `ProductSummary`, `ProductDetail` | Canonical family identity, category, brand when meaningful, variants/packs links, status, icon; no home fields. |
| `ProductVariantView` | Product link and meaningful variant attributes preserved by the identity rules. |
| `ProductPackView` | Product/variant, original pack display, normalized amount/unit/base conversion when valid, status. |
| `ProductAliasView` | Sanitized approved alias, locale/scope metadata, canonical product/pack, ambiguity/status. |
| `ProductBarcodeView` | Barcode/GTIN string, type/check metadata, exact canonical target and status. |
| `ProductIdentityRuleView` | Stable rule ID, rule revision/version, priority, identity dimensions and status; all 19 migration rules must be representable. |
| `CatalogIconView` | Public object URL/reference, checksum, media metadata, accessibility text, approval/status. |
| `CatalogRevisionChange`, `CatalogRevisionPage` | Opaque catalog cursor, operation, entity type/ID/revision and sanitized representation/tombstone. |

Catalog write schemas exist only under administration, use revision
preconditions, and cannot accept home-private data.

### Home item master and inventory

| Schema family | Essential contract content |
|---|---|
| `HomeProductCreate`, `HomeProductUpdate`, `HomeProductView` | Either global product/pack link or private proposed name/pack/variant evidence; preferred/archived state; server home and revision. |
| `HomeProductAliasCreate/Update/View` | Same-home product, original alias text, normalized lookup form, locale and state/revision; never a global publication input without a separate sanitized proposal. |
| `CatalogProposalSubmissionCreate` | Same-home private product reference and explicit consent/policy evidence; server accepts no caller-supplied global provenance fields and constructs the sanitized proposal. |
| `CatalogProposalSubmissionStatusView` | Opaque home-private submission ID, safe moderation state/timestamps and optional non-sensitive reason; status is a projection of authoritative catalog moderation, with no queue payload or global actor details. |
| `ProductMatchRequest`, `ProductMatchCandidate` | Raw/original identity evidence; candidate target, deterministic match reason/score components and optional AI marker; never auto-merges. |
| `HomeLocationCreate/Update/View` | Name, type and archived state; home scope/revision. |
| `InventoryBalanceView` | Home product/location, usable/projected original and normalized quantities when convertible, projection revision/last movement position; explicitly read-only. |
| `StockMovementView` | Immutable movement ID, type, original/normalized signed quantity, product/location, source reference, reason where required, server actor/time and reversal relation. |
| `StockAdjustmentCreate` | Product/location, quantity delta or counted target as explicitly selected, original unit evidence, reason, expected state, idempotency. |
| `StockMovementReverse` | Target movement, reason, expected state; creates a compensating fact. |
| `StockCountSessionCreate/View` | Location, draft/closed/abandoned state, start/close metadata, concurrency and possible-double-count warnings. |
| `StockCountLinePut/View` | Stable line ID, home product, observed original/normalized quantity, confirmed flag, optional extraction-run candidate relation and warnings. |
| `StockCountClose`, `StockCountCloseResult` | Expected session revision, confirmed line set/state, reconciliation conflicts, variance and created movement IDs; idempotent. |
| `StockPreferencePut/View` | Minimum reserve, always-keep/never-suggest, preferred pack and explicit override data; no fixed global threshold. |

### Purchasing

| Schema family | Essential contract content |
|---|---|
| `StoreCreate/Update/View` | Home-private store name and original/normalized identity; status/revision. |
| `ReceiptCreate/Update/View` | Purchase date, store, receipt number when present, currency, state, structured extraction relation and reconciliation totals; no original image URL by default. |
| `ReceiptLineCreate/Update/View` | Raw printed description, original quantity, unit price, line total, brand/product/variant/pack evidence, discount/tax links, unresolved warnings and state. |
| `ReceiptMatchCandidate`, `ReceiptLineMatchPut/View` | Candidate global/private target, exact/normalized/deterministic/AI origin, confidence/reasons; approved actor/revision distinguished from suggestion. |
| `DiscountView`, `TaxObservationView` | Original label and confirmed amount/rate/type/jurisdiction evidence; nullable when absent, not fabricated. |
| `ReceiptCommit`, `ReceiptCommitResult` | Expected receipt revision, explicitly approved line revisions/matches, result movement/price IDs and unresolved lines; commit once. |
| `ReceiptAmendmentCreate/View` | Explicit correction/reversal reason and affected facts; no destructive edit to committed history. |
| `PriceObservationView` | Private product/pack/store/date, original quantity/price and currency, optional normalized comparison value with conversion evidence. |

### Shopping and intelligence

| Schema family | Essential contract content |
|---|---|
| `ShoppingListCreate/Update/View` | Title, state, dates, progress projection and revision. |
| `ShoppingListLineCreate/Update/View` | Optional product/pack or unresolved raw text, requested/editable quantity, suggestion origin, checked state and revision. |
| `CheckStatePut` | Boolean state and `If-Match`; no client timestamp winner. |
| `SuggestionRunCreate/View` | Requested horizon/context and server-selected versioned deterministic model, run state, generated time, data coverage. |
| `ShoppingSuggestionView` | Product/pack, expected demand, usable stock, safety stock, required amount, mapped buy quantity, confidence, data coverage and weak-evidence flag. |
| `SuggestionExplanationView` | Ordinary-language explanation plus structured contributing factors and model version. |
| `SuggestionFeedbackCreate/View` | Accepted/dismissed/snoozed/edited quantity/always-keep/never-suggest/preferred-pack action and safe actor/time metadata. |

Recipe/menu schemas are excluded from the first contract. Nextcloud Cookbook
remains the source of truth until a deliberate later integration contract.

### AI integration

| Schema family | Essential contract content |
|---|---|
| `AiProviderTypeView` | Provider mode, supported capabilities and privacy-path labels; no secrets. |
| `AiSettingsPut/View` | Selected provider, strict-local/cloud mode, credential mode, explicit privacy flags and safe configured/not-configured state. |
| `AiCredentialPut`, `AiCredentialRotationResult` | Write-only credential input over protected transport; response contains no plaintext credential. |
| `ExtractionRunView` | ID/state, provider/model, prompt/schema versions, timing, confidence/warnings, cost/token metadata when available, user-correction state; no hidden reasoning or media bytes. |
| `ReceiptExtractionProposal` | Classification, date, store, receipt number, currency, raw lines, brand/product/variant/pack/quantity/prices/discounts/tax/notes, per-field and per-line confidence, warnings and unresolved values. |
| `StockPhotoExtractionProposal` | Candidate product/pack, visible quantity or range, confidence, optional bounding region, ambiguity/occlusion warnings. |
| `ExtractionFailure` | Classified refusal, invalid structured output, truncation, timeout, unsupported capability, sensitive/unrelated document rejection. |

The image-request schema is conditional on the unresolved decision between a
streaming non-persisting application proxy and direct native/local provider
calls. The contract must display the selected provider and true image path
before transmission. AI proposals never contain a command that commits stock.

### Synchronization

| Schema family | Essential contract content |
|---|---|
| `SyncPushRequest` | Protocol version, batch ID, registered device ID, optional last-pulled cursor, bounded operations. |
| `ClientOperation` | Stable operation ID, entity type/ID, closed domain operation type, base revision where required, diagnostic client time, payload schema version, strict payload. |
| `SyncPushResponse` | Protocol version, batch/request IDs, server time, exactly one result per operation. |
| `OperationAccepted` | Resulting revision/cursor and safe canonical representation/tombstone; includes identical replay. |
| `OperationConflict` | Conflict code, base/current revisions, authorized current representation, closed allowed actions. |
| `OperationValidationError` | Problem-compatible pointer/code/message errors; unchanged retry cannot succeed. |
| `OperationAuthorizationFailure` | Safe denial without cross-home disclosure. |
| `OperationRetryableFailure` | Safe code and optional retry-after; same operation ID required. |
| `SyncPullResponse` | From/page/high-water opaque cursors, `hasMore`, ordered changes. |
| `SyncChange` | Cursor, entity type/ID, upsert/delete, revision, server time, representation schema version and exactly one representation/tombstone. |
| `SyncBootstrapPage` | Snapshot token, captured high-water cursor, stable entity page and page continuation. |
| `OperationStatusView` | Persisted accepted/blocked result for response-loss recovery, authorized to originating device/home. |

The exact envelopes and cursor/tombstone semantics are normative in
`03-synchronization-protocol.md` and must be transcribed without divergence.

### Administration, reporting, and operational schemas

| Schema family | Essential contract content |
|---|---|
| `CatalogProposalView` | Sanitized catalog fields and moderation state only; explicitly excludes home/user ID, price, quantity, receipt number, image and private notes. |
| `CatalogReviewCreate/View` | Decision, reason, reviewer role, expected proposal revision and safe audit relation. |
| `CatalogMergePreviewRequest/View` | Surviving/duplicate IDs, affected global relation counts and warnings; no private home content. |
| `CatalogMergeCreate/Result` | Approved preview/revisions, idempotency, immutable merge event and reversible plan/result. |
| `HomeReportView` families | Clearly labelled projections, date/currency/unit context, data coverage and generated-at position. |
| `ExportRequestCreate/View` | Home/user export scope, state, expiry and safe retrieval method; asynchronous result does not expose object-store credentials. |
| `HealthLive`, `HealthReady` | Boolean/status and safe component classification only; no credentials, hostnames, stack traces or private counts. |

## Operation definition checklist

Every `paths` operation must declare:

1. stable `operationId` following `<module><UseCase>`, not a framework handler
   class name;
2. tag and concise domain summary;
3. authentication alternatives plus the exact required home/platform/domain
   policy in the description or a documented extension;
4. all path/query/header parameters, including `If-Match`,
   `Idempotency-Key`, and `X-Request-ID` where applicable;
5. strict request schema and at least one validated non-sensitive example;
6. success status and schema (`201` with `Location` for creation, `200` for
   command result/update/read, or `204` only when no response is needed);
7. ETag and request-ID response headers where applicable;
8. explicit Problem Details statuses;
9. idempotency and retry classification;
10. whether response data is authoritative, draft, proposal, or projection;
11. pagination/filter/sort allowlist for collections;
12. deprecation metadata when applicable.

## Workflow-level contract coverage

OpenAPI examples and contract tests must cover these sequences:

### Register and create a home

`RegistrationCreate` → email verification → authenticated `SessionView` →
`HomeCreate` → owner `MembershipView`. A user can then list/switch among three
homes, but every home resource is separately authorized.

### Unknown product

Create `HomeProduct` with preserved raw private identity → request deterministic
match candidates → either link an existing canonical pack, retain private
product, add a pack through moderation, or leave unresolved → optionally submit
only a sanitized `CatalogProposal`.

### Receipt

Create draft receipt → optional validated extraction proposal → human edits
header/lines → deterministic candidate matches → human-approved line matches →
idempotent commit → immutable stock-in movements and private price observations.
Reprocessing/retry returns the same effects.

### Stock-photo count

Create count session/location → retain image locally → optional extraction
proposal → human-confirmed lines with unconfirmed first in UI → explicit close
→ concurrency/double-count check → immutable reconciliation movements → balance
projection update.

### Offline edit and reconciliation

Drift transaction writes state/outbox → push one or more versioned operations →
per-operation accepted/conflict/error result → pull through stable high-water
cursor → apply page and cursor atomically → tombstone or conflict handling →
bootstrap only when the feed history has expired.

### Catalog merge

Reviewer/curator reads sanitized queue → revision-checked review → merge preview
→ authorized idempotent merge → relink all canonical references transactionally
→ immutable merge event and catalog revision. No home-private record is exposed
to the administrator.

## Contract validation and generation pipeline

Phase 1 must make these checks executable:

1. lint and bundle all local references deterministically;
2. validate OpenAPI 3.1 and every example against its schema;
3. reject duplicate/missing `operationId` values;
4. fail if a protected operation has no security/policy declaration;
5. fail if a home-scoped write schema exposes `homeId` as writable;
6. fail if secret/hash/internal Doctrine fields enter a response schema;
7. compare with the last tagged contract for breaking changes;
8. generate the Dart client/models from the bundled artifact;
9. compile and test generated Dart serialization for commands, Problem Details,
   pagination, revisions, and every synchronization result variant;
10. run server conformance tests proving actual status, headers, body, and
    authorization match the contract on SQLite, MySQL, and MariaDB profiles;
11. publish the bundle, checksum, generator version/configuration, and generated
    client proof as release artifacts.

Generator selection is a Phase 1 evidence-based decision. Generated output
quality, nullability, sealed/discriminated unions, OpenAPI 3.1 support, Dart
compatibility, license, reproducibility, and maintenance status must be tested
before adoption.

## Decisions that affect final contract shape

The master prompt identifies these unresolved decisions. They must be answered
only when they materially affect the operation/schema:

- first authentication methods beyond email/password;
- cloud image path (streamed non-persisting backend proxy versus permitted
  native direct cloud calls), default AI privacy mode, and advanced native BYOK;
- optional encrypted private-media backup in first release or later;
- automatic versus explicit per-item sanitized catalog proposal submission;
- first-release locales, currencies, units, time zones, and languages;
- supported offline/retention window, which governs cursor/tombstone expiry.

Android/iOS release priority, public name, deployment profile, queue-broker
profile, and commercial/licensing choices affect Phase 1 planning or public
claims but do not change the core home authorization and domain DTO rules in
this outline.
