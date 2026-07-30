# Phase 7 merge and conflict runbook

## Conflict queues

Use `GET /api/v1/catalog-admin/workbench?queue=...` with one of:

- `proposals` — clean and conflicted proposals;
- `duplicates` — exact normalized product/pack identity conflicts;
- `aliases` — an approved global alias already exists;
- `barcodes` — a barcode already belongs to a pack;
- `icons` — published products missing active icon metadata;
- `merges` — applied and reversed merge history without internal relink IDs.

For a conflict, compare only global catalog identities. `keep-existing` records
the reviewer, reason, time, and optimistic revision; it resolves the conflict
and rejects the proposal. A new, corrected proposal is required when the
existing global identity should not win. This conservative checkpoint does not
silently reassign aliases or barcodes.

## Merge preparation

1. Search both canonical products and their packs.
2. Confirm the records represent one real product, not merely similar names.
3. Choose the stable, best-described product as survivor.
4. Call the preview endpoint with one to twenty distinct duplicate IDs.
5. Review product names, brands, revisions, collision codes, and aggregate
   affected counts.
6. Resolve every reported variant, pack, alias, or redirect-chain collision
   outside the merge.
7. Re-preview immediately before apply.
8. Apply with the survivor revision, a revision for every duplicate, and a
   concise reason.

Never infer a merge from AI output or name similarity alone. Brand, meaningful
variant, form, flavour, pack identity, and the 19 authoritative identity rules
remain governing evidence.

## Reversal

Use the merge event revision from the `merges` workbench. Reversal checks that
each recorded reference still points at the survivor. If any reference changed,
the transaction stops without partial restoration.

After reversal:

- duplicate products return to `published`;
- redirects become `reversed`;
- variants, packs, aliases, icon targets, and opaque home-product references
  return to their original product;
- the survivor remains published with a new revision;
- merge/reversal catalog revisions and global audit events remain immutable.

## Incident response

For a suspected poisoned proposal, alias takeover, barcode conflict, or unsafe
icon:

1. reject or retain the existing identity;
2. stop before applying a merge;
3. archive the public asset in the external asset store if relevant;
4. preserve the proposal, conflict, catalog revision, and audit IDs;
5. rotate reviewer/curator sessions if account compromise is suspected;
6. reverse only through the endpoint—never by deleting products or editing
   foreign keys manually.
