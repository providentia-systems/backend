# Inventory integration roadmap — P0 through P3

This roadmap is the shared implementation contract for
`providentia-systems/backend` and `providentia-systems/client`. The P0–P3
labels below are **integration priorities**, not the historical product phases
in the master implementation prompt.

## Fixed boundary

- The backend owns the authoritative OpenAPI contract, tenant authorization,
  domain transactions, audit history, queues, and global catalog.
- The Flutter client owns authenticated presentation, the local Drift
  projection, the durable client-operation outbox, and platform packaging.
- Every private record is authorized from the authenticated user and active
  home on the server. A path, body, cached, or locally selected home ID is
  never authorization.
- Platform administrators, catalog reviewers, and catalog curators receive no
  home access from their platform role. They cannot browse household stock,
  locations, counts, purchases, prices, lists, AI settings, credentials, or
  private media.
- Product identity, public product images, and store-price facts have separate,
  default-off household consent. A contribution contains no household or user
  attribution in moderation responses or published global data. Quantities,
  storage locations, counts, receipt identifiers, private notes, and raw media
  are never community contributions.
- AI output is an untrusted proposal. Human approval through ordinary domain
  commands is required before inventory or purchase state changes.

## P0 — security and contract lock

Outcome: both repositories agree on one immutable API, one privacy boundary,
and fail-closed behavior before additional screens are called integrated.

- Pin the backend OpenAPI bytes and deterministic client generation output.
- Prove operation, route, schema, and lock-file parity in CI.
- Prove horizontal, vertical, cross-home, revoked-membership, and platform-role
  isolation for every integrated private resource.
- Keep catalog moderation DTOs allowlisted and free of home/user attribution.
- Keep credentials, tokens, receipt content, private media, and household
  payloads out of logs, metrics, queues, crash output, and support exports.
- Purge a revoked home's local projection only after its synchronization work
  is quiesced, and never resume it without fresh server authorization.

Exit evidence: contract checks, authorization/privacy regressions, generated
client checks, and synchronized risk/security documentation pass on the same
backend and client commits.

## P1 — authoritative inventory and counting

Outcome: the signed-in household inventory is backed by the server and remains
useful offline without creating duplicate movements.

- Project locations, private/global home products, balances, count sessions,
  count lines, and tombstones from the protocol-v2 change feed into Drift.
- Commit an optimistic local projection and its typed client command in one
  transaction.
- Use UUID operation/entity IDs, expected revisions, closed payload schemas,
  and server idempotency for location/product creation, adjustments, count-line
  updates, and count closure.
- Synchronize at startup, resume, home switch, manual refresh, and after a
  successful foreground mutation when connectivity permits.
- Preserve pending intent across process death, lost responses, token refresh,
  cursor replacement, and schema upgrades.
- Surface pending, retrying, conflict, validation, authorization, and offline
  states without displaying false success.

Exit evidence: repository/controller tests, command-shape tests, bootstrap and
cursor tests, lost-response retry tests, and two-device convergence tests pass.

## P2 — purchases, shopping, community catalog, and AI review

Outcome: the main household workflows and explicitly consented community
features cross the same authenticated boundary.

Client checkpoint (2026-08-11): the product-identity path is composed from the
active-home inventory projection. It requires `catalog.contribute`, current
server product-identity consent, an exact sanitized preview, and a fresh
per-item checkbox. Neither selecting an item nor changing consent submits.

- Project stores, receipts, receipt lines, shopping lists, and list lines from
  the authoritative feed; keep raw receipt descriptions private.
- Support idempotent receipt creation, line review, commit, shopping-line
  creation/checking, and revision conflicts through typed commands.
- Expose separate household consent for product identity, public product image,
  and store-price contributions, with per-item submission and category
  withdrawal that immediately unpublishes affected approvals.
- Publish only reviewer-approved, sanitized global facts without contributor
  attribution or reusable moderation IDs; never publish stock quantity or
  household linkage.
- Connect catalog review/curation only for the matching platform capability.
- Connect provider policy, write-only encrypted credentials, extraction,
  discrepancy/candidate review, and explicit commit flows. Sensitive or
  unrelated media creates no inventory proposal.

Exit evidence: replay/idempotency tests, contribution sanitization and consent
tests, platform-role denial tests, synthetic AI contract tests, and explicit
"no automatic inventory mutation" tests pass.

## P3 — intelligence, administration, and production acceptance

Outcome: reporting and administrative surfaces are connected and the release
candidate can be tested end to end without overstating production readiness.

Client checkpoint (2026-08-11): reviewer/curator navigation is platform-role
derived; proposal, contribution, conflict, validated icon metadata, merge, and
reversal actions call revision-bound ports. Permission, role, or session loss
clears route-owned state and dismisses both navigator layers. Live acceptance
remains required.

- Connect dashboard, movement, purchase, inventory, consumption, suggestion,
  price-comparison, feedback, and household-report reads.
- Connect account/home export, erasure, request status, and cancellation with
  permission-derived capabilities, explicit erasure confirmation, and no raw
  backend diagnostic detail in presentation state.
- Connect revision-bound catalog decisions, icon metadata, merge previews,
  reversible merges, and sanitized catalog audit history.
- Keep platform administration and catalog administration separate from home
  membership and render no private household payload on either surface.
- Automate a live backend/client acceptance suite for login, home selection,
  inventory, counts, receipts, shopping, consent, AI review, revocation, and
  cross-home denial.
- Run static analysis, unit/integration/widget/accessibility/golden tests,
  database and broker matrices, contract checks, migrations, and supported
  platform builds.
- Record staging, two-device, provider, backup/restore, signing, browser, and
  store evidence against immutable commit and artifact digests. Missing live
  evidence keeps the candidate testing-ready, not production-accepted.

## Release order

1. Implement and test a backend contract change.
2. Publish immutable OpenAPI bytes with a semantic version.
3. Pin those exact bytes and lock digest in the Flutter repository.
4. Regenerate the client; never hand-edit generated Dart.
5. Implement application-owned adapters and presentation.
6. Pass both repositories' quality gates and the connected acceptance suite.
7. Release the backend before the compatible client.

## Current contract baseline

- Backend API: `1.12.0`
- Client lock SHA-256:
  `30604d238f9c29f9d6b09dbf1819c84a475cb93e94728a8b2888f9b65a865a44`
- Contract addition: public, bounded
  `GET /api/v1/catalog-contributions` exposes only moderator-approved,
  allowlisted contribution payloads and never contribution attribution.
- Generic protocol-v1 entities: `home-preference`, `private-note`
- Pantry mutations: closed protocol-v2 commands only

This file must be changed in both repositories when a priority, privacy rule,
contract baseline, responsibility, or exit gate changes.
