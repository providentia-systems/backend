# Backend issues 33–38

This change set implements the backend work for issues #33–#38. The canonical
API remains owned here; paired Client and Admin workflows are maintained in
their own repositories.

## Implementation and verification checklist

- [ ] #33: audited finished-receipt corrections for omitted and unresolved lines,
  including synchronization, idempotency, revision conflicts and stock evidence.
- [ ] #34: consistent receipt spend, exact derivation and pricing completeness in
  summaries and reports, with explicit totals and separate currencies.
- [ ] #35: truthful unknown, zero and uncertain inventory report projections.
- [ ] #36: resolved operator stock metadata with reference diagnostics,
  tenant isolation and stable pagination.
- [ ] #37: accurate synchronization recovery and guarded stock-unit guidance.
- [ ] #38: pinned frontend compatibility matrix exercising production adapters,
  with historical regression and installed acceptance boundaries stated.
- [ ] Canonical contract, lock and runtime validation updated together.
- [ ] Required local validation and GitHub checks reviewed before readiness.

Each implementation commit references the issue it addresses. Validation results
and remaining release dependencies will be recorded here as the work completes.
