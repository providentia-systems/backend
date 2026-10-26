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
current price comparisons. Cancelling a draft or committed receipt reverses its
remaining applied purchases atomically and increments the parent revision once.
Cancellation retains the receipt and movement history; it is not destructive
deletion. The existing HTTP command is
`DELETE /api/v1/homes/{homeId}/receipts/{receiptId}` with its current revision;
the durable outbox command is `purchasing.receipt.cancel`.

## Omitted and unresolved lines on a finished receipt

A committed receipt can be completed through the dedicated audited correction
command without reopening it or recreating its already-applied purchases:
`POST /api/v1/homes/{homeId}/receipts/{receiptId}/line-corrections`. This is a
backend capability; the paired Client must provide the corresponding workflow
before an installed user can rely on it.

The request supplies a stable `correctionId` and `lineId`, the current
`expectedReceiptRevision`, a reason, and the complete reviewed purchase fields:
`homeProductId`, nullable `packId`, `rawDescription`, `quantity`, nullable
`originalPackText`, nullable `unitPrice` and `lineTotal`, `unit` and
`stockQuantityPerPack`. Supply `expectedLineRevision: null` for a new omitted
line, or the current line revision for an existing unresolved, nonapplied line.
Both prices may remain null when the purchase is known but its price is not;
unknown pricing is not replaced with zero. Obtain product/pack factors from
authoritative metadata; do not invent conversions from display text.

The durable synchronization equivalent is `purchasing.receipt-line.correct`.
Its `entityId` is the line ID and its `baseRevision` is the expected **receipt**
revision. The payload contains `receiptId` and the same correction fields above,
including nullable `expectedLineRevision`, but omits `lineId` and
`expectedReceiptRevision` because the envelope supplies them. Preserve both the
operation ID and correction ID on retry; do not substitute the line revision
for the parent revision.

The receipt remains committed throughout. The correction validates the home,
permissions, product/pack/unit snapshot and both revisions, records before/after
audit evidence, approves only the corrected purchase, and applies its stock and
price effects in one transaction. Stock evidence uses the receipt's effective
purchase day, including any earlier audited date correction; chronology
determines the resulting current balance. Existing applied lines and movements
are retained and are not replayed. Use the existing applied-line correction
workflow for a line that already affected stock.

Retry the same immutable request with the same `correctionId` after a lost
response. An accepted identical correction returns its retained result without
another stock effect. Reusing that identity with changed fields produces a
conflict. A new correction with stale receipt or line revisions, including one
that lost a concurrent race, also conflicts; reload and review the authoritative
receipt rather than silently overwriting it. An interrupted transaction leaves
no partly reopened receipt to finish.
Read audit history using
`GET /api/v1/homes/{homeId}/receipts/{receiptId}/line-corrections` and reconcile
the receipt, line and balance through synchronization readback.
Audit records retain the correction and line IDs, reason, receipt revision,
nullable original line, corrected line, actor and correction timestamp. The
existing date-correction history retains changes to the receipt's effective
purchase day.

The explicitly declared receipt total is not overwritten by a line correction.
If the declaration itself was wrong, use the ordinary revision-checked receipt
metadata update. Reports distinguish this declaration from derived line spend
and pricing completeness.

## Receipt metadata and purchase-date corrections

Store, notes and total can be corrected after purchase entry or completion. Store
corrections update the receipt's associated price evidence without adding stock.
Ordinary metadata saves cannot change purchase date or currency after stock was
applied, or on a committed receipt. Currency remains fixed. To correct the day
of an active draft or committed receipt, use the existing audited
`POST /api/v1/homes/{homeId}/receipts/{receiptId}/date-corrections` command
(`purchasing.receipt.correct-date` in the durable outbox), with the current
receipt revision and a reason. Dates are `YYYY-MM-DD` calendar days validated
against the household timezone; future days are rejected. The correction keeps
original receipt/line identities and audit evidence, supersedes affected price
observations and reconciles stock chronology without adding another purchase.
Read the audit using `GET` on that same date-corrections path. Cancelled receipts
cannot have their day corrected. Source identity, permissions, tenant boundaries
and optimistic revision checks remain intact.

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
