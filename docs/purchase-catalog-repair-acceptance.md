# Purchase and catalog repair acceptance

Status: implementation in progress on `fix/2026-10-purchases-catalog-sync`. This document records
acceptance targets; it does not claim that the targets already pass.

## Coordinated scope

- Admin product search pages start at their first product; selected-product
  pack creation and editing use the existing guarded catalog maintenance API.
- Canonical metadata updates reach linked home products through the existing
  synchronization and item-master refresh, respecting private overrides.
- A deliberately selected manual purchase adds stock immediately while its
  receipt remains open for additional items. Prices remain required.
- Pack quantities retain packaging history and use explicit compatible stock
  measurements. Four one-kilogram packs and one four-kilogram pack each add
  four kilograms to kilogram-based stock, without guessing unknown units.
- Store creation is visible from receipts; metadata and stock corrections
  preserve purchase history and never duplicate a purchase.
- Pending receipts and invalid cursors recover without losing queued intent
  or blocking subsequent receipt entry.
- PDF/photo import uses existing consent-bound extraction, visible progress,
  editable proposals and explicit confirmation before stock effects.

## Required evidence

Exercise normal entry, pack conversion, corrections, cancellation, stable
retry identities, offline/server reconciliation, a second device receiving
updates, pagination and synthetic receipt import. Keep existing authorization,
exact-decimal, revision, architecture, privacy, coverage and build gates active.
All applicable original GitHub workflows must pass on the final PR head before
this draft is marked ready for review. Merge is left to the owner.

## Repository responsibility

Backend owns authoritative purchase rules, persistence, migrations, synchronization representations and the canonical API contract.
