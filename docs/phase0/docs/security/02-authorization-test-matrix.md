# Providentia authorization test matrix

**Status:** Accepted Phase 0 authorization baseline; executable tests belong to implementation phases

**Default policy:** Deny unless an authenticated principal, current membership or platform grant, action, object, and tenant context all authorize the request.

## 1. Role legend

Home roles are evaluated separately for each home:

- **O:** Owner
- **M:** Manager
- **N:** Member
- **V:** Viewer

Platform roles do not imply home membership:

- **PA:** Platform administrator
- **CC:** Catalog curator
- **CR:** Catalog reviewer
- **SO:** Support operator
- **SG:** Support operator with a valid user-granted support-access grant
- **A:** Authenticated user without membership in the target home
- **X:** Anonymous

Cell values:

- **Allow:** Expected authorization success, subject to validation and object state.
- **Deny:** Expected indistinguishable authorization failure; the response must not disclose object existence.
- **Conditional:** Allowed only under the condition shown and separately tested.

An individual may hold multiple roles, but each permission must identify which role/grant authorized it. Platform roles never satisfy home membership checks.

## 2. Home and membership actions

| Action | O | M | N | V | PA/CC/CR/SO | SG | A/X | Conditions and required negatives |
|---|---|---|---|---|---|---|---|---|
| Read home summary/settings | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | SG scope includes this home/resource and is unexpired |
| Update ordinary home settings | Allow | Allow | Deny | Deny | Deny | Conditional | Deny | Manager cannot alter ownership/security-only settings |
| Delete home | Allow | Deny | Deny | Deny | Deny | Deny by default | Deny | Step-up; typed confirmation; retention/export policy |
| Export complete home data | Allow | Deny by default | Deny | Deny | Deny | Deny by default | Deny | Step-up; audited; no other-home rows or secrets |
| List memberships | Allow | Allow | Deny by default | Deny | Deny | Conditional | Deny | Product may later expose limited member display separately |
| Invite Manager/Member/Viewer | Allow | Conditional | Deny | Deny | Deny | Deny by default | Deny | Manager cannot invite Owner; role cap and policy enforced |
| Revoke pending invitation | Allow | Allow for own/permitted invite | Deny | Deny | Deny | Deny by default | Deny | Re-check actor permission at execution |
| Accept invitation | Conditional | Conditional | Conditional | Conditional | Conditional | Conditional | X: Deny | Authenticated intended account; token valid, single use, current |
| Remove another member | Allow | Conditional | Deny | Deny | Deny | Deny by default | Deny | Manager cannot remove Owner or peer Manager unless explicitly approved |
| Change another member's role | Allow | Conditional | Deny | Deny | Deny | Deny by default | Deny | Manager role ceiling; cannot create/alter Owner |
| Leave home | Conditional | Allow | Allow | Allow | Not applicable | Not applicable | Deny | Sole Owner must transfer ownership or delete first |
| Transfer ownership | Allow | Deny | Deny | Deny | Deny | Deny | Deny | Step-up; target current member; explicit acceptance/safeguards; audited |
| Switch active home | Allow if member | Allow if member | Allow if member | Allow if member | Deny without membership | Conditional | Deny | Never grants membership; invalid home reveals nothing |
| Create support grant | Allow | Conditional proposal only | Deny | Deny | Deny | Deny | Deny | Narrow scope, expiry, purpose, visibility, audit |
| Revoke support grant | Allow | Conditional | Deny | Deny | Deny | Cannot prevent revocation | Deny | Immediate effect on next operation |

## 3. Private operational resources

The following table applies only to objects whose `home_id` matches a current membership. Every row must also be executed with a valid object ID from a different home and with a mismatched route/body `home_id`; both must be denied.

| Resource/action | O | M | N | V | PA/CC/CR/SO | SG | A/X | Special rule |
|---|---|---|---|---|---|---|---|---|
| Locations: list/read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Tenant-scoped query |
| Locations: create/update/archive | Allow | Allow | Deny | Deny | Deny | Conditional | Deny | V1 assigns location management to Owner/Manager; archive cannot orphan history |
| Home products: list/read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Includes private products/preferences |
| Home products: create/update | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | Cannot publish globally through this route |
| Private home aliases: list/read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Raw aliases stay home-private and never appear in catalog moderation |
| Private home aliases: create/revoke | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | Same-home product only; global promotion requires separate consent/sanitization |
| Stock-threshold/suggestion preferences: read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Home-private policy and explanation inputs |
| Stock-threshold/suggestion preferences: create/update | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | Revisioned; never direct balance mutation |
| Stock sessions: list/read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Local media reference never exposed cross-device as bytes |
| Stock session: open/add lines | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | Active membership required at operation execution |
| Stock session: close | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | Creates idempotent reconciliation movements |
| Closed count: edit/delete | Deny direct | Deny direct | Deny direct | Deny | Deny | Deny | Deny | Correction uses audited reversal/new fact |
| Stock movement: list/read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | No unscoped reporting endpoints |
| Stock movement: direct create/update/delete | Deny direct | Deny direct | Deny direct | Deny | Deny | Deny | Deny | Only authorized domain commands may create/reverse |
| Inventory balance: read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Projection only |
| Inventory balance: direct write | Deny | Deny | Deny | Deny | Deny | Deny | Deny | Rebuild/projector only |
| Stores: list/read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Store identities and purchase links are home-private |
| Stores: create/update/archive | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | Archive cannot orphan receipt or price history |
| Receipts/header/lines: list/read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Raw text and prices are private |
| Receipt: create/review/update | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | AI output remains proposal |
| Receipt: approve/commit | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | Stock-in exactly once |
| Receipt matches/discounts/taxes: read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Same-home receipt; raw and normalized evidence retained |
| Receipt matches/discounts/taxes: approve/amend | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | Explicit amendment/reversal; no silent overwrite |
| Receipt/media bytes: read/upload | Conditional | Conditional | Conditional | Conditional read | Deny | Conditional | Deny | Original local by default; only approved backup policy may create server media |
| Price observations: list/read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Never implied global sharing |
| Shopping lists: list/read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Tenant scoped |
| Shopping lists: create/update/check | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | Revision/conflict policy |
| Suggestions: read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Explanation contains only home data |
| Suggestion runs/explanations: read | Allow | Allow | Allow | Allow | Deny | Conditional | Deny | Home-scoped inputs and confidence; no cross-home comparison |
| Suggestion feedback: create/update | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | `always keep`/`never suggest`, dismiss/snooze and edits remain private |
| AI extraction runs: start/read/cancel own home run | Allow | Allow | Allow | Deny | Deny | Conditional | Deny | Valid provider/privacy mode; no automatic inventory mutation |
| AI provider settings metadata | Allow | Allow if policy permits | Conditional own/use only | Deny | Deny | Deny by default | Deny | Exact management policy finalized before Phase 6 |
| AI credential plaintext/readback | Deny | Deny | Deny | Deny | Deny | Deny | Deny | No plaintext display after entry |
| AI credential create/rotate/delete | Conditional | Conditional | Conditional own | Deny | Deny | Deny | Deny | Reauthentication; provider policy; full audit |
| Home audit events: read | Allow | Conditional subset | Deny by default | Deny | Deny | Conditional | Deny | Security audit visibility policy must be least privilege |
| Client operations/change feed/tombstones: sync read | Allow | Allow | Allow | Conditional read | Deny | Conditional | Deny | Only the authorized sync contract; cursor and entity scope are same-home |
| Client operations/change feed/tombstones: direct create/update/delete | Deny | Deny | Deny | Deny | Deny | Deny | Deny | Synchronization application service and domain transaction only |
| Outbox events: read/write through home API | Deny | Deny | Deny | Deny | Deny | Deny | Deny | Infrastructure-only; operational metrics never expose payload |
| Home deletion/export job status | Allow | Deny by default | Deny | Deny | Deny | Deny by default | Deny | Job IDs are not bearer authorization |

## 4. Global catalog and moderation resources

| Action | O/M/N/V as home user | CC | CR | PA | SO/SG | A | X | Conditions |
|---|---|---|---|---|---|---|---|---|
| Read published catalog/categories/packs/icons | Allow | Allow | Allow | Allow | Allow | Allow | Conditional public subset | Public/private fields explicitly serialized |
| Search published aliases/barcodes | Allow | Allow | Allow | Allow | Allow | Allow | Conditional/rate-limited | Raw private receipt aliases excluded |
| Create private home product | O/M/N Allow; V Deny | Deny without membership | Deny | Deny | SG Conditional | Deny | Deny | Creates no global data automatically unless policy approved |
| Submit sanitized catalog proposal | O/M/N Conditional; V Deny | Deny (moderation only) | Deny (review only) | Deny by default | Deny | Deny | Deny | Current home membership and consent policy; service strips private fields and records status through a separate home-private link |
| Read own proposal status | O/M/N Conditional; V Conditional read | Deny | Deny | Deny by default | Deny | Deny | Deny | Reads only the same-home private submission link and safe status; catalog roles never authorize this link or expose proposal provenance |
| Read proposal queue | Deny | Allow | Allow | Deny by default | Deny | Deny | Deny | Queue contains sanitized data only and no home/user relationship |
| Review/approve/reject proposal | Deny | Allow | Conditional recommendation/review | Deny by default | Deny | Deny | Deny | Separation-of-duty policy tested if enabled |
| Create/update canonical product, pack, alias, barcode | Deny | Allow | Deny by default | Deny by default | Deny | Deny | Deny | Revision, conflict checks, audit |
| Moderate categories/identity rules | Deny | Allow | Conditional review | Deny by default | Deny | Deny | Deny | V1 identity rules protected from silent overwrite |
| Upload/replace catalog icon | Deny | Allow | Conditional review | Deny by default | Deny | Deny | Deny | Safe media pipeline and separate asset origin |
| Preview merge | Deny | Allow | Allow read/review | Deny by default | Deny | Deny | Deny | Preview includes global links, not private row content |
| Execute/reverse merge | Deny | Conditional elevated curator | Deny by default | Deny by default | Deny | Deny | Deny | Step-up, revision, reversible relink, audit |
| View home stock/prices/receipts from catalog UI | Own membership only | Deny | Deny | Deny | SG only within grant | Deny | Deny | Explicit anti-join regression |

## 5. Platform, account, and operational resources

| Action | Subject user | PA | CC/CR | SO | SG | Other authenticated/anonymous | Conditions |
|---|---|---|---|---|---|---|
| Read/update own profile | Allow | Deny unless own | Deny unless own | Deny unless own | Deny unless own | Deny | Email/security changes require verification |
| List/revoke own devices | Allow | Deny unless own | Deny unless own | Deny unless own | Deny unless own | Deny | Current-session safeguards |
| Request password reset/verification | Conditional | Conditional own | Conditional own | Conditional own | Conditional own | Anonymous request accepted generically | No account enumeration |
| Disable/suspend account | Conditional own deletion flow | Conditional platform operation | Deny | Deny by default | Deny | Deny | PA cannot browse home data; audit and appeal/recovery policy |
| View platform health/metrics | Deny | Conditional | Deny | Conditional operational subset | Conditional | Deny | Metrics contain no private payloads |
| View queue payload | Deny | Deny by default | Deny | Deny by default | Deny | Deny | Operational view uses metadata only |
| Retry/dead-letter job | Deny | Conditional | Deny | Conditional by workload | Conditional | Deny | Re-authorize referenced record at execution; full audit |
| Create platform/catalog role | Deny | Conditional elevated admin | Deny | Deny | Deny | Deny | Does not create home access |
| Read secrets/provider plaintext | Deny | Deny | Deny | Deny | Deny | Deny | Secret-manager operations outside application UI and separately controlled |
| Run migration/import | Deny | Conditional deployment operator, not ordinary PA | Deny | Deny | Deny | Deny | Dry-run/reconciliation and protected operator channel |
| Restore backup | Deny | Conditional deployment operator, not ordinary PA | Deny | Deny | Deny | Deny | Isolated environment, audited infrastructure procedure |

## 6. Synchronization authorization matrix

| Case | Expected result | Required assertion |
|---|---|---|
| Valid member pushes operation for active home | Per-operation success/validation/conflict | Server derives user/home; client IDs preserved |
| Client body changes `home_id` to another home | Authorization failure | No target existence/data; no write/outbox/change-log event |
| Member was revoked while offline | Authorization failure | Every pending operation rechecks current membership |
| Role downgraded while offline | Per-operation authorization based on current role | Earlier local permission is not accepted |
| Duplicate accepted operation | Same logical result, no duplicate fact | Idempotency response does not rerun side effects |
| Operation ID reused with different payload/entity | Conflict/security failure | Original operation remains immutable; audit signal |
| Device token used with another device ID | Authentication/authorization failure | Device binding enforced |
| Cursor from another home | Authorization failure or safe invalid-cursor response | No cross-home changes or cursor position leaked |
| Tombstone from another home | Deny/ignore safely with audit | Cannot delete target-home records |
| Offline membership/role grant | Reject | Grants are server-authoritative only |
| Closed count edit through generic sync | Reject | Append-only fact; correction command required |
| Catalog merge through sync | Reject | Server-only audited command |
| Lost response after server commit | Idempotent retry returns accepted result | No duplicate movement/outbox event |
| Token expires during batch | Per-operation/batch classified auth failure | No partial operation without explicit per-item result |

## 7. Invitation and support-grant state tests

Every state transition must have a positive test and direct API negative tests:

```text
Invitation: pending -> accepted | revoked | expired
Support grant: pending -> active -> expired | revoked
Ownership transfer: proposed -> accepted | cancelled | expired
```

Required races:

- two concurrent invitation acceptances;
- invite revocation concurrent with acceptance;
- inviter loses permission before acceptance;
- support grant expires during a multi-request workflow;
- home owner revokes a grant while a worker job is queued;
- ownership transfer target leaves the home before acceptance.

Queued jobs and long requests must re-check authorization at the point of sensitive action, not only when enqueued.

## 8. Test harness requirements

### Fixtures

Create at least:

- three homes;
- one user belonging to all three with different roles;
- one user for each home role;
- users with each platform role but no home membership;
- a support operator with no grant, an expired grant, a revoked grant, and a valid narrow grant;
- colliding object identifiers across resource types;
- private products and aliases, catalog-submission links, global products,
  stores, thresholds, receipts, matches, discounts, taxes, prices, movements,
  lists, suggestion runs/feedback, AI settings/runs, client operations, change
  rows, tombstones, cursors, outbox events, exports, and queued jobs in every
  home.

### Assertions for every denied request

1. Correct non-disclosing status and Problem Details contract.
2. No target payload, count, timing distinction, or existence clue.
3. No database mutation.
4. No stock movement, outbox message, audit side effect except an appropriate security-denial event.
5. No cache, search, queue, export, metric label, or log containing private target data.
6. Request and security correlation IDs remain available for investigation.

### Automation

- Generate route/resource coverage from the OpenAPI operation registry and fail CI when a protected operation lacks authorization tests.
- Run the same repository authorization contracts against SQLite, MySQL, and MariaDB.
- Include direct handler/application tests so hiding UI controls is never treated as authorization.
- Fuzz route IDs, body IDs, home IDs, cursors, revisions, and operation IDs.
- Add explicit regression tests for every discovered authorization defect.

## 9. Completion criterion

The matrix is complete only when every protected OpenAPI operation and every non-HTTP entry point (CLI, queue consumer, scheduler, importer, projector, export, restore helper) maps to:

- a policy owner;
- allowed roles/grants;
- tenant derivation;
- positive and negative tests; and
- an audit requirement.

No wildcard “administrator may do everything” policy is permitted.
