# Phase 4 — offline synchronization

Phase 4 established the authenticated, home-scoped synchronization envelope,
typed protocol-v1 and pantry protocol-v2 policies, idempotent push batches,
ordered pull cursors, paged bootstrap snapshots, operation-status recovery,
retention-aware tombstones, and privacy-safe metrics. The authoritative
protocol and security details remain in
[`docs/architecture/synchronization-protocol.md`](../../../architecture/synchronization-protocol.md).

Phase 5 inventory, receipt, count, and shopping mutations now have closed typed
protocol-v2 commands. A generic document payload remains prohibited.

## Delivered follow-through

- Paged bootstrap preserves one frozen snapshot boundary.
- Operation-status reads are bound to the authenticated home, user, and device.
- Typed pantry commands call the same application services as online writes.
- Count cancellation is a terminal revisioned command with no stock movement.
- Retain home and device binding, opaque signed cursors, payload size limits,
  batch limits, and per-operation classifications.

Physical-device, browser-lifecycle, failover, and multi-day offline evidence
remain production acceptance activities rather than missing repository
capability.
