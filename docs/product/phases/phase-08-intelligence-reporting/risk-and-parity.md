# Phase 8 risk and parity record

## Master-prompt parity

| Required outcome | Implementation evidence | Boundary |
|---|---|---|
| Movement-based balances | Movement sum for historical inputs; rebuildable balance API/report | Projection is not model truth |
| Consumption estimates | Reliable count intervals plus inbound movements | No estimate with fewer than two counts |
| Explainable suggestions | Stored factors, confidence, coverage, limitations | No opaque ML |
| Price and pack comparison | Dimension conversion, price age, currency isolation | No exchange-rate assumption |
| Confidence and feedback | Versioned grades plus immutable user feedback | Score is not probability |
| Household reports | Inventory, purchases, consumption, suggestions | Home membership required and audited |
| Backtesting/evaluation | Knowledge-time walk-forward results and proxies | Purchase is a proxy, not stock-out proof |

## Principal risks

| Risk | Present control | Remaining work |
|---|---|---|
| Sparse or unreliable count history | Complete/reliable gate and minimum-only fallback | Improve count UX and evidence volume |
| Backdated data leaks into evaluation | Require business time and creation time at/before cutoff | Add dedicated integration fixtures |
| Wrong pack conversion | Same unit dimension and normalized base amounts | Expand catalog normalization coverage |
| Cross-currency false economy | Isolated groups and no selected winner | Add deliberate FX policy only if approved |
| Recommendation overclaim | Ordinary-language limitations and proxy labels | Client copy/accessibility review |
| Concurrent policy edits | Optimistic revision and transactional history | Add API-level conflict regression |
| Cross-home disclosure | Home authorization and every SQL query scoped | Complete automated horizontal tests |
| Report access sensitivity | Membership plus metadata-only audit | Export workflow remains future work |
| Synchronous large homes | Bounded current API and indexed reads | Move generation/report runs to queue at scale |

## Deliberately deferred

- Seasonality stays disabled until adequate history and promotion criteria.
- Menu-plan demand waits for a deliberately designed recipe/menu aggregate.
- Garden or home-produced stock needs an explicit factual input model.
- Learned ranking waits for sufficient feedback, leakage-safe evaluation, and
  a model registry promotion workflow.
- Cross-home benchmarks and price sharing are forbidden without separate,
  explicit consent and privacy design.
- Automatic retention is not invented while the product retention decision
  remains unresolved.

## Client handoff

The Flutter client should render factual balance, estimated demand, confidence,
limitations, and pack-price evidence as different concepts. It must allow
quantity edits, dismiss/snooze, policy controls, and explanation access.
Offline policies for the new aggregates remain opt-in work; clients must not
send generic synchronization payloads for them until typed conflict semantics
are published.
