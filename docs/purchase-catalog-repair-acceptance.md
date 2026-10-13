# Purchase and catalog repair acceptance

## Implemented workflow

- Admin product search pages and new searches start at the first product.
  Selected products expose Add pack size and Edit pack size using the existing
  catalog editor, current revisions, curator permission and audit controls.
- Sync now uses the existing household synchronization and item-master refresh.
  Linked products receive canonical metadata while private overrides are retained.
  The status displays progress, errors and the last successful synchronization.
- Selecting a product, its compatible pack or stock measurement, quantity and
  price, then Add immediately increases local stock and queues one confirmed
  purchase. The same receipt remains open for the next item.
- Finishing a receipt does not apply confirmed manual purchases twice. Imported
  proposals stay reviewable and have no stock effect until confirmation/finish.
- Explicit pack facts and stock-unit snapshots preserve packaging and conversion:
  four one-kilogram packs and one four-kilogram pack add four kilograms to stock
  measured in kilograms. Unknown or incompatible measurements are rejected.
- Add store is available beside receipt store selection. Independent receipts
  remain selectable, including pending receipts. Store corrections preserve
  purchase history; supported item corrections retain movement evidence.
- Typed invalid cursor recovery obtains a currently authorized snapshot without
  deleting pending purchases or changing their stable retry identities.
- Purchases opens receipt-focused camera/photo/PDF import with progress, clear
  setup failures, explicit consent, missing-price correction and human review.

## Repository responsibility

Authoritative purchase commands, exact measurements, retained stock and price history, authorization, migrations and the canonical API contract.

## Acceptance and release evidence

Regression tests cover additive stock, exact pack conversions, corrections,
retained history, retry protection, pending/server reconciliation, tenant
isolation, catalog paging and synthetic receipt extraction/review. Existing
architecture, privacy, coverage, build and clean-host startup gates remain
active. The final PR's GitHub checks are the release evidence; earlier passing
commits do not certify a later commit.

The usability target is ten familiar purchases in a couple of minutes.
Installed deployment versions, live provider credentials and a timed household
session require acceptance with the matching deployed applications. The PRs do
not change production data or deploy an application.

Merge/deploy the backend with migration Version20261013000100 before rebuilding
the matching Client and Admin. Preserve existing data and pending operations;
resetting household data is not an upgrade procedure. Review the backend
purchase correction guide for historical-data downgrade restrictions.
