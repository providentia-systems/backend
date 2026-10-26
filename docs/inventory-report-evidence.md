# Inventory quantities and evidence

The inventory report contains active household products, including products
that have never had a balance recorded. It does not rebuild stock or create a
count. Its `quantitySemantics` is
`recorded-ledger-balance-with-evidence-state`.

| Field | Meaning |
| --- | --- |
| `factualQuantity` | Signed decimal ledger quantity, or `null` when no balance exists. The historical field name does not certify a current physical count. |
| `quantityState` | `unknown` when no balance exists; `uncertain` when the recorded projection has chronology uncertainty; otherwise `recorded`. |
| `stockUnit` | The household product's stock unit. |
| `uncertain` | Stored chronology uncertainty; a missing balance is separately identified by `quantityState`. |
| `pendingQuantity` | Signed quantity awaiting chronology resolution, or `null` when there is no balance. It is not added to `factualQuantity` by the report. |
| `limitations` | Existing chronology explanations. A missing balance has an explicit no-recorded-balance explanation. |
| `checkpointId` | Stored accepted count checkpoint reference, if present. It identifies supporting evidence and does not assert that today's physical stock was observed. |
| `balanceRevision`, `lastMovementId`, `balanceUpdatedAt` | Existing ledger projection identity and time; all are `null` when no balance exists. |

Recorded zero and unknown are distinct. Negative and fractional balances remain
visible without clamping or converting them into a physical observation. A
recorded balance without uncertainty still describes the ledger; consult the
checkpoint and limitations before making claims about physical stock.

Private product names and pack descriptions retain their current precedence.
Inactive household products remain excluded. Threshold preferences are separate
configuration and do not establish stock evidence. Report generation records its
ordinary access audit, but does not change balances, movements, counts or the
synchronization queue.
