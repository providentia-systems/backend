# Backend inventory and synchronization corrections

This change set tracks the backend work for issues #28, #29, #30 and #31.
The baseline is `b65e66abe94147c8904832c6afbe5321ff63afcb`.

| Issue | Backend responsibility | Verification boundary |
| --- | --- | --- |
| #28 | Shopping product/pack identity, revision-checked relinking, count eligibility, recommendation explanations | Client entry and presentation require coordinated Client work |
| #29 | Preserve account/home/device scoping, safe original-binding operation recovery evidence and deployment diagnostics | Existing encrypted browser queue and installed sessions require live verification |
| #30 | Calendar-date validation and audited correction of purchase and price evidence | Client date input and timezone round trips require Client changes |
| #31 | Count-aware chronology, historical depletion and deterministic rebuilding | Client optimistic projections require matching historical semantics |

Implementation commits reference the issue they target. The shared contract is
API **2.3.0**, with migration versions `20261022000100` through
`20261022000300`. The canonical archive, lock, materializer, runtime routes and
generated Dart proof move together. The required contract workflow publishes
the generated package as a PR artifact for coordinated frontend adoption.

## Implemented behavior

- **#28:** manual product and pack selection retain purchasing instructions and
  distinguish pack count from stock quantity. Existing lines have a deliberate,
  revision-checked relinking endpoint with audit history. Confirming count scope,
  reliability and physical observation time makes eligible evidence available;
  removed or omitted products do not become zero. Recommendation runs explain
  shortages, minimum-only calculations, exclusions, insufficient evidence and
  uncertain stock. Reads refresh stale derived runs, retain superseded evidence
  and carry dismissed/accepted/snoozed decisions during automatic refresh.
- **#29:** opt-in Homeowner/Admin browser cookie namespaces prevent shared-origin
  session replacement. Original-binding revalidation classifies immutable saved
  operations without executing or reassigning them. Authenticated diagnostics
  expose actual build, contract and migration evidence. Existing device/account/
  home checks and stable operation identities remain mandatory. See the
  [recovery runbook](../deployment/synchronization-recovery.md).
- **#30:** purchase days are validated calendar dates. Future days are rejected
  against the household's `defaultTimezone`; extracted proposals remain evidence
  for review. An explicit date-correction workflow preserves original timestamps,
  receipt/line identities, prices and audit history, supersedes active price
  observations and reconciles inventory. Currency protections and revision
  conflicts remain. Receipt header locks prevent concurrent line corrections
  from writing stale date/store price evidence.
- **#31:** current balances and rebuilding use the same chronological reader.
  Only compatible, explicitly reliable whole-home observations are checkpoints;
  later receipts before a checkpoint improve history without inflating current
  stock. Same-day date-only ambiguity is exposed as uncertainty and pending
  quantity. Known outflows and residual unexplained depletion remain distinct.
  Audited count reviews preserve previous/next observations and their captured
  timezone. Unit relabeling with existing evidence requires an explicit separate
  correction rather than silently changing quantity meaning. Rebuild publishes
  materially changed absolute balances for synchronization.

The documented example of a 2 kg opening count, late 4 kg purchase and 3 kg
closing count yields 3 kg residual depletion and 3 kg current stock; a subsequent
2 kg purchase yields 5 kg. Repeated transmission, correction and rebuild do not
create another logical purchase. Shopping checkboxes and unreviewed receipt
proposals do not create stock events.

## Data and transaction guarantees

Migration retains existing counted balances as explicitly unassessed baselines
until the household supplies or reviews trustworthy physical evidence. It does
not certify old counts automatically. Earlier historical cutoffs retain an
uncertain legacy projection. New observation/audit/baseline evidence prevents a
destructive downgrade; home exports include the new correction history.

MySQL and MariaDB connections establish `READ COMMITTED` on connection and
reconnection. Product/receipt row locks serialize mutations and subsequent
chronological reads see the winning committed transaction. Recommendation
generation serializes generators per home and verifies accepted input content
before gathering, after gathering and before persisting; concurrent drift
returns a conflict without saving a mixed run. Derived refreshes never mutate
shopping plans or stock.

The web image also updates Go to 1.26.10 and klauspost/compress to 1.18.7 to
resolve findings reproduced by the initial PR's security scan. The image scan
and binary dependency assertions remain enforced.

## Verification and rollout boundary

Regression coverage includes full migrations, chronological entry permutations,
legacy preservation, timezone boundaries, count corrections, price supersession,
cancellation, rebuilding/readback, cookie isolation, immutable replay and
recommendation freshness. The database matrix includes a real two-connection
receipt-header race; its test explicitly skips when no native matrix database
is configured. Exact pinned container, broker, database, mutation and image
checks are enforced by GitHub Actions. Local supplemental PHP checks are
separate from the pinned container lane; the managed host could not complete
the Docker-based doctor bootstrap.

This backend PR does not inspect or repair the installed encrypted Client queue.
Matching frontend adapters must adopt the cookie header, recovery endpoints,
calendar-date input, count confirmations and uncertainty-aware offline
projections. Existing local work must be backed up and retained during recovery.
Installed browser/native acceptance remains outstanding; the coordination issues
must remain open until their Client and live acceptance requirements are met.
