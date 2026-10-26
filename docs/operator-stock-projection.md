# Operator stock records

The operator household `stock` collection preserves every existing balance row
and its original fields, including `home_id`, `home_product_id`, signed
`quantity`, revision, timestamps and chronology metadata. Reading the collection
does not create balances or edit stock. Rows remain ordered by
`home_product_id`, with 100 rows per page.

`quantity` and `pending_quantity` use decimal strings consistently across
database drivers, including zero, signed fractions and very small quantities.

The response additionally resolves the same readable metadata as the Products
collection: `name`, `brand`, `pack_text`, `category_name`, `category_scope` and
`catalog_reference`. Private names and pack descriptions take precedence over
the shared catalog. Local category references take precedence over household
global category overrides and the catalog product's inherited category.

Stock rows also expose `product_id`, `pack_id`, `home_category_id`,
`global_category_id`, `unit`, `home_product_status`, `catalog_product_status`
and `catalog_pack_status` for support. These fields describe linked records;
the balance's original revision and timestamps retain their meaning.

Archived product and pack references retain their readable metadata and expose
their statuses. Missing household or catalog references remain visible with
explicit fallback text. A catalog pack contributes metadata only when it belongs
to the referenced catalog product. A local category contributes its name only
when it belongs to the selected household. A balance pointing to a different
household's product is represented as a missing home product, without exposing
that other household's private metadata.

The fields are additive. Existing consumers of raw balance fields can continue
using them; the paired Admin adapter must use the resolved columns to present
readable stock rows. No separately paged Products fetch is required to identify
each balance.
