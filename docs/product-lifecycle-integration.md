# Product lifecycle integration — API 2.1.0

This implementation coordinates `providentia-systems/backend`,
`providentia-systems/client` and `providentia-systems/admin`. Backend PR #22 uses
`feat/product-lifecycle-integration`; Client PR #18 and Admin PR #10 use
`feat/complete-lifecycle-integration` after the earlier client branches merged.
The product name is Providentia.

This is a source contract and acceptance guide, not evidence about a particular
running deployment. A healthy older image does not prove the new operations exist.
Use matching backend, Client and Admin builds; deploy the backend first.

## Contract and authorization

The backend owns the canonical OpenAPI artifact. Its exact compressed source is
copied to both clients; the locks and generated manifests record the digest and
operation counts. Regenerate bindings after contract changes; never edit generated
Dart manually. The homeowner and Linux Admin operation sets remain separate.

Homeowner mutations use membership, effective home permissions and current quotas.
Admin uses dedicated operator endpoints with its own credentials and
`homes.read` / `homes.manage` authority, without creating home memberships.
Privileged authorization loss clears visible/cached state and invalidates pending
editor responses. Public catalog contribution is separate from internal operator
access: a private product must be visible to an authorized operator after private
synchronization, even when its household has never consented to public sharing.

## Mutable records and retained history

| Record | Implemented lifecycle |
| --- | --- |
| Private products | Client and Admin create/edit/archive/restore; editable private name, pack description and home category; revisions prevent stale overwrites. |
| Home categories | Client and Admin create/edit/archive/restore, with active-reference and quota checks. |
| Home locations and stores | Client and Admin create/edit/archive/restore; selectors retain real IDs and prevent use of inactive records. |
| Stock preferences | Client and Admin edit/reset minimum quantity, always-keep/never-suggest, lead time, expiry handling and published preferred pack. Existing revisions and exact decimal quantities are retained. |
| Shopping lists and lines | Client and Admin create/edit/archive/restore; description, quantity and checked state use current revisions, retaining parent-list history. |
| Open counts | Client edits/recounts and removes erroneous lines; removal is retained, contributes no adjustment, and recount reuses the same product-line identity. Closed/cancelled counts cannot be edited. |
| Draft receipts | Client edits header/line values, removes lines and cancels drafts. Edits reset human approval; removed/cancelled records remain in synchronization and cannot enter commit totals. |
| Global catalog | Admin maintains categories, products, packs, units, variants, aliases, barcodes and identity rules, with revisions, reference validation and audit reasons. |
| Access groups and policy drafts | Admin removes only unused custom groups or unpublished, unused policy drafts. Built-in/protected/default/assigned groups and published/accepted policies remain protected. |

Archival is the removal operation for referenced business records, not SQL deletion.
A product with stock, an active count or an uncommitted receipt cannot be archived.
Category/location/store archival also respects operational references. Restoring
records rechecks current quotas and references; lowering a quota never erases data.

Stock movements, committed receipts, observed prices, policy acceptances and audit
events are historical evidence. This change does not introduce destructive editing
of those records. Corrections must use the relevant explicit business workflow;
a generic delete button is not a substitute for a reversal policy.

## Product inspection and catalog publication

Admin's Catalog operations → Published identities retains the selected product's
identity, category, revision, exact pack measures/multiplicity and icon metadata.
Manage product, packs and identities opens the existing revision-bound maintenance
surface scoped to that product, including related aliases and barcodes. Existing
conflict and reversible-merge workbenches remain authoritative.

Household category overrides do not rename or reclassify the global product.
The stock display/filter honors the home category while the public item-master
cache retains canonical labels. Used pack/unit measurements and published pack
parents cannot be reinterpreted. Alias variant/pack relationships are validated;
a shared publication lock prevents concurrent global normalized-alias claims.
Private aliases are excluded from global maintenance.

An approved new product receives a selectable pack. Contribution-linked approval
publishes the reviewed pack text and supplied valid barcode atomically; a conflict
cannot leave a partially published identity. A standalone product without a
specified measure receives an unspecified pack that can be completed through the
existing maintenance workflow.

## Synchronization, recommendations and AI

Local Client changes atomically update the Drift projection and typed protocol-v2
outbox. Stable operation IDs support retries without duplicate effects. Private
source changes must synchronize successfully before a public contribution is
submitted. Consent remains an explicit review of the exact allowlisted fields;
source removal, a stale revision or authorization loss blocks submission.

Recommendations are generated only by the explicit Generate recommendations
action. Reading cached suggestions does not generate new ones. Feedback is a
durable typed command; a rejected decision must not permanently hide a suggestion.
Successful suggested-line creation records acceptance/quantity changes atomically.
The backend verifies the home/product/suggestion relationship and derives persisted
provenance, so other devices receive the same origin and explanation.

AI extraction uses the existing backend provider integration. Before transmission,
the Client displays the resolved primary, fallback and validation recipients.
Consent binds to the home/user, settings/policy revisions and profile
provider/model/endpoint revisions. A changed plan requires fresh review before
provider credentials are used. Credentials remain encrypted and write-only;
raw media and provider tokens never enter inventory synchronization commands.

AI results remain proposals. Reviewed stock candidates may create private products
with a user-selected home category; quantity and receipt commits still require
human confirmation. The documented extraction schema does not promise AI-created
categories. Statistical product recommendations and AI extraction are distinct
existing workflows, not a new undocumented provider-dependent recommendation path.

## Setup, checks and release acceptance

For unpublished review changes, build the matching backend source with
`scripts/setup-development.sh`. For a published prebuilt image, select its
explicit version; a feature-branch checkout does not change the default `edge`
image into that branch. Preserve existing data; `--reset-data` is not an upgrade.
See the backend client/user testing runbook and each client's existing setup guide.

The migrations add retained shopping-line lifecycle/provenance fields and the
global alias publication lock. Apply the normal portable migrations before
launching matching clients; do not repair integration with manual database edits.

Required checks remain unchanged: contract generation/hash equality, architecture,
format/static analysis, Client/Admin tests and coverage, backend unit/integration
tests, SQLite/MySQL/MariaDB and Redis/Valkey matrices, mutation checks, source image,
headless HTTP acceptance and native Client/Admin build/package checks. The linked
PR checks are the current evidence; a successful earlier commit does not certify
a newer head.

The headless test covers typed private category/product creation, authorized Admin
inspection/editing, another installation's change feed, immutable retry receipts
and stale-revision rejection, alongside synthetic AI and moderated publication.
The post-release checklist additionally requires real matching installed apps,
two-device restart/offline convergence, the intended API origin/image digest,
mail delivery and synthetic-media testing of each actually enabled AI provider.
Those deployed checks cannot be inferred from source or a local health response.

These branches do not deploy, merge automatically, enable billing enforcement or
activate paid platform AI. Review and the deployed acceptance record remain
required before production sign-off.
