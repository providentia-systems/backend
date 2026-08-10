# Phase 7 — governed global catalog administration

Status: delivered backend governance capability, with the Phase 9 consent-bound
contribution channel layered alongside it.

Phase 7 turns the seeded global catalog into a moderated, auditable system
without giving catalog staff access to household data. Authenticated users can
explicitly submit one sanitized product, pack, alias, or barcode proposal.
Platform reviewers moderate clean proposals and conservative conflict queues;
curators manage public icon metadata and preview, apply, or reverse canonical
product merges.

## Delivered surfaces

- Exact, type-specific sanitized proposal contracts
- Deterministic duplicate, alias, pack, and barcode conflict detection
- Reviewer workbench queues for proposals, conflicts, missing icons, and merges
- Separate `catalog_reviewer`, `catalog_curator`, and
  `platform_administrator` authority
- Operator-only, audited platform-role grant/revoke command
- Moderated publication of products, packs, aliases, and barcodes
- Content-addressed public icon metadata with optimistic revisions
- Non-mutating merge previews with conflict reasons and aggregate counts
- Transactional, audited, reversible product merges and permanent redirects
- Redirect-aware canonical product reads for existing clients
- OpenAPI 1.6 contracts and tenant/privacy regression gates

## Catalog/home separation

Catalog administration APIs never return home IDs, home-product IDs,
quantities, household locations, receipts, lists, notes, AI settings,
credentials, or private media. A merge may internally relink
`home_products.product_id` so existing households keep their history, but the
curator sees only an aggregate `homeReferences` count.

The later contribution channel is deliberately separate from canonical
proposal/merge governance. It permits only independently consented and
moderator-approved product identity, public product-image metadata, and store
price facts. Its moderator and public DTOs contain no contributor or household
attribution, and the public projection excludes pending, rejected, withdrawn,
and unsupported rows.

Proposal submission is explicit per item. Providentia does not automatically
publish unknown household products and does not silently deduplicate by name.

## Reading order

1. [Governance architecture and flows](architecture-and-flows.md)
2. [Merge and conflict runbook](merge-and-conflict-runbook.md)
3. [Local and remote setup](local-and-server-setup.md)
4. [Acceptance checklist](acceptance.md)
5. [Risk and parity record](risk-and-parity.md)

Phase 7 does not use household activity to train or rank catalog items.
Movement-derived suggestions and reporting remain Phase 8 responsibilities.
