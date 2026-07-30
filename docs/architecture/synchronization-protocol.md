# Phase 4 synchronization protocol

## Boundary

Protocol version `1` is home-scoped and device-bound. The Phase 4 prototype
implements the end-to-end mechanics for the explicitly allow-listed
`home-preference` and `private-note` entity types. It does not fabricate Phase
3 inventory aggregates that do not yet exist.

## Push

`POST /api/v1/homes/{homeId}/sync/push` requires:

- current membership in the route home;
- a session device equal to the envelope `deviceId`;
- protocol version `1`;
- an `Idempotency-Key` exactly equal to the UUID `batchId`;
- 1–100 closed-shape operations;
- UUID operation/entity IDs, `put` or `delete`, schema version `1`, a valid
  client timestamp, and at most 64 KiB of JSON payload.

Version 1 payloads are closed:

- `private-note` requires a `body` of 1–4,000 characters (matching OpenAPI
  `minLength`/`maxLength`, so whitespace is not implicitly trimmed) and permits
  an optional `title` of at most 120 characters.
- `home-preference` permits only `defaultLocale`, `defaultCurrency`,
  `defaultTimezone`, and `measurementSystem` (`metric` or `imperial`), with at
  least one field. `defaultTimezone` is additionally checked at runtime by
  PHP's `DateTimeZone` constructor; the OpenAPI length constraint alone cannot
  express the constructor's accepted identifier/offset set.
- Delete payloads are empty. Unknown and server-owned fields such as IDs,
  revisions, actors, or tenant scope are rejected.

The server derives user and home from authentication/routing. Client timestamps
never allocate order. A viewer receives a per-operation authorization failure.
For each operation the server compares `baseRevision`, applies one next
revision, writes the change feed, receipt, audit record, tombstone if deleted,
and outbox event in one database transaction.

Operation IDs are globally unique and bound to the original home, user, device,
and canonical request hash. An identical retry returns the stored response. Any
reuse with changed identity/scope/payload becomes an `operation_id_reuse`
conflict.

## Bootstrap and pull

The first incremental pull without a cursor returns `410
sync_resync_required`. The client first requests
`GET /api/v1/homes/{homeId}/sync/bootstrap`. Authorization is rechecked, the
server captures one high-water sequence and the current non-deleted records in
one database transaction, returns that snapshot plus an opaque cursor, and
acknowledges the captured position for the current device.

The current bootstrap implementation refuses snapshots above 250 records with
`409`; it never returns an inconsistent partial snapshot. Paged bootstrap with
a durable snapshot token is the required upgrade before large homes.

Incremental pull decodes a signed cursor that contains the route home, current
position, frozen high-water position, expiry, and version. Pages are ordered by
the server sequence and never include changes newer than the frozen high-water.
The client commits a page and its `pageCursor` atomically. `hasMore=false`
marks that frozen window complete. The next pull captures a new current
high-water boundary, making later server changes visible while again freezing
that entire paged window.

Changing the cursor home or signature cannot disclose another tenant. Expired
cursors return `410 sync_resync_required`.

## Deletion and retention

Deletion increments the resource revision and emits a payload-free tombstone.
The current implementation retains tombstones indefinitely and performs no
history compaction because the supported offline duration has not been approved.
Once that decision exists, compaction must retain all history through the
supported window and establish a documented snapshot boundary; older cursors
must receive `sync_resync_required`.

## Client invariants

- Persist the local mutation and client operation in one Drift transaction.
- Reuse operation/batch IDs after timeouts; never generate a new ID for an
  uncertain response.
- Do not treat a client clock or opaque cursor as a comparable domain value.
- Apply all records/tombstones in a page and advance the cursor atomically.
- Preserve pending local operations through a required bootstrap.
- Treat `revision_mismatch` as an explicit conflict, not last-write-wins.

## Remaining Phase 4 acceptance boundary

This is a bounded Phase 4 protocol prototype, not full Phase 4 acceptance:

- There is no standalone operation-status recovery endpoint. After a lost
  response, the client must retry the original immutable operation ID and
  batch ID to receive the stored exact response.
- Bootstrap is one consistent page capped at 250 current records. The server
  fails safely with `409` above that boundary. A durable snapshot-token design
  is required before consistent multi-page bootstrap can be claimed.
- Automated multi-day/offline scheduling, UI retry state, token-refresh during
  sync, database failover/restart scenarios, and full multi-device end-to-end
  tests remain open.
