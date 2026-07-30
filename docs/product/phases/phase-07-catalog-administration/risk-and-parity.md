# Phase 7 risk and parity record

## Delivery parity

| Master-prompt capability | Phase 7 implementation | Evidence |
|---|---|---|
| Product proposal workflow | Explicit, exact-shape product, pack, alias, and barcode proposals | OpenAPI 1.6 and proposal sanitization tests |
| Curator/reviewer workbench | Server-derived platform roles and six bounded queues | Authorization matrix and workbench handler |
| Duplicate and alias review | Deterministic conflicts; keep-existing requires reason and revision | Conflict store and runbook |
| Icon management | Content-addressed metadata with size, type, provenance, and revisions | Icon endpoint and policy validation |
| Reversible merges | Preview, all-record revision checks, relink ledger, redirect, reversal | Merge integration test |
| Catalog audit history | Immutable catalog revisions plus global audit events | Governance store |

The legacy pantry application had no equivalent global moderation surface.
These capabilities are therefore additive; they do not replace an
owner-approved legacy behavior.

## Active risks and controls

| Risk | Control | Residual/next action |
|---|---|---|
| Household data leaks into a global proposal | Exact allowlists, forbidden-field test, no home foreign key | Review every future proposal type against this boundary |
| Alias or barcode takeover | Exact conflict queues; conflicted proposal cannot be approved | A corrected proposal is required to choose a different identity |
| Concurrent moderation overwrites a decision | Optimistic revisions inside one transaction | Clients must refresh on `409` |
| Merge creates ambiguous children | Pairwise variant, normalized-pack, and approved-alias collision gates | Resolve collisions before re-preview |
| Redirect chains make old IDs or reversal ambiguous | A survivor with incoming active redirects is ineligible | Revisit only with a formally tested redirect-chain design |
| Reversal overwrites newer relinks | Every ledger reference must still point to the survivor | Manual review is required if any reference changed |
| Unsafe public asset is served | API stores only bounded content-addressed metadata | Deployment asset scanning remains an operator responsibility |
| Catalog role implies tenant access | Catalog and home authorization are independent | Keep cross-home denial tests in the quality gate |

## Explicit decisions

- Catalog sharing is per-item opt-in; unknown household items are never
  published automatically.
- Catalog workers see global identities and aggregate relink counts only.
- Product rows and history are retained; merge is a status/redirect operation,
  never a destructive delete.
- Name similarity and AI output can inform a human but cannot authorize a
  merge.
