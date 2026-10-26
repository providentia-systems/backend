# Purchase amounts and completeness

Purchase reports and recent purchase summaries use the same receipt spend
calculation. Only committed receipts in the selected home and calendar-date
range participate. Draft and cancelled receipts are excluded. Removed receipt
lines are excluded; unresolved lines remain eligible because their prices can
be known independently of their stock mapping. Reads never change stored prices,
stock movements, revisions or price observations.

An explicitly declared receipt header total is the complete receipt spend,
including an intentional zero or receipt discount. Without a declared header,
each eligible line uses its explicit line total first. Only when that total is
missing is quantity multiplied by unit price, using exact decimal arithmetic
and rounding each derived line to two decimal places with `HALF_UP`. Explicit
discounted line totals are preserved even when they differ from multiplication.
Known line amounts are added exactly after this per-line derivation; totals are
serialized as decimal strings without insignificant trailing zeros.

Without a header, all eligible lines must have known prices for the complete
receipt spend to be available. A partly priced receipt has a null `totalAmount`
and an explicitly partial `knownSubtotal`. A wholly unpriced receipt, or an empty
receipt without a declared total, has both values null. Neither is a complete
zero. A fully priced free purchase has total `"0"` and `pricingComplete: true`.
For a declared receipt, `knownSubtotal` is the declared spend rather than a
potentially conflicting product subtotal.

## Public aggregates

Monthly report rows are grouped by calendar month, currency and store. Recent
summary currency rows are grouped by currency. No operation combines currencies.
For the same home, dates and currency, summing complete report rows agrees with
the corresponding summary currency row. A recent summary covers its inclusive
UTC calendar cutoff through today; a report uses its explicit inclusive dates.

Each aggregate exposes:

| Field | Meaning |
| --- | --- |
| `receiptCount` | All included receipts, including unpriced receipts. |
| `total` (report) / `spend` (summary currency row) | Complete decimal spend, or null if any included receipt is incomplete. |
| `knownSubtotal` | Sum of available effective receipt amounts, including known portions of partial receipts; null if no amount is known. |
| `pricingComplete` | Whether every included receipt has complete spend. |
| `amountSource` | `declared` when all receipts use declared totals; `derived` when all use complete line subtotals; `mixed` when both complete sources occur; `partial` when incomplete receipts coexist with known amounts; `unknown` when no amount is known. |
| `completeReceiptCount` | Receipts with complete spend. |
| `partialReceiptCount` | Receipts with some priced lines and some missing prices, without a declared total. |
| `unknownReceiptCount` | Receipts with no known amount. |
| `declaredReceiptCount` | Receipts whose declared header is authoritative. |
| `derivedReceiptCount` | Receipts whose complete eligible line subtotal is authoritative. |

Monthly rows retain `month`, `currency`, nullable `storeId` and nullable
`storeName`. Summary currency rows have `currency` and `lineCount` in addition
to the fields above. `lineCount` counts eligible lines, including unpriced lines.

The recent summary envelope retains `receiptCount`, `lineCount` and `spend`, and
adds nullable `currency`, `pricingComplete`, and `currencyTotals` (the currency
rows above). Envelope `spend` is available only for one fully priced currency;
it is null for multiple currencies or incomplete pricing. An empty summary has
`spend: "0"`, `currency: null`, `pricingComplete: true` and `currencyTotals: []`.
Consumers must render null complete totals as unavailable or incomplete and can
show `knownSubtotal` with its partial label and counts.

Header/date/line corrections are reflected directly from the current receipt
and eligible line rows. The joined facts query reads headers and line prices in
one database snapshot; it does not combine independently read revisions.
