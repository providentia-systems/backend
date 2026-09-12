# Product lifecycle integration — API 2.1.0

This branch coordinates `providentia-systems/backend`, `providentia-systems/client`
and `providentia-systems/admin`. It is implementation work under review, not a
production acceptance record. Deploy the matching backend before the two clients.

## Runtime contract

The authoritative backend OpenAPI archive is copied byte-for-byte into both
clients. API 2.1.0 has 176 paths, 210 operations and 249 schemas. The homeowner
facade exposes 159 operations; the isolated Linux Admin facade exposes 75.
Run each repository's materializer and generator checks; do not hand-edit the
generated Dart facades.

- Home category create/edit/archive/restore and private product name, pack-text,
  category and archive changes use protocol-v2 durable commands. Projection and
  outbox updates are atomic. A stale category revision is rejected locally;
  the backend remains authoritative for conflicts, quotas and active references.
- Catalog-backed home products allow category and archive changes without changing
  the shared product identity. Stock must be zero before archival; open counts
  and uncommitted receipts can also prevent archival. Ledger history is retained.
- Private records synchronize independently of public contribution consent. The
  authorized Admin home inspector reads them through audited operator routes.
  Its stock ordering uses `home_product_id`, matching the balance table key.
- Admin catalog maintenance lists, creates, revises, archives and restores global
  categories, products, packs, units, variants, aliases, barcodes and identity
  rules. PUT `/api/v1/catalog-admin/entities/{entityType}/{entityId}` takes
  `fields`, `status`, `expectedRevision` and an audit `reason`; revision zero
  creates with a client-generated UUID. Revisions and audit events share the
  transaction. Private aliases are excluded. Used units and packs cannot be
  reinterpreted; create another measurement identity instead.
- Newly created Admin products receive an unspecified selectable pack. A
  contribution-linked product approval publishes its pack and, when supplied,
  validated barcode atomically. Invalid or conflicting identifiers block the
  transaction rather than producing a partially published identity.
- The Stock workspace links directly to the existing consent-bound product
  contribution review flow. Synchronization is not public sharing: users still
  select and confirm the exact allowlisted public contribution.
- Reviewed AI stock candidates can create private products with a chosen home
  category. Human quantity confirmation still uses ordinary inventory commands.
  No provider tokens or raw media enter those commands.
- Shopping has an explicit Generate recommendations action using the existing
  statistical recommendation service. Reading cached suggestions does not
  trigger a generation mutation. The application reports a failed catalog cache
  refresh instead of calling the whole refresh successful.

## Validation and remaining acceptance work

The local environment has a verified Flutter SDK but runtime validation is not
complete. Automatic approval review rejected Flutter dependency resolution after
it attempted cloud instance metadata access. That operation was not retried or
bypassed. PHP, Composer, Docker and native Linux build dependencies are unavailable.
The required Flutter/PHP suites, database matrix, coverage, mutation checks and
packaged Linux launch must be green before these PRs can leave draft.

Regression tests cover category/product durable intent, tenant isolation, category
revision conflicts, operator stock projection, catalog lifecycle/audit, maintenance
authorization, and exact measure normalization with referenced-unit protection.
Contract generation, archive hashes and available structural/Node checks are
separate evidence, not a substitute for runtime or deployment acceptance.

The wider requested complete-CRUD acceptance is still open: dedicated operator
mutation workflows for household records, shopping quantity/provenance and durable
recommendation feedback, remaining receipt/location lifecycle controls, AI-generated
category extraction, and live provider/second-device/admin convergence must be
completed and verified. Historical stock movements and committed purchases require
explicit correction/reversal policies, not destructive generic table deletion.
No production deployment, paid AI activation, or automatic merge is authorized by
these branches.
