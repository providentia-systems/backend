# Purchase entry, pack quantities and corrections

Manual purchase entry uses `purchasing.receipt-line.purchase` through the durable
protocol-v2 synchronization outbox. The HTTP equivalent is
`POST /api/v1/homes/{homeId}/receipts/{receiptId}/purchases`; callers provide a
stable line `id` and reuse it when retrying. Both paths require purchase-write
permission and the receipt's current revision. A deliberately selected home
product and pressing Add provide human confirmation; AI/import proposals remain
unreviewed until the existing explicit review workflow confirms them. Imported
review may supply packId, unit and stockQuantityPerPack together to snapshot
pack conversion without applying stock until completion.

Each confirmed manual addition creates an approved line, immutable purchase
movement, inventory balance update and synchronization records in one transaction.
The receipt stays draft so another item can be added immediately. Completing the
receipt skips already-applied lines and applies only the remaining reviewed
imports. Retrying completion or an accepted synchronization operation cannot
increase stock twice. Prices remain required: provide a unit price or line total.
The server does not substitute zero for an unknown price.

`quantity` retains the purchased pack count when `packId` is supplied. Without a
pack, it means stock units and any original pack description is retained as
purchase evidence. `stockQuantity` is the actual applied inbound quantity;
`stockQuantityPerPack` records the exact conversion snapshot. Four 1 kg bags and
one 4 kg bag both add 4 kg to a kilogram-tracked product while retaining distinct
packaging and purchase quantities. Selecting an alternate published pack is
allowed when it belongs to the same canonical product.

Kilograms/litres divide normalized grams/millilitres by 1000. Grams/millilitres
use the normalized amount directly. Products tracked in `units` with an attached
catalog pack use that pack as the stock base; a compatible alternate pack is
converted by the ratio of normalized measures. A family tracked in units without
a base pack accepts count packs; mass and volume packs require an explicit stock
measurement or a compatible base pack. Incompatible dimensions and conversions
that cannot be represented exactly within eight decimals fail without partial
writes. No binary floating-point arithmetic is used.

The purchase request carries the expected `unit` and `stockQuantityPerPack` from
cached product and pack facts. The backend recomputes them and returns a revision
conflict if another device changed the stock unit or canonical measure. An offline
purchase must never silently change its meaning while waiting to synchronize.

Existing line-update/removal operations correct applied purchases in draft or
committed receipts. They preserve the chosen product and snapshotted conversion,
append only the stock difference, and retain every earlier movement. Removing a
line sets its applied stock effect to zero and preserves the original product,
pack and entered quantity. Corrected price observations supersede older evidence;
older observations remain available in export/history and are excluded from
current price comparisons. Cancelling a draft reverses all of its immediately
applied purchases atomically and increments the parent revision once.

Store, notes and total can be corrected after purchase entry or completion. Store
corrections update the receipt's associated price evidence without adding stock.
Purchase date and currency cannot change after stock was applied. Source identity,
permissions, tenant boundaries and optimistic revision checks remain intact.

Migration `Version20261013000100` adds nullable packaging/conversion snapshots and
price-evidence supersession. Historical committed imports are backfilled using the
numeric quantities originally applied, with conversion factor 1; their old stock
meaning is never retroactively interpreted from current catalog pack metadata.
Downgrade is refused when immediate/converted stock snapshots or multiple price
observations would be lost. Fresh databases and unchanged legacy data can
rollback/reapply; deployments with new purchase history must retain the upgraded
schema or restore their pre-upgrade backup.

Client local stock projections represent pending purchases immediately. The
synchronization acknowledgement and authoritative balance feed confirm those
projections; they do not append another local purchase quantity. Pending receipts
can coexist, so a stalled receipt does not block another shopping session.
