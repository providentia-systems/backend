# Phase 4 synchronization protocol

## Current boundary

Providentia supports two home-scoped, device-bound write envelopes over one
ordered change feed:

- protocol v1 handles the closed `home-preference` and `private-note`
  resources;
- protocol v2 handles closed, typed pantry commands for inventory, counts,
  purchasing, and shopping.

Both versions require current membership in the route home, the authenticated
session device, UUID operation and batch identities, exact idempotency-key
binding, bounded request sizes, and one classified result per operation. The
server derives the actor and tenant; no body field grants access.

## Protocol-v2 pantry commands

The enabled command set is deliberately closed:

- create a location or private/global home product;
- create an inventory adjustment;
- create, add/update a line, close, or cancel a stock-count session;
- create a store, receipt, or receipt line, approve or intentionally leave a
  receipt line unresolved, and commit a receipt;
- create a shopping list or line and update checked state.

Every aggregate update carries its current base revision. Cancellation is a
revisioned terminal count-session transition: it preserves the audit record,
creates no inventory movement, emits a `cancelled` change-feed projection, and
returns the stored result on an identical retry. Closing remains the only
count transition that reconciles confirmed observations into movements.

Commands are dispatched through the same tenant-authorized application
services as the ordinary HTTP resources. The synchronization layer does not
duplicate inventory or purchasing business rules.

The unresolved receipt-line decision is revision-bound and durable. It clears
any selected home product, preserves the raw description, quantity, pack text,
and prices, supersedes an earlier approved match, and emits authoritative line
and receipt projections. Receipt commit requires every line to be terminal:
approved lines create their idempotent price and inbound-movement effects;
unresolved lines remain attached to history and create neither effect.

## Push and lost-response recovery

`POST /api/v1/homes/{homeId}/sync/push` accepts 1–100 operations. Operation IDs
are globally unique and bound to their original home, user, device, command,
and canonical request hash. An identical retry returns the stored result;
changed reuse is a conflict.

`POST /api/v1/homes/{homeId}/sync/operation-status` accepts 1–100 operation
IDs and returns only receipts belonging to the authenticated home, user, and
device. Clients may query it after an interrupted response or retry the exact
immutable operation and batch IDs. Neither path can apply the mutation twice.

Results are `accepted`, `conflict`, `validation_error`,
`authorization_failure`, or `retryable_failure`. A client acknowledges local
intent only after an accepted result.

## Bootstrap and incremental pull

The first incremental pull without a cursor returns `410
sync_resync_required`. The client obtains a consistent snapshot from
`GET /api/v1/homes/{homeId}/sync/bootstrap`.

Bootstrap is paged over a frozen high-water sequence. Each continuation cursor
is signed, home-bound, expiring, and carries the last entity sort key. The
incremental cursor is returned only on the final page. This prevents a large
home from receiving a partial or mixed-time snapshot.

Incremental pull uses a signed cursor containing home, position, frozen
high-water position, expiry, and version. Pages are sequence ordered and never
cross the frozen boundary. The client commits a page and its cursor atomically.
When the position reaches the boundary, the next pull captures a newer
high-water value.

Expired, compacted, wrong-home, or invalid cursors fail closed. A resnapshot
replaces only synchronized projections and replays durable unacknowledged
local intent.

## Deletion, retention, and compaction

Deletes emit revisioned payload-free tombstones. The supported offline window
defaults to 90 days and tombstone retention to 120 days; configuration rejects
a retention window shorter than supported offline operation. Compaction
records the minimum available cursor so an older device receives a required
resnapshot rather than silently missing a deletion.

## Conflict and client invariants

- Local mutation and durable outbox command commit in one Drift transaction.
- Client clocks are diagnostic only; server revisions and sequence allocate
  order.
- Membership and role changes remain server-authoritative.
- Revision conflicts preserve local and remote representations for explicit
  user resolution.
- Closed and cancelled counts are terminal facts.
- Stock movements remain append-only; balances are rebuildable projections.
- Access and refresh credentials never enter Drift or synchronization
  payloads.

## Verification and operational evidence

Repository tests cover closed command shapes, cross-home/device binding,
idempotent receipts, paged snapshots, cursor expiry, tombstone retention,
operation-status isolation, typed command dispatch, lost-response retry, and
deterministic two-device convergence. CI runs the backend suite on SQLite,
MySQL, and MariaDB and exercises Redis and Valkey queue profiles.

`SyncMetricsProbe` contributes privacy-safe synchronization counts to the
metrics surface. Labels contain no household payload, receipt text, media,
token, credential, or private entity value.

Physical-device lifecycle, browser persistence, staging failover, and
multi-day offline evidence remain release-candidate acceptance activities;
their absence does not turn repository-tested behavior into a production
claim.
