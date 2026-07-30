# API resource outline

**Status:** Phase 0 contract draft

**Authority:** `home_stock_control_master_implementation_prompt_V1.md`

**Audience:** Backend, Flutter, security, migration, and contract-test work in
Phases 1–4.

This document defines the HTTP resource surface needed to plan the first
versioned OpenAPI contract. It does not scaffold handlers or claim implemented
behavior. Domain commands remain the authority for business changes; HTTP
handlers validate transport input, call one application use case, and map its
result.

## Protocol and versioning

- HTTPS is mandatory outside a trusted development/container network.
- JSON API base path: `/api/v1`.
- The URI major version changes only for a deliberately incompatible contract.
  Compatible additions are released under the same major with the OpenAPI
  document carrying its full semantic contract version.
- The backend publishes immutable OpenAPI and JSON Schema artifacts for every
  tagged API release. The Flutter repository pins a released contract and
  generates its client and DTOs; PHP and Dart request/response types are not
  maintained independently.
- Clients send `Accept: application/json`; problem responses use
  `application/problem+json`. Schema-sensitive writes include a payload schema
  version in the command or synchronization operation.
- Deprecations identify the replacement and removal release/window in response
  metadata and published release notes. Removing or narrowing a field,
  operation, enum value, authentication mode, or documented behavior is a
  breaking change.

## Request context and authorization

### Authentication modes

| Client/surface | Transport | Required controls |
|---|---|---|
| Native Flutter | Short-lived bearer access credential and rotated, device-bound opaque refresh credential | Refresh credential in OS secure storage; server stores only its hash; replay detection, per-device revocation, and bounded token lifetime. |
| Authenticated Flutter web | Secure `HttpOnly`, `Secure`, `SameSite` cookie session | CSRF token on state-changing requests; restrictive CORS and origin checks; no provider key in browser storage. |
| Public website/API reads | Anonymous | Only explicitly public operations; no inferred active home or private catalog proposal access. |
| Platform/catalog administration | Native/web authenticated session plus platform role | Role and operation policy checked server-side; platform role never grants home data. |
| Time-limited support | Authenticated support operator plus active user-granted support grant | Grant scope, home, expiry, and every access audited; no implicit platform-admin access. |

`401` means authentication is absent, expired, or invalid. `403` means the
authenticated principal is not permitted. An object from another home should
normally be indistinguishable from a nonexistent object (`404`) unless exposing
its existence is explicitly safe.

### Home selection is not authorization

Home-scoped routes use `/homes/{homeId}/...` so the requested tenant is
unambiguous and cache/audit keys are explicit. The path value is only a selector.
For every request, the server:

1. resolves the authenticated user and device/session;
2. loads an active membership or explicit support grant for that exact home;
3. evaluates the required home role and domain policy;
4. resolves every referenced object within the same home;
5. ignores/rejects any attempt to set or change `home_id` in a payload.

The last active home is a user/device preference, not an authorization claim.

### Request and correlation IDs

- A client may send `X-Request-ID` using a valid bounded identifier. The server
  validates it or generates a new value and always returns `X-Request-ID`.
- The server creates or propagates a correlation ID for application, audit,
  outbox, queue, and worker observability. Neither ID contains private data.
- Logs redact credentials, tokens, AI keys, image bytes, receipt content, and
  medical content.

## Standard HTTP behavior

### Resource representation

Every mutable resource representation includes:

```json
{
  "id": "0198...",
  "revision": 7,
  "createdAt": "2026-07-29T14:31:22.000Z",
  "updatedAt": "2026-07-29T14:33:04.000Z"
}
```

Home IDs are returned only where the client needs explicit context. Server
payload mappers never serialize Doctrine entities, proxies, internal encrypted
fields, credential hashes, or cross-home foreign keys.

### Optimistic concurrency

- Mutable single-resource reads return `ETag: "rev-<revision>"`.
- Updates, state transitions, and tombstone deletes require `If-Match`.
- Missing required preconditions return `428 Precondition Required`; a stale
  revision returns `412 Precondition Failed` with the current safe
  representation or a conflict link when authorization permits.
- Append-only commands use domain-specific expected state/revision plus
  idempotency. Closed counts, stock movements, audit events, and merge events
  are never edited in place.

### Idempotency

- All retryable `POST` commands and create operations require
  `Idempotency-Key`; use a UUIDv7-compatible opaque value.
- The server scopes the key to authenticated principal/device, home where
  applicable, operation, and route; stores a request hash and terminal response;
  and returns the same result for an identical retry.
- Reusing a key with a different method, target, or normalized body returns
  `409 Conflict`.
- Stock-in from a receipt line, count close/reconciliation, reversals,
  invitation acceptance, product merge, exports, and synchronization operations
  also carry domain uniqueness keys so transport retries cannot duplicate facts.
- A timeout never permits a client to create a replacement operation with a new
  key until it has checked/retried the original outcome.

### Pagination, filtering, and ordering

- Collections use opaque cursor pagination: `?cursor=<opaque>&limit=<n>`.
- Responses contain `items`, `nextCursor`, and `hasMore`. Exact total counts are
  omitted unless a use case can calculate them safely and cheaply.
- Default and maximum limits are published in the OpenAPI contract, not assumed
  by clients.
- Sort order is deterministic with the immutable ID as final tie-breaker.
- Search/filter parameters are allowlisted per resource. Catalog search covers
  canonical names, approved aliases, brands, pack sizes, and categories. Private
  home search additionally covers permitted private proposed products.
- Home-private collection cursors are scoped to the authenticated home and
  cannot be replayed against another home.

### Problem Details

Errors follow the current Problem Details RFC shape:

```json
{
  "type": "urn:providentia:problem:validation-failed",
  "title": "Request validation failed",
  "status": 422,
  "detail": "One or more fields are invalid.",
  "instance": "/api/v1/homes/0198.../receipts/0198...",
  "requestId": "0198...",
  "code": "validation_failed",
  "errors": [
    {
      "pointer": "/lines/0/quantity",
      "code": "must_be_positive",
      "message": "Quantity must be greater than zero."
    }
  ],
  "retryable": false
}
```

`type` is a stable documented URI. `detail` and field messages are safe for the
caller and contain no stack traces or private cross-home data. Extensions are
defined in OpenAPI. Expected statuses include:

| Status | Use |
|---:|---|
| `400` | Malformed JSON, headers, cursor, or request syntax. |
| `401` | Missing/expired/invalid authentication; native client may attempt one safe refresh. |
| `403` | Authenticated but policy denies the operation. |
| `404` | Resource absent or intentionally concealed across tenant boundary. |
| `409` | Domain conflict, reused idempotency key, concurrent count, or incompatible state. |
| `410` | Sync cursor/history expired; full authorized resynchronization required. |
| `412` | `If-Match`/revision precondition failed. |
| `422` | Syntactically valid request fails input/domain validation. |
| `428` | Required precondition missing. |
| `429` | Rate limited; `Retry-After` supplied. |
| `503` | Classified retryable dependency failure; `Retry-After` when known. |

## Resource and endpoint map

The table distinguishes ordinary CRUD-like resources from commands that must
preserve domain invariants. `home` authorization applies to every route below
that starts with `/homes/{homeId}`.

### Identity and account

| Resource/use case | Initial operations |
|---|---|
| Registration and verification | `POST /auth/registrations`, `POST /auth/email-verifications`, `POST /auth/email-verifications/resend` |
| Login/session rotation | `POST /auth/sessions`, `POST /auth/sessions/refresh`, `DELETE /auth/sessions/current` |
| Password recovery | `POST /auth/password-reset-requests`, `POST /auth/password-resets` |
| Current user/profile | `GET /me`, `PATCH /me/profile` with `If-Match`, `POST /me/export-requests`, `POST /me/deletion-requests` |
| Devices/sessions | `GET /me/devices`, `GET /me/sessions`, `DELETE /me/sessions/{sessionId}`, `POST /me/sessions/revoke-others` |
| MFA (when selected) | `POST /me/mfa/enrolments`, `POST /me/mfa/challenges`, `DELETE /me/mfa/enrolments/{id}` |

Password hashes, refresh-token hashes, MFA secrets, lockout internals, and email
verification/reset tokens are never API representations.

### Homes, membership, and support

| Resource/use case | Initial operations |
|---|---|
| Homes | `GET /homes`, `POST /homes`, `GET /homes/{homeId}`, `PATCH /homes/{homeId}`, `POST /homes/{homeId}/deletion-requests` |
| Active-home preference | `PUT /me/active-home` after membership validation |
| Memberships | `GET /homes/{homeId}/memberships`, `PATCH /homes/{homeId}/memberships/{membershipId}`, `DELETE /homes/{homeId}/memberships/{membershipId}`, `POST /homes/{homeId}/leave` |
| Invitations | `GET /homes/{homeId}/invitations`, `POST /homes/{homeId}/invitations`, `DELETE /homes/{homeId}/invitations/{invitationId}`, `POST /invitations/{token}/accept` |
| Ownership transfer | `POST /homes/{homeId}/ownership-transfers`, `POST /homes/{homeId}/ownership-transfers/{id}/confirm` |
| Support grants | `GET /homes/{homeId}/support-grants`, `POST /homes/{homeId}/support-grants`, `DELETE /homes/{homeId}/support-grants/{grantId}` |

Membership grants, role changes, ownership transfers, invitation acceptance, and
support grants are server-authoritative online commands and cannot be created as
offline permission grants.

### Global catalog and home item master

| Resource/use case | Initial operations |
|---|---|
| Catalog discovery | `GET /catalog/categories`, `GET /catalog/units`, `GET /catalog/products`, `GET /catalog/products/{productId}`, `GET /catalog/packs/{packId}`, `GET /catalog/search`, `GET /catalog/barcodes/{barcode}` |
| Catalog revision feed | `GET /catalog/revisions` with opaque revision cursor |
| Home item master | `GET /homes/{homeId}/products`, `POST /homes/{homeId}/products`, `GET /homes/{homeId}/products/{homeProductId}`, `PATCH /homes/{homeId}/products/{homeProductId}`, `DELETE /homes/{homeId}/products/{homeProductId}` |
| Matching | `POST /homes/{homeId}/product-match-candidates`; deterministic exact rules precede optional AI suggestions |
| Private aliases | `GET /homes/{homeId}/product-aliases`, `POST /homes/{homeId}/product-aliases`, `GET/PATCH/DELETE /homes/{homeId}/product-aliases/{aliasId}`; these routes never publish globally |
| Sanitized proposal submission/status | `GET/POST /homes/{homeId}/catalog-proposal-submissions`, `GET /homes/{homeId}/catalog-proposal-submissions/{submissionId}`; submission constructs a sanitized global payload, while reads expose only the same-home opaque submission link and safe moderation status |

Creating an unknown home product makes it immediately usable only in that home.
Global publication is a separate moderation action. Whether proposal submission
is automatic or explicit per item is an unresolved product decision; the
contract must not pick one silently. Catalog curators and reviewers access only
the sanitized moderation resource; they cannot query the private submission
link or infer its originating home/user.

### Inventory and stock counting

| Resource/use case | Initial operations |
|---|---|
| Locations | `GET/POST /homes/{homeId}/locations`, `GET/PATCH/DELETE /homes/{homeId}/locations/{locationId}` |
| Balances | `GET /homes/{homeId}/inventory-balances`, `GET /homes/{homeId}/inventory-balances/{homeProductId}`; read-only projection |
| Movement ledger | `GET /homes/{homeId}/stock-movements`, `GET /homes/{homeId}/stock-movements/{movementId}` |
| Manual correction | `POST /homes/{homeId}/stock-adjustments`; requires reason, expected balance revision, and idempotency |
| Movement reversal | `POST /homes/{homeId}/stock-movements/{movementId}/reversals`; never delete a movement |
| Count sessions | `GET/POST /homes/{homeId}/stock-count-sessions`, `GET /homes/{homeId}/stock-count-sessions/{sessionId}` |
| Draft count lines | `PUT /homes/{homeId}/stock-count-sessions/{sessionId}/lines/{lineId}`, `DELETE .../lines/{lineId}` with session revision |
| Close/reconcile count | `POST /homes/{homeId}/stock-count-sessions/{sessionId}/close`; detects concurrency/double counting and creates movements once |
| Threshold/preferences | `GET/PUT /homes/{homeId}/stock-preferences/{homeProductId}` |

Server endpoints accept structured count evidence only. Original stock-photo
bytes remain local by default. AI suggestions do not become count lines or
movements until confirmed by a user and the count is explicitly closed.

### Purchases, receipts, and prices

| Resource/use case | Initial operations |
|---|---|
| Stores | `GET/POST /homes/{homeId}/stores`, `GET/PATCH/DELETE /homes/{homeId}/stores/{storeId}` |
| Receipts | `GET/POST /homes/{homeId}/receipts`, `GET/PATCH /homes/{homeId}/receipts/{receiptId}` |
| Draft receipt lines | `POST /homes/{homeId}/receipts/{receiptId}/lines`, `PATCH/DELETE .../lines/{lineId}` |
| Match review | `GET /homes/{homeId}/receipts/{receiptId}/match-candidates`, `PUT /homes/{homeId}/receipts/{receiptId}/lines/{lineId}/match` |
| Commit receipt | `POST /homes/{homeId}/receipts/{receiptId}/commit`; approves selected lines and creates stock-in movements once |
| Amend/reverse committed data | `POST /homes/{homeId}/receipts/{receiptId}/amendments`; explicit history-preserving command |
| Prices | `GET /homes/{homeId}/price-observations` with product/store/date/currency filters |

Raw printed descriptions remain present even after a canonical match. Unresolved
lines are valid. Receipt image upload is not part of the default persistence
contract.

### Shopping and suggestions

| Resource/use case | Initial operations |
|---|---|
| Lists | `GET/POST /homes/{homeId}/shopping-lists`, `GET/PATCH/DELETE /homes/{homeId}/shopping-lists/{listId}` |
| List lines | `POST /homes/{homeId}/shopping-lists/{listId}/lines`, `PATCH/DELETE .../lines/{lineId}` |
| Check state | `PUT /homes/{homeId}/shopping-lists/{listId}/lines/{lineId}/check-state` with revision precondition |
| Suggestions | `GET /homes/{homeId}/shopping-suggestions`, `POST /homes/{homeId}/shopping-suggestion-runs` |
| Explanation | `GET /homes/{homeId}/shopping-suggestions/{suggestionId}/explanation` |
| Feedback | `POST /homes/{homeId}/shopping-suggestions/{suggestionId}/feedback` |

Every suggestion representation includes confidence, data coverage, explanation,
editable quantity, pack mapping, and weak-evidence wording when applicable.
Suggestions cannot mutate inventory.

### AI provider configuration and extraction

| Resource/use case | Initial operations |
|---|---|
| Provider capabilities | `GET /ai/provider-types`; public safe metadata only |
| Home/user settings | `GET /homes/{homeId}/ai-settings`, `PUT /homes/{homeId}/ai-settings`; returned representation never includes a plaintext secret |
| Credential entry/rotation/deletion | `PUT /homes/{homeId}/ai-credentials/{providerId}`, `POST .../{providerId}/rotate`, `DELETE .../{providerId}` |
| Receipt extraction | `POST /homes/{homeId}/ai/receipt-extractions`, `GET /homes/{homeId}/ai/extraction-runs/{runId}` |
| Stock-photo extraction | `POST /homes/{homeId}/ai/stock-photo-extractions`, `GET /homes/{homeId}/ai/extraction-runs/{runId}` |

The exact image transport depends on the unresolved privacy-mode decision. A
future contract may expose a streamed, non-persisting server proxy. It must not
describe cloud transmission as on-device processing. Direct local/self-hosted
mode may not require image bytes to touch this API. Extraction responses are
validated proposals; confirmation uses the ordinary receipt/count commands.

### Synchronization

| Resource/use case | Initial operations |
|---|---|
| Push durable client operations | `POST /homes/{homeId}/sync/push` |
| Pull ordered changes | `GET /homes/{homeId}/sync/pull?cursor=...&limit=...` |
| Bootstrap snapshot | `GET /homes/{homeId}/sync/bootstrap` using a bounded, paged snapshot token |
| Operation status recovery | `GET /homes/{homeId}/sync/operations/{operationId}` |

The complete envelopes, cursor rules, conflicts, tombstones, retry behavior, and
tests are defined in `03-synchronization-protocol.md`.

### Catalog administration

These routes require Catalog Reviewer, Catalog Curator, or Platform
Administrator permissions as specified per operation, but none grant home-data
access.

| Resource/use case | Initial operations |
|---|---|
| Moderation queue | `GET /administration/catalog/proposals`, `GET /administration/catalog/proposals/{proposalId}` |
| Review | `POST /administration/catalog/proposals/{proposalId}/reviews` |
| Catalog CRUD via commands | `POST/PATCH /administration/catalog/products...`, variants, packs, aliases, barcodes, identity rules, categories, and units with revisions |
| Icon management | `POST /administration/catalog/icons`, `POST /administration/catalog/icons/{iconId}/approve`, `DELETE .../{iconId}` |
| Merge preview/execution | `POST /administration/catalog/merge-previews`, `POST /administration/catalog/merges`; audited, revision-checked, reversible plan |
| Audit/history | `GET /administration/catalog/revisions`, `GET /administration/catalog/merge-events` |

Moderation representations contain sanitized proposal data only. Curators and
reviewers cannot query prices, receipts, quantities, lists, home AI settings, or
private media.

### Reporting, export, health, and operations

| Resource/use case | Initial operations |
|---|---|
| Home reports | `GET /homes/{homeId}/reports/inventory`, `/purchases`, `/consumption`, `/suggestions`; reporting projections only |
| Home export | `POST /homes/{homeId}/export-requests`, `GET /homes/{homeId}/export-requests/{id}` |
| Liveness/readiness | `GET /health/live`, `GET /health/ready`; never expose secrets or dependency connection strings |
| API contract | `GET /api/openapi.json` for the running release; immutable tagged artifact remains release authority |

Queue depth, dead letters, worker heartbeats, and other operational metrics use
a separately protected operator surface and never expose private payloads.

## Field and command boundaries

- Create/update schemas set only allowlisted fields. Server-owned fields include
  `home_id`, actor, membership role, approval/commit state, revisions, audit
  fields, ledger source, and normalized security attributes.
- Monetary values use decimal strings plus ISO currency, never binary
  floating-point JSON numbers.
- Quantities preserve original decimal/text/unit/pack evidence and expose
  normalized base-unit values only when a valid conversion exists.
- Dates without times remain dates; instants are RFC 3339 UTC timestamps.
- Barcodes remain strings so leading zeroes survive.
- Free text has explicit length and normalization rules and is never used as
  HTML.
- Binary media is not embedded in ordinary JSON. Any later upload endpoint must
  validate type by content, bound size/dimensions, protect against decompression
  bombs, scan/quarantine, and follow the media/privacy ADR.

## Phase 1 contract gates

Before calling this API outline an implemented contract, Phase 1 must provide:

1. an OpenAPI 3.1 document with stable `operationId` values and reusable schemas;
2. request/response examples that validate against the document;
3. Problem Details and authentication middleware tests;
4. generated PHP-side conformance validation and a generated Dart client proof;
5. backward-compatibility checks against the last tagged contract;
6. authorization requirements attached to every non-public operation;
7. idempotency, concurrency, pagination, and request-ID contract tests;
8. no Doctrine entity/proxy serialization;
9. tenant isolation tests for each home-scoped resource;
10. unresolved endpoints or fields clearly marked as deferred, not fake success
    implementations.
