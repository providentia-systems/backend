# Phase 7 acceptance checklist

## Governance

- [ ] Proposal submission requires an authenticated user and explicit opt-in.
- [ ] Product, pack, alias, and barcode payloads accept exact sanitized fields.
- [ ] Price, quantity, home, receipt, image, note, and stock fields fail.
- [ ] Exact deterministic conflicts enter the appropriate review queue.
- [ ] Conflicted proposals cannot be directly approved.
- [ ] Reviewer, curator, and administrator authority is server-derived.
- [ ] A home role alone grants no catalog-admin capability.
- [ ] Role grant/revoke is operator-only and audited.

## Catalog integrity

- [ ] Clean approvals publish a globally searchable canonical entity.
- [ ] Alias children and packs are verified against their product.
- [ ] Barcode uniqueness is enforced by the database.
- [ ] Icon metadata is content-addressed, bounded, and revision-controlled.
- [ ] Merge preview performs no writes and exposes only aggregate home links.
- [ ] Alias/variant collisions block merge.
- [ ] Apply checks the survivor and every duplicate revision.
- [ ] Every moved reference has an internal reversal-ledger entry.
- [ ] Old product IDs resolve to the survivor while the merge is active.
- [ ] Reversal restores all unchanged references or rolls back completely.
- [ ] No product, purchase, movement, count, or price history is deleted.

## Privacy and quality

- [ ] Workbench/API responses contain no home IDs, home-product IDs, quantities,
  prices, receipts, lists, notes, credentials, or media.
- [ ] Catalog revision and audit history records actor, reason, operation, and
  before/after state without household payloads.
- [ ] OpenAPI 1.6 matches every runtime route.
- [ ] Proposal sanitization, platform RBAC, merge/reversal, redirect, and tenant
  isolation tests pass.
- [ ] Clean-install, upgrade, rollback, MySQL, MariaDB, and SQLite migration
  suites pass in CI.
- [ ] Backup/restore smoke testing preserves redirect and reversal behavior.

## Consent-bound contribution extension

- [ ] Product identity, product image, and store price have separate default-off
  consent switches and immutable revisioned receipts.
- [ ] Submission uses the current matching receipt and strips quantity,
  location, receipt, note, household, and contributor fields.
- [ ] Review and decision remain restricted to server-derived reviewer,
  curator, or platform-administrator capability.
- [ ] Review queues omit home, user, source fingerprint, and receipt linkage.
- [ ] Public reads contain only approved supported types and a type-specific
  payload allowlist; pending, rejected, and withdrawn rows never appear.
- [ ] Disabling one consent category unpublishes only that category's approved
  facts, and re-enabling it does not revive withdrawn submissions.
- [ ] Platform catalog roles alone cannot access any private home resource.
