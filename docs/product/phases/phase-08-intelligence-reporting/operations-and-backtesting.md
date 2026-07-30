# Phase 8 operations and backtesting

## Operational signals

Monitor these separately by home-safe aggregate labels; never put product
names, home IDs, prices, or reasons in metric labels:

- suggestion run duration, products evaluated, suggestions emitted, failures;
- count of low/medium/high confidence estimates;
- stale price-option count and multi-currency isolation count;
- feedback decision count and user override rate;
- backtest duration, evaluated rows, support, and unavailable metrics;
- report request count, duration, failure, and authorization denial;
- migration, database, queue, outbox, and readiness health.

Alert on repeated run failures, a sudden loss of eligible count evidence,
unbounded run growth, stale backtests, audit-write failures, or a sustained
increase in low-confidence suggestions.

## Backtest procedure

Use one to twelve fully historical cutoff dates and an evaluation window of
1–90 days. Every evaluation window must end before the request time.

For each cutoff the service:

1. reads only products, movements, counts, prices, and preference revisions
   known at that cutoff;
2. runs the same versioned estimator and suggestion formula used online;
3. observes committed purchases in the later evaluation window;
4. persists product-level outcomes and an aggregate support record.

| Metric | Interpretation |
|---|---|
| Precision | Fraction of suggested rows followed by a purchase |
| Recall | Fraction of later-purchased rows that were suggested |
| Missed stock-outs proxy | Later purchase without a suggestion |
| Overbuying proxy | Suggestion without a later purchase |
| User override rate | Edited, dismissed, or snoozed feedback / all feedback |

The two proxy metrics are not proof. Purchases may be planned, and absence of a
purchase does not mean the recommendation caused waste.

## Promotion gate

Do not replace `deterministic-replenishment-v1` merely because another formula
has a higher point estimate. Record:

- exact version and parameters;
- at least three representative historical windows when data permits;
- support, precision, recall, both proxies, and override rate;
- performance split by confidence band;
- evidence leakage review;
- cross-home authorization and currency-isolation tests;
- product-owner approval of changed fallback behavior.

If support is zero, precision is `unavailable`; it must not be displayed as
zero or one. Small samples stay visibly weak.

## Incident checks

For an unexpected suggestion, use its run ID:

1. confirm home scope and membership audit;
2. compare `asOf`, method/model versions, and watermark;
3. inspect factual ledger balance independently of the projection;
4. inspect the reliable count intervals and inbound movements;
5. inspect the policy revision active at the run time;
6. inspect pack conversion, currency, observed price time, and limitations;
7. reproduce with the same version and cutoff before changing data.

Never “repair” a result by editing an immutable run. Correct the underlying
ledger through an audited reversal/adjustment, revise policy optimistically, or
publish a new model version and generate a new run.

## Data retention and recovery

Phase 8 does not invent an automatic deletion window. Back up intelligence
tables with the movement, receipt, preference, membership, and audit tables
needed to interpret them. A restore test must prove one explanation, one
feedback record, one report audit, and one backtest result for the same home.
