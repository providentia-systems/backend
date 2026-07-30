# Phase 8 acceptance checklist

## Automated gates

- [x] Deterministic domain code has no infrastructure/framework dependency
- [x] Quantity and cost arithmetic is fixed-point, not binary floating point
- [x] OpenAPI and runtime route parity is structurally checked
- [x] Migration contains no known database-specific SQL construct
- [x] MySQL/MariaDB index lengths use bounded keys
- [x] Unit coverage exists for consumption, low evidence, decimal arithmetic,
  pack rounding, and currency isolation
- [x] CI applies, rolls back, and reapplies migrations on SQLite, MySQL, and
  MariaDB

## Evidence and behavior

- [x] Balances derive from the immutable movement ledger
- [x] Only complete reliable count scopes contribute consumption points
- [x] Backtests exclude facts created after each cutoff
- [x] Purchase cadence is optional and limitation-labelled when unavailable
- [x] Weak evidence falls back only to configured minimum reserve
- [x] Every run stores version, cutoff, watermark, confidence, coverage, and
  limitations
- [x] Pack conversions require compatible dimensions
- [x] Different currencies are never compared or totalled
- [x] Feedback preserves original and edited quantities
- [x] Long-lived policy writes use home roles and optimistic revisions
- [x] Reports and intelligence mutations write home-scoped audit events

## Manual release proof

- [ ] Execute the documented smoke path on MySQL with synthetic home data
- [ ] Repeat migration and core reads on MariaDB
- [ ] Prove viewer can read but cannot generate, edit policy, or send feedback
- [ ] Prove platform/catalog roles without membership cannot access reports
- [ ] Prove one home cannot read another home's run, explanation, policy,
  feedback, report, price, or backtest
- [ ] Verify an invalid calendar date and an incomplete count are rejected
- [ ] Verify a multi-currency result selects no implicit winner
- [ ] Review a backtest with unavailable support in the client
- [ ] Restore a backup in staging and reproduce an explanation

Release acceptance requires the automated workflow matrix and the manual
home-isolation proof. A green SQLite test alone is insufficient.
