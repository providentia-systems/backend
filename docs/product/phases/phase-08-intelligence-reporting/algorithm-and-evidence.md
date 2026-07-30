# Phase 8 algorithm and evidence rules

## Quantity arithmetic

All recommendation and report amount arithmetic uses `FixedDecimal` with eight
fractional digits. Domain code does not use binary floating point. Inputs
outside the supported fixed-point range fail validation instead of rounding
silently.

## Consumption between counts

Only a closed session explicitly marked both `scopeComplete=true` and
`reliability=reliable`, with a confirmed line, contributes a count point. For
two consecutive count points:

```text
observed consumption =
  max(0, opening quantity + eligible inbound quantity - closing quantity)

daily rate =
  sum(observed consumption) / sum(interval days)
```

An interval implying negative consumption is clamped to zero and adds a
limitation. The current implementation reports mean absolute deviation of
interval rates as variability.

| Evidence | Confidence band | Important qualification |
|---|---|---|
| No complete interval | Low | Rate is zero; only configured reserve may trigger a suggestion |
| One interval | Low | Score varies with coverage and count recency |
| Two intervals | Medium | Stronger only with sufficient days and recent evidence |
| Three or more intervals | Medium/high | High requires at least 90 covered days and a recent count |

Scores are versioned evidence grades, not calibrated probabilities.

## Purchase cadence

Committed receipt-line stock-in movements are ordered by the time known at the
cutoff. With at least two distinct purchase times, the estimator uses the
median whole-day interval. It projects the first cadence boundary on or after
the run time as `nextExpectedShoppingAt`. A configured target-coverage policy
takes precedence; otherwise this cadence sets the replenishment horizon. If
cadence is unavailable, the request horizon is used and the limitation is
returned.

## Replenishment

```text
expected demand = daily rate × (replenishment horizon + lead time)
required quantity = max(0, expected demand + minimum reserve - usable stock)
```

Usable stock is the non-negative portion of the movement-derived factual
balance. `alwaysKeep` supplies a reserve of one when no explicit minimum
exists. `neverSuggest` and active snooze prevent eligibility. With no reliable
consumption interval, expected demand is zero and the algorithm uses only the
configured reserve.

## Pack and price mapping

A candidate pack participates only when its unit dimension matches the home
product's current pack and both have normalized base amounts. Pack counts round
up. Effective cost uses private, observed receipt price evidence:

```text
home units per candidate pack = candidate base / current home-pack base
pack count = ceil(required quantity / home units per candidate pack)
price per pack = observed line total / observed receipt quantity
effective total = price per pack × pack count
```

Price recency is labelled high up to 30 days, medium through 90 days, and low
after 90 days. A preferred pack wins within a single currency when eligible;
otherwise the lowest observed total wins. When multiple currencies exist no
option is selected, because choosing one would imply an exchange-rate policy
that the product has not approved.

## Immutable evidence

Each run stores:

- method and model versions;
- one `asOf` timestamp and input watermark;
- rate, variability, coverage, cadence, confidence, and evidence window;
- factual stock, expected demand, reserve, required quantity, and pack options;
- ordinary-language factors and limitations.

Policy history is revisioned so backtests do not read a future preference.
Generated suggestions are never rewritten; feedback changes workflow status
and records the user's decision separately.
