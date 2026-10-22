# Backend inventory and synchronization corrections

This change set tracks the backend work for issues #28, #29, #30 and #31.
The baseline is `b65e66abe94147c8904832c6afbe5321ff63afcb`.

| Issue | Backend responsibility | Verification boundary |
| --- | --- | --- |
| #28 | Shopping product/pack identity, revision-checked relinking, count eligibility, recommendation explanations | Client entry and presentation require coordinated Client work |
| #29 | Preserve account/home/device scoping, safe original-binding operation recovery evidence and deployment diagnostics | Existing encrypted browser queue and installed sessions require live verification |
| #30 | Calendar-date validation and audited correction of purchase and price evidence | Client date input and timezone round trips require Client changes |
| #31 | Count-aware chronology, historical depletion and deterministic rebuilding | Client optimistic projections require matching historical semantics |

Implementation commits reference the issue they target. Changes must preserve
existing records, operation identities and purchase confirmation boundaries.
An unreviewed receipt or a shopping-list checkbox must never add inventory.

The final pull request records the implemented behavior, regression evidence,
contract changes and any installed-environment verification still outstanding.
