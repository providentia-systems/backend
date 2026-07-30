# Synchronization protocol

**Status:** Phase 0 protocol draft

**Authority:** `home_stock_control_master_implementation_prompt_V1.md`

**Scope:** Offline-first Flutter clients synchronizing private home data with
the Mezzio/Laminas backend.

This protocol makes local writes durable, server retries idempotent, conflicts
explicit, and tenant boundaries testable. It is not a global last-write-wins
scheme. Global catalog synchronization uses the catalog revision feed; home
membership and role grants remain online, server-authoritative commands.

## Invariants

1. Flutter UI observes repositories backed by Drift. Widgets do not call HTTP
   or SQLite directly.
2. A local mutation and its outbox operation are committed in one Drift
   transaction before the UI reports local success.
3. The operation remains pending until the server returns a terminal
   per-operation result.
4. The authenticated server session determines the user and verifies that its
   registered device matches the envelope. The route home is reauthorized for
   every request.
5. Client-created UUIDv7-compatible entity IDs survive acceptance; the server
   does not replace them merely because they were created offline.
6. Server acceptance atomically writes the domain change, operation receipt,
   audit event where required, ordered change-log row(s), and transactional
   outbox event(s) where required.
7. `stock_movements` is append-only and carries a domain uniqueness key.
   Duplicate receipt approval or count close can never double-add inventory.
8. A cursor is opaque, home-scoped, ordered, and durable. Client clocks do not
   order server changes.
9. Deleted sync-visible resources emit tombstones. Auditable facts are reversed,
   revoked, expired, or superseded rather than erased.
10. The server can commit while the response is lost. Retrying the exact
    `operationId` must recover the same accepted result.

## Local Drift records

The client storage design must include, at minimum:

| Record | Required content |
|---|---|
| Local domain row | Entity ID, home ID, server revision when known, local sync state, domain fields, and local timestamps for diagnostics. |
| Client outbox operation | Operation ID, device ID, home ID, entity type/ID, operation type, base revision, payload schema version, payload, client timestamp, retry count, last safe error, and state. |
| Durable pull cursor | Home ID, feed/schema version, last fully applied opaque cursor, and last successful sync time. |
| Local tombstone | Home ID, entity type/ID, server revision/cursor, deletion time, and acknowledgement state. |
| Local media metadata | Device-local URI/handle, content fingerprint and scan relation; never assume another device can open it. |

Outbox states are `pending`, `in_flight`, `blocked_conflict`,
`blocked_validation`, `blocked_authorization`, `retry_wait`, and
`acknowledged`. A process crash may leave `in_flight`; startup safely returns it
to retry with the same operation ID.

## Push

### Endpoint

`POST /api/v1/homes/{homeId}/sync/push`

Required controls:

- authenticated native device access credential or protected web session;
- CSRF protection for cookie authentication;
- `Idempotency-Key` for the batch request;
- validated `X-Request-ID`;
- bounded batch size and payload byte size published in OpenAPI;
- per-user, per-device, per-home, and per-IP rate limits.

The route `homeId` is a selector, not authorization. Every referenced record is
loaded inside the authorized home scope. Payload `homeId`, actor identity, role,
revision, and server audit fields are rejected as writable domain fields.

### Push envelope

```json
{
  "protocolVersion": 1,
  "batchId": "0198a5f3-1e35-7c9a-a836-80c8ce20f23c",
  "deviceId": "0198a5d7-68b7-70c8-bf9d-e11b4bbdbdb1",
  "lastPulledCursor": "opaque-home-cursor",
  "operations": [
    {
      "operationId": "0198a5f1-94a7-7dcc-8c88-14cb3944a89c",
      "entityType": "shoppingListLine",
      "entityId": "0198a5e9-915b-715d-b8b3-26e20986059d",
      "operationType": "setCheckState",
      "baseRevision": 4,
      "clientTimestamp": "2026-07-29T14:31:22.000Z",
      "payloadSchemaVersion": 1,
      "payload": {
        "checked": true
      }
    }
  ]
}
```

Field contract:

| Field | Rule |
|---|---|
| `protocolVersion` | Required integer; unsupported versions fail the envelope before processing. |
| `batchId` | Required UUIDv7-compatible ID for observability and batch request idempotency; it does not replace each operation ID. |
| `deviceId` | Required registered device ID; must belong to the authenticated session/user. |
| `lastPulledCursor` | Optional diagnostic/concurrency context; never grants access and never changes authoritative server ordering. |
| `operations` | Ordered, non-empty, bounded array. The server returns one result per supplied operation. |
| `operationId` | Required, stable across all retries. Server uniqueness is at least `(device_id, operation_id)`. |
| `entityType` | Closed OpenAPI enum for resources allowed in this protocol. Administrative/catalog merge and membership grants are excluded. |
| `entityId` | Required client/server stable ID. |
| `operationType` | Closed enum per entity type, preferably a domain command rather than generic arbitrary patch. |
| `baseRevision` | Required for revision-checked updates/deletes; absent only for allowed creates or append commands whose domain uniqueness/precondition is in the payload. |
| `clientTimestamp` | Required diagnostic instant. It never determines winning order, permissions, price date, or audit time. |
| `payloadSchemaVersion` | Required positive integer; allows deterministic operation upgrades. |
| `payload` | Strict command schema selected by entity/operation/version. Unknown writable fields fail validation. |

The persisted `client_operations` record adds server-derived authenticated user,
authorized home, request hash, received time, result, and resulting revision/
cursor. The client does not assert the authenticated actor.

### Per-operation result envelope

The HTTP response is `200` for a syntactically valid, authenticated batch even
when individual operations have conflicts or validation failures. Whole-request
authentication, unsupported protocol, malformed JSON, rate limiting, or backend
unavailability uses the corresponding HTTP Problem Details response.

```json
{
  "protocolVersion": 1,
  "batchId": "0198a5f3-1e35-7c9a-a836-80c8ce20f23c",
  "requestId": "0198a5f4-3fa9-7115-b8cf-ad2e0bb5e86e",
  "serverTime": "2026-07-29T14:31:23.040Z",
  "results": [
    {
      "operationId": "0198a5f1-94a7-7dcc-8c88-14cb3944a89c",
      "status": "accepted",
      "entityType": "shoppingListLine",
      "entityId": "0198a5e9-915b-715d-b8b3-26e20986059d",
      "revision": 5,
      "changeCursor": "opaque-home-cursor",
      "representation": {
        "id": "0198a5e9-915b-715d-b8b3-26e20986059d",
        "revision": 5,
        "checked": true
      }
    }
  ]
}
```

`status` is one of:

| Status | Terminal? | Meaning and required result |
|---|---|---|
| `accepted` | Yes | Commit succeeded, or an identical prior commit is being replayed. Return resulting revision and safe canonical representation/tombstone where applicable. |
| `conflict` | No, user/domain action required | Base revision or domain state conflicts. Return a safe conflict object, current revision/representation when authorized, and allowed resolution actions. |
| `validation_error` | Until corrected/replaced | Strict transport or domain validation failed. Return Problem Details-compatible field errors; retrying unchanged input cannot succeed. |
| `authorization_failure` | Until authorization changes | Membership revoked, role insufficient, support grant expired, device mismatch, or object outside scope. Do not leak cross-home state. |
| `retryable_failure` | No | No accepted commit is being acknowledged for this attempt. Return a safe code and optional `retryAfter`; client retries the same operation ID. |

An unexpected server error must not convert an unknown commit outcome into a
new operation. The status-recovery endpoint or an identical retry determines
the outcome.

### Processing and ordering

- Operations are independently idempotent and each has a result. A failed item
  does not imply that later independent items were not processed.
- The client preserves local creation order for operations on the same aggregate.
  The server still validates current revision and domain state; array order is
  not an authorization or concurrency override.
- Accepted changes receive server commit order in the per-home change feed.
  `clientTimestamp` and array position do not allocate a cursor.
- When an operation depends on a locally created aggregate, the client pushes
  the aggregate create first and blocks dependent commands if that create is
  rejected. IDs require no server remapping.
- Batch-level idempotency is an optimization; operation-level receipts and
  domain uniqueness remain mandatory.

## Pull

### Endpoint

`GET /api/v1/homes/{homeId}/sync/pull?cursor=<opaque>&limit=<n>`

For first sync or expired history, use the authorized bootstrap flow. A missing
cursor does not silently mean "all history" once the dataset exceeds the
published bootstrap contract.

### Pull response

```json
{
  "protocolVersion": 1,
  "requestId": "0198a600-21b3-7e50-ad78-1f61659d0d93",
  "fromCursor": "opaque-home-cursor",
  "pageCursor": "opaque-home-cursor",
  "highWaterCursor": "opaque-home-cursor",
  "hasMore": false,
  "changes": [
    {
      "cursor": "opaque-home-cursor",
      "entityType": "shoppingListLine",
      "entityId": "0198a5e9-915b-715d-b8b3-26e20986059d",
      "operation": "upsert",
      "revision": 5,
      "serverTimestamp": "2026-07-29T14:31:23.030Z",
      "representationSchemaVersion": 1,
      "representation": {
        "id": "0198a5e9-915b-715d-b8b3-26e20986059d",
        "revision": 5,
        "checked": true
      }
    }
  ]
}
```

For deletion:

```json
{
  "cursor": "opaque-home-cursor",
  "entityType": "shoppingListLine",
  "entityId": "0198a5e9-915b-715d-b8b3-26e20986059d",
  "operation": "delete",
  "revision": 6,
  "serverTimestamp": "2026-07-29T15:02:00.000Z",
  "representationSchemaVersion": 1,
  "tombstone": {
    "deletedAt": "2026-07-29T15:02:00.000Z"
  }
}
```

### Cursor semantics

- A cursor is an opaque server token. Clients store and compare it only by
  equality and pass it back unchanged.
- Each accepted home-visible transaction allocates ordered change positions
  monotonically within that home. Gaps are permitted; reordering is not.
- A pull captures `highWaterCursor` at the beginning. All pages in that pull
  cover changes after `fromCursor` through that same high-water boundary, so
  writes arriving during pagination wait for the next pull.
- `pageCursor` advances through the current bounded page. The client stores it
  durably only after applying the complete page to Drift in one transaction.
  When `hasMore` is false, `pageCursor` equals the completed high-water
  boundary.
- A cursor is bound to home and feed/protocol generation. Reuse for another home
  or altered token returns a safe `400`/`404`; it never reveals the other home.
- A cursor older than retained change/tombstone history returns `410 Gone` with
  problem code `sync_resync_required` and a safe bootstrap link.
- Server-side `sync_cursors` are observability/retention aids; client progress is
  not trusted as proof that all devices have pulled.
- Global catalog changes use an independent opaque catalog revision cursor so a
  home feed cannot leak catalog-administration or other-home activity.

### Applying a pull page

Within one Drift transaction the client:

1. processes changes in returned order;
2. discards an upsert older than the locally stored accepted server revision;
3. applies a newer safe representation or tombstone;
4. reconciles any matching acknowledged/pending local operation state without
   inventing an acknowledgement;
5. stores the new page cursor only after all changes commit.

A process death before commit replays the whole page safely. A pending local
operation is not overwritten silently: the repository retains it and surfaces
a conflict or reapplies the local intent against the new base only when that
entity's explicit conflict rule permits it.

## Bootstrap and schema evolution

A bootstrap is a consistent, authorized, paged snapshot followed by its captured
home change cursor. The server must:

- bind the opaque snapshot token to the authenticated device/home;
- capture one high-water cursor;
- paginate deterministic entity sets with stable schema versions;
- include tombstone-independent current state;
- expire the token safely without changing authorization;
- require restart if a page set can no longer be completed consistently.

The client writes the new snapshot into staging tables or uses an equivalent
transaction-safe replacement, preserves compatible pending outbox operations,
then revalidates/rebases those operations explicitly. It must never discard
pending operations during an application or Drift schema upgrade.

Protocol, payload, and representation schema versions are separate:

- an unknown protocol version rejects the envelope;
- a known operation with an older supported payload version is upgraded
  deterministically before validation and the original remains in the operation
  receipt hash;
- an unsupported operation schema blocks that operation with an upgrade-required
  result;
- a client database migration must prove pending operations survive and remain
  serializable before release.

## Tombstones and deletion

- Sync-visible mutable resources produce a `record_tombstone` and `delete`
  change-log entry in the same transaction as their controlled deletion.
- Tombstones contain only tenant scope, entity type/ID, deletion revision/time,
  and ordered cursor; deleted private content is absent.
- Tombstones and operation receipts are retained through the entire published
  supported offline window plus an approved safety interval. No duration is
  invented in Phase 0.
- Compaction first establishes an authorized bootstrap boundary. A device whose
  cursor predates that boundary receives `sync_resync_required`.
- Stock movements, closed count facts, receipt commit effects, audit events, and
  merge events are not ordinary delete candidates. Correction uses a linked
  reversal/superseding fact, which itself synchronizes.
- Home/account deletion is a separately safeguarded server workflow. A device
  cannot resurrect a deleted home through replay.

## Conflict policy by domain

| Domain operation | Policy |
|---|---|
| Membership, invitation role, ownership, support grant | Online and server-authoritative. Never accepted as an offline permission grant. Revocation immediately blocks later operations even if they were queued earlier. |
| Closed stock count | Append-only fact. Close is idempotent. Concurrent sessions for the same product/location produce explicit reconciliation work; one session does not silently overwrite the other. |
| Receipt commit | Idempotent command. Only human-approved lines create stock-in movements, once. Reprocessing or sync retry returns the existing result. |
| Manual stock correction | Append-only movement with required reason and expected state. Stale expected state conflicts and requires review. |
| Shopping-list checked state | Revision-based last **accepted** update. A stale base returns conflict/current state; the client may offer an explicit reapply, never timestamp-based silent overwrite. |
| Shopping-list contents/quantity | Optimistic locking. Preserve unresolved raw text and show conflicting values when user intent cannot be merged safely. |
| Private notes | Optimistic locking with user-visible local/server versions and explicit resolution. No global last-write-wins. |
| Home product/private alias | Revision checks and deterministic identity rules. Conflicting canonical links require human resolution. |
| Global catalog edit | Separate revision-checked moderation API. Not accepted through home offline sync. |
| Product merge | Server-only, privileged, revision-checked, audited command with preview and reversible plan. |
| Balance/suggestion/report projection | Never accepts client mutation. Rebuilt from authoritative facts; stale projection is replaced by newer server state. |

Conflict response example:

```json
{
  "operationId": "0198a5f1-94a7-7dcc-8c88-14cb3944a89c",
  "status": "conflict",
  "entityType": "shoppingListLine",
  "entityId": "0198a5e9-915b-715d-b8b3-26e20986059d",
  "conflict": {
    "code": "revision_mismatch",
    "baseRevision": 4,
    "currentRevision": 6,
    "allowedActions": [
      "discardLocal",
      "reapplyAgainstCurrent"
    ],
    "currentRepresentation": {
      "id": "0198a5e9-915b-715d-b8b3-26e20986059d",
      "revision": 6,
      "checked": false
    }
  }
}
```

Allowed actions are a closed enum per operation. Server state is returned only
after object-level authorization.

## Retry and connectivity behavior

| Condition | Client action |
|---|---|
| Timeout, connection loss, response lost | Keep operation durable; retry identical `operationId`, payload, and batch idempotency key. |
| `401` access expiry | Attempt one refresh using rotated device credential, then retry unchanged operations. Refresh replay/denial signs out and preserves blocked local work for explicit recovery/export. |
| Revoked session/device/membership | Do not loop. Mark affected operations `blocked_authorization`, hide inaccessible server data according to policy, and offer safe sign-in/export/reconciliation guidance. |
| `429` | Respect `Retry-After`; use exponential backoff with jitter and tenant/device rate-limit awareness. |
| `503`/retryable operation result | Keep pending and retry unchanged operation after server hint or exponential backoff with jitter. |
| `400`/`422` permanent validation | Stop automatic retry; expose safe field/domain error and let a corrected action create a new operation. |
| Conflict | Stop automatic retry for that intent; resolve with domain-specific UI and new explicit command where needed. |
| Cursor expired (`410`) | Run authorized bootstrap; preserve and revalidate pending outbox operations. |

Backoff has configured minimum, maximum, multiplier, jitter, and retry budget in
the generated client/runtime policy. Values are selected and tested in Phase 4,
not guessed here. Network reachability is a hint; only a successful API request
proves service availability.

The UI uses precise states:

- local commit completed: `Saved on this device - waiting to sync`;
- transient retry: show non-blocking pending status;
- conflict/validation/authorization block: show the exact affected action and
  safe resolution;
- fully acknowledged and pull reconciled: synced.

Background synchronization is best effort. Always attempt synchronization on
app start, resume, home switch, manual refresh, and after a successful foreground
mutation when connectivity permits. Do not promise an operating system will run
background work at a particular time.

## Security and privacy

- Device ID, operation ID, cursor, entity ID, and idempotency key are not
  credentials.
- Every push, pull, bootstrap, and status request reruns session, device,
  membership, role, home, and object-level checks.
- Query construction is home-scoped before resolving an entity; post-load
  filtering is insufficient.
- Payload schemas cannot write server actor, `home_id`, roles, revisions,
  audit/outbox fields, balance projections, or approval state.
- Rate limiting covers user, device, home, IP, and expensive provider-backed
  operations without leaking whether another tenant owns an identifier.
- Sync messages and logs exclude image bytes, AI credentials, receipt content,
  medical content, refresh tokens, and encrypted-secret plaintext.
- A catalog proposal is sanitized by a server use case; private home content is
  never copied wholesale into the global catalog revision feed.
- Sync replay is contained by operation receipts, request hashes, token/session
  rotation, revision checks, domain uniqueness, and tombstones.

## Required automated tests

### Client repository and Drift

- Local domain mutation and outbox insertion roll back together on failure.
- Process death after local commit leaves a retryable operation.
- Process death while `in_flight` retries the same ID.
- Pull page application and cursor advancement are atomic.
- App/Drift schema upgrade preserves every pending operation and payload version.
- A tombstone removes/archives the local visible row without deleting pending
  conflict evidence.
- Device-local media metadata never becomes a usable URI on another device.

### Server idempotency and transactions

- Duplicate identical operation returns the original accepted result.
- Same operation ID with altered payload is rejected.
- Server commit followed by lost response is recovered by identical retry.
- Receipt commit twice produces one movement per approved line.
- Count close twice produces one reconciliation effect.
- Database failure rolls back domain, operation receipt, change log, audit, and
  outbox together.
- Outbox dispatch/redelivery remains idempotent.

### Ordering, cursors, and tombstones

- Hours and days offline within the supported window pull all changes in order.
- Duplicate and out-of-order network requests cannot regress revision/state.
- Changes created while paging appear only after the captured high-water cursor.
- Client crash before cursor commit safely replays a page.
- Clock skew does not alter server order or choose a winner.
- Tombstone replay is idempotent.
- Expired cursor returns `sync_resync_required`; bootstrap preserves pending work.
- Catalog and home cursors cannot be substituted for each other.

### Authorization

- A user in three homes receives only the selected authorized home's feed.
- Changing route, entity, nested relation, cursor, operation status, or device ID
  cannot cross a home.
- Viewer cannot push mutations.
- Revoked membership blocks queued work submitted after revocation.
- Role changes cannot be granted offline.
- Platform administrator, catalog curator, reviewer, and support operator have
  no implicit home sync access.
- Active support access works only for its home, scope, and time window and is
  audited.

### Conflict and recovery

- Concurrent two-device edits execute each conflict policy above.
- Concurrent count sessions require reconciliation and never silently overwrite.
- Shopping check-state stale revision returns current authorized state and
  explicit actions.
- Notes retain both values for user-visible resolution.
- Token expiry during push refreshes once and does not duplicate.
- Network loss during push and server restart/failover preserve operation result.
- Retryable dependency failure leaves the client outbox pending.
- Permanent validation error stops automatic retry without losing the local
  action.

### Portability and operational resilience

- Run the same server sync integration suite on SQLite, supported MySQL, and
  supported MariaDB.
- Exercise locking, concurrent revision checks, uniqueness, transaction rollback,
  and monotonic cursor allocation on all three.
- Exercise MySQL and MariaDB restart/failover behavior.
- Test maximum batch/page bounds, compression, timeouts, rate limiting, and
  malformed cursors.
- Confirm request/correlation observability contains no private payloads.

Phase 4 is not complete until these tests pass across two actual devices/client
instances and the failure outcomes are visible and recoverable in the Flutter UI.
