# Catalog import safety contract

Catalog import is a staged home-catalog workflow. It does not directly publish global catalog data and it does not import inventory quantities or prices.

## Supported rows

Clients submit a JSON `records` list. This also supports CSV-derived data after the client normalizes each source row into one of these explicit record types:

- `catalog_product_reference`: requires a published global product match by `productId`, `packId`, `barcode`, or normalized `name` and optional `brand`.
- `home_product`: links a matching global product when possible, otherwise stages a private home product using `privateName` or `name`.

The allowed string fields are `recordType`, `productId`, `packId`, `barcode`, `name`, `brand`, `privateName`, and `packText`. Unknown fields are row errors. Quantity, stock, price, currency, and store-price fields are always rejected as `unsupported_mutation`; those values require their dedicated inventory or price workflow and its authorization and consent rules.

## Staging and confirmation

Staging is bounded to 500 rows and 1 MiB of normalized JSON and requires an 8–128 character `Idempotency-Key`. Reusing a key with identical content returns the original batch; reusing it with different content returns a conflict.

Rows are resolved against published product and pack identifiers, barcodes, normalized global identities, and the requesting home's existing catalog. Ambiguous identities and duplicates are reported per row. Staging writes only the import batch and its review rows.

The client must review the staged rows and then supply both the current `expectedRevision` and the exact confirmation value `apply_catalog_records`. Confirmation serializes imports per home, rechecks catalog publication and home-level duplicates, and creates only the valid home-catalog records. Each created record is written to the protocol-v2 synchronization change feed in the same database transaction. Invalid rows remain part of the immutable review evidence. Re-delivering a completed confirmation is safe.

## Integration routes

The route and OpenAPI integration pass should register these authenticated handlers:

| Method | Path | Service key | Purpose |
|---|---|---|---|
| `POST` | `/api/v1/homes/{homeId}/catalog-imports` | `catalog.imports.stage` | Stage `records` with `Idempotency-Key` |
| `GET` | `/api/v1/homes/{homeId}/catalog-imports/{importId}` | `catalog.imports.get` | Read the revisioned review result |
| `POST` | `/api/v1/homes/{homeId}/catalog-imports/{importId}/confirm` | `catalog.imports.confirm` | Apply reviewed valid catalog rows |

All operations require the configurable `catalog.import` home permission. Owner, manager, and member roles receive it by default; home permission overrides can remove it. Batch lookup and idempotency are scoped by `home_id`, preserving the API's cross-home `404` posture.
