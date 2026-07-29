# Authoritative catalog seed and reconciliation

The global catalog import accepts only the two verified JSON exports from the
Phase 0 handover:

| Source | SHA-256 |
|---|---|
| `pantry-data.json` | `ac2a74f267d7a48a460c8fae24515887f97632cddfb4a17f5f45dd07c9e90116` |
| `product-rules.json` | `8131bd3bf41c9b70f0e4cfe86c9e7de699ca0df827c6287fc9f2927e35827899` |

The command refuses any checksum or shape mismatch. The dry-run acceptance
gate is exact:

| Gate | Expected |
|---|---:|
| item-master rows | 292 |
| distinct product names | 263 |
| distinct product/brand/pack/unit tuples | 292 |
| category labels | 22 |
| alias groups / aliases | 13 / 19 |
| identity rules | 19 |
| unresolved current-stock descriptions | 8 |
| `Pack size pending` rows | 9 |

Run it manually only from protected extracted evidence:

```bash
php bin/providentia catalog:seed \
  --data=/protected/pantry-data.json \
  --rules=/protected/product-rules.json \
  --dry-run

php bin/providentia catalog:seed \
  --data=/protected/pantry-data.json \
  --rules=/protected/product-rules.json
```

Import is transactional and source-key idempotent. The seed-run evidence row is
also unique by seed version and both verified source digests, so repeating the
same committed import has zero catalog or seed-evidence delta. It creates sanitized
catalog identity/pack data, verified aliases and identity rules, seed run
evidence, and quarantine rows for all eight unresolved descriptions. Missing
alias targets are failures rather than silent skips. Pending pack descriptions
remain explicitly pending; no pack amount is invented.

The repository does not contain the source exports, ZIP, household stock,
prices, locations, notes, receipt/media paths, or medical/private evidence.
