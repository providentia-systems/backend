# Phase 5 baseline reconciliation

The importer accepts only the two files whose SHA-256 values were approved in
Phase 0:

| File | SHA-256 |
|---|---|
| `pantry-data.json` | `ac2a74f267d7a48a460c8fae24515887f97632cddfb4a17f5f45dd07c9e90116` |
| `product-rules.json` | `8131bd3bf41c9b70f0e4cfe86c9e7de699ca0df827c6287fc9f2927e35827899` |

The source commit is
`b01b5ef14783b4ad1c1bfc0be7ba0dba32629af8`.

## Hard reconciliation gates

| Evidence | Expected |
|---|---:|
| Item-master product-and-pack rows | 292 |
| Opening stock lines | 60 |
| Opening quantity | 159 |
| Catalog-linked opening lines | 23 |
| Private opening products | 37 |
| Recent purchase lines | 16 |
| Recent purchase spend | NAD 1,078.38 |
| Historical purchase lines | 452 |
| Monthly validation rows | 261 |
| Receipt groups | 9 |
| Imported purchase lines | 468 |
| Approved authoritative matches | 456 |
| Unresolved quarantined lines | 12 |
| Price observations | 16 |
| Alias groups / alias strings | 13 / 19 |
| Identity rules | 19 |
| Unresolved source descriptions | 8 |

Opening links require a unique normalized product, brand, and pack match.
Purchase-history approval requires an authoritative canonical product-and-pack
export that resolves uniquely. Ambiguous or absent links remain unresolved;
the importer never guesses.

Every source row receives a mapping record with a digest. Unresolved recent
purchase rows also receive a quarantine record containing the source payload,
reason, and resolution state. A completed run is keyed by home, verified source
commit, combined source digest, mode, and status.
