# Phase 8 — deterministic intelligence and household reporting

Status: implementation checkpoint.

Phase 8 replaces the legacy fixed-threshold suggestion with an explainable,
home-scoped recommendation service. It treats the movement ledger as fact,
derives consumption only from complete reliable count intervals, keeps every
run immutable, and tells the client when evidence is weak. It also adds
household reports and leakage-safe historical evaluation.

## Delivered surfaces

- Movement-derived factual balances and an explicit inventory-balances API
- Versioned consumption estimates with coverage, cadence, confidence, and
  limitations
- Deterministic replenishment using demand plus reserve minus usable stock
- Fixed-point pack conversion and price comparison within one currency only
- Immutable suggestion runs, explanations, feedback, and audited policy edits
- `always keep`, `never suggest`, preferred-pack, lead-time, coverage, and
  snooze controls
- Inventory, purchase, consumption, and suggestion household reports
- Walk-forward backtesting with knowledge-time cutoffs and explicitly labelled
  missed-stock-out and overbuying proxies
- Audited report access, recommendation runs, feedback, policies, and
  backtests
- OpenAPI 1.7 client contracts and cross-database migration coverage

## Non-negotiable semantics

`stock_movements` is the source of factual quantity. `inventory_balances` is
only a rebuildable read projection. An estimate is not a fact, a confidence
score is not a probability, and a later purchase is not proof that a home
stocked out.

No recommendation or report joins homes. Prices are private to one home and
amounts in different currencies are never totalled or ranked together.

## Reading order

1. [Architecture and interaction flows](architecture-and-flows.md)
2. [Algorithm and evidence rules](algorithm-and-evidence.md)
3. [Local and remote setup](local-and-server-setup.md)
4. [Operations and backtesting](operations-and-backtesting.md)
5. [Acceptance checklist](acceptance.md)
6. [Risk and parity record](risk-and-parity.md)

This phase deliberately starts with interpretable deterministic statistics.
Seasonality, menu-plan demand, garden production, and opaque machine learning
remain disabled until evidence, product decisions, and promotion gates exist.
