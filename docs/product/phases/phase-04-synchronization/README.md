# Phase 4 — offline synchronization

Phase 4 established the authenticated, home-scoped synchronization envelope,
typed private-note and home-preference policies, idempotent push batches,
ordered pull cursors, and bootstrap snapshots. The authoritative protocol and
security details remain in
[`docs/architecture/synchronization-protocol.md`](../../../architecture/synchronization-protocol.md).

Phase 5 resources are deliberately exposed first as normal online APIs. They
must receive their own typed synchronization policies before clients treat
them as offline-writable. A generic document payload is not an acceptable
substitute for inventory, receipt, or count invariants.

## Follow-through gates

- Add a paged bootstrap when a home can exceed one bootstrap response.
- Add an operation-status read endpoint for clients recovering from an
  interrupted push response.
- Add typed policies only after the corresponding domain transaction and
  conflict behavior are stable.
- Retain home and device binding, opaque signed cursors, payload size limits,
  batch limits, and per-operation classifications.

These follow-through items are cross-phase hardening work, not permission to
weaken Phase 4's existing security properties.
