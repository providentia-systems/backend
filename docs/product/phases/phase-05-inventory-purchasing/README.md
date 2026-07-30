# Phase 5 — inventory, purchasing, and shopping

Status: implementation checkpoint.

Phase 5 introduces the first complete household-stock vertical slice. Stock is
derived from an append-only movement ledger; count sessions reconcile observed
physical quantities; manually entered receipt lines require human matching
before commit; approved receipt lines create idempotent inbound movements;
shopping lists use optimistic revisions; and the verified v1 evidence is
imported through a checksum-gated, replay-safe command.

## Delivered surfaces

- Home item master and catalog/private product selection
- Home locations, stock balances, movements, and administrative rebuild
- Open/close physical count sessions with revision-checked lines
- Draft receipts, explicit line matching, commit, history, store, and price data
- Manual shopping lists and checked-line state
- Clearly labelled legacy-parity suggestions pending Phase 8
- Home dashboard projections through module-owned read interfaces
- `baseline:import` dry-run, commit, reconciliation, quarantine, and replay
- OpenAPI 1.4 routes and schemas
- One-command local baseline setup after the verified handover is supplied

## Non-negotiable stock rule

The verified 60-line physical count is the cutover boundary. Historic and
recent purchase evidence is imported for history, matching, price, and later
analytics, but it does **not** create additional stock-in movements. Doing so
would double-count purchases already reflected by the physical count.

## Reading order

1. [Architecture and interaction flows](architecture-and-flows.md)
2. [Baseline reconciliation](baseline-reconciliation.md)
3. [Local and server setup](local-and-server-setup.md)
4. [Acceptance checklist](acceptance.md)

Phase 5 intentionally does not claim AI extraction, catalog governance, or
forecasting. Those responsibilities belong to Phases 6, 7, and 8.
