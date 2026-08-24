# Phase 7 — governed global catalog administration

Status: delivered backend governance capability, with the Phase 9 consent-bound
contribution channel layered alongside it.

Phase 7 turns the seeded global catalog into a moderated, auditable system
without giving catalog staff access to household data. Authenticated users can
explicitly submit one sanitized category, product, pack, alias, or barcode proposal.
Platform reviewers moderate clean proposals and conservative conflict queues;
curators manage public icon metadata and preview, apply, or reverse canonical
product merges.

## Delivered surfaces

- Exact, type-specific sanitized category/product/pack/alias/barcode proposal contracts
- Deterministic duplicate, alias, pack, and barcode conflict detection
- Reviewer workbench queues for proposals, conflicts, missing icons, and merges
- Separate `catalog_reviewer`, `catalog_curator`, and
  `platform_administrator` authority
- Operator-only, audited platform-role grant/revoke command
- Moderated publication of categories, products, packs, aliases, and barcodes
- Content-addressed public icon metadata with optimistic revisions
- Explicit homeowner product-image submission with a versioned public-reuse
  declaration, server-side WebP re-encoding, encrypted quarantine, moderator
  preview, and curator-selected product publication
- Immutable, digest-addressed public catalog image content after publication
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

The later contribution channel remains separate from canonical publication
while feeding that one governance path through durable links. It permits only
independently consented product identity, exact-pack store prices, and product
images. Its moderator and public DTOs contain no contributor or household
attribution. The contribution feed excludes product-image rows entirely;
clients consume an image only after a curator has selected a published product
and the canonical icon path has accepted the expected revision.

An image submission is bound to one active home product and one current image
consent receipt. The homeowner separately confirms the exact image and the
closed `homeowner_original_public_catalog_v1` rights declaration. The client
supplies the digest of the uploaded bytes; the backend verifies it, decodes and
re-encodes JPEG, PNG, or WebP input as metadata-stripped WebP, and records a new
digest for the sanitized bytes. The source digest remains in the homeowner's
private retry projection and is never returned to moderators or public reads.
Reviewers receive only an authenticated, no-store binary preview. Curators
publish to an explicitly selected canonical product, never a guessed target.

Rejected or pre-publication-withdrawn images lose their encrypted quarantine
bytes. Publication copies the sanitized content into a new attribution-free
public asset and removes quarantine. Consent withdrawal after publication
removes the household contribution from active sharing, but it cannot recover
household identity from or retroactively delete the already moderated,
attribution-free public asset. The immutable public URL is keyed by the
sanitized asset digest.

The first deployable storage adapter intentionally co-locates encrypted image
bytes and moderation metadata in the Catalog-owned relational database. This
lets contribution creation, rejection cleanup, icon CAS, and publication link
commit or roll back together without exposing another module's storage. The
use case depends only on `CatalogContributionImageStore`; a later
S3-compatible adapter can implement that same Catalog port with an outbox
without changing the application service. AI private-media storage is not
reused, and Catalog infrastructure never reads Inventory tables: the
Inventory-owned source reader implements the narrow source-ownership port.

An approved product-identity contribution can be linked by a curator to
exactly one ordinary product proposal using an explicit published category ID.
The proposal workbench/review remains the sole canonical publication gate. A
consent withdrawal before review makes the linked source ineligible; after
anonymized canonical publication it removes the contribution feed entry but
does not retroactively delete the public catalog fact or non-household audit.

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
