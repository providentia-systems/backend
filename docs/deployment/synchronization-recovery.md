# Original-binding synchronization recovery (#29, #37)

## Evidence and scope

A `device_binding_mismatch` response means the envelope's `deviceId` did not
match its authenticated session. That check runs before batch execution and
remains mandatory. It does not establish the cause of an installed session
mismatch. A dashboard count of pending operations does not establish how many
purchases executed, whether their responses were lost, or whether home access
was revoked. Treat the following as source behavior until the installed build,
session binding and preserved queue have been verified.

Source review also found a reproducible browser-session collision boundary:
legacy web sessions use the same backend-origin cookie names for both frontend
applications. Separate frontend origins do not create separate cookie jars for
the backend origin. An Admin sign-in can therefore replace the cookies used by
an already-open household workspace. This is a source-level prevention fix and
an automated regression, not proof that this caused a particular installed
queue. The user's encrypted device database, installed revisions and queued
operations have not been inspected by this change.

## Browser session isolation

Updated browser adapters must consistently send
`X-Providentia-Application: homeowner` or
`X-Providentia-Application: admin` when requesting and verifying email codes,
reading authenticated data, refreshing and signing out. These are the only
accepted values. The login request and verified challenge application must
agree with the selected context. The header selects cookies; it grants no
account, home or administrator permission.

The selected namespace uses `providentia_homeowner_access`,
`providentia_homeowner_refresh` and `providentia_homeowner_csrf`, or the equivalent
`providentia_admin_*` names. Authentication, refresh and logout only consult that
namespace. They never borrow legacy cookies or another application's cookies.
Mutating cookie-authenticated requests retain the double-submit and
session-hash CSRF checks. Scoped refresh also requires its matching CSRF proof
before rotating credentials. Signing out of one context expires only its own
cookies.

Requests without this header retain the existing legacy cookie behavior. Native
bearer sessions remain supported. Enabling isolated cookies requires matching
Client/Admin HTTP adapter updates and a deliberate sign-in to the new
namespace; this backend change does not migrate, erase or reassign saved work.
Current session responses expose the distinct account-scoped `deviceId` and
original `installationId`; an unknown legacy installation is `null` in session
list/readback instead of an invented installation identifier.

The Linux native Admin uses bearer credentials, not the browser cookie jar.
Cookie namespacing is therefore not a native-session recovery procedure. For
either transport, verify the authenticated account and account-scoped device
binding; copying browser cookies, inventing a replacement device ID or signing
in on another installation cannot establish the original queue's provenance.

## Establish the installed backend evidence

An authenticated home member can call
`GET /api/v1/homes/{homeId}/sync/diagnostics`. It reports:

- authenticated account, device and home correlation identifiers;
- application version and build revision, or `null` when the source revision is
  unavailable;
- SHA-256 of the actual loaded canonical contract and agreement with its lock;
- available, applied, pending and unknown migration names, with an explicit
  `current`, `pending`, `divergent` or `uninitialized` migration status.

Production images embed the CI source revision in `APP_BUILD_REVISION`. A
version label alone is insufficient deployment evidence. Compare these values
with both frontend revisions and their generated contract lock. This endpoint
contains no credentials, encryption keys, receipt content or operation payloads.
It cannot inspect a browser's private saved queue or prove uploads completed.

## Preservation prerequisites and recovery limits

The reviewed Client does not provide a user-facing local backup/restore or
blocked-queue recovery workflow. Its implementation is tracked in
[Client #30](https://github.com/providentia-systems/client/issues/30). Do not
instruct a user to press an unimplemented backup or restore button. Before any
local repair, require a version- and platform-specific preservation procedure
that has been verified to recover the encrypted device database, durable outbox
and the key material/provenance needed to read them. A copied database without
its usable encryption context is not a verified recovery copy. Until that
procedure and tooling are available, retain the existing installation/profile
and its saved data; do not clear storage, reinstall or re-enter receipts as a
generic repair.

| Evidence or operation | What it preserves or proves | What it cannot replace |
| --- | --- | --- |
| Server home/account export | Authorized records already stored on the backend, including retained historical evidence | Encrypted Client data, unsent operations or their original operation IDs |
| Verified local preservation | Recoverable encrypted local records, queued commands and original account/home/device provenance, with their required encryption context | Server execution evidence or proof that pending uploads succeeded |
| Authorized bootstrap/pull | A current permitted server snapshot/change feed and authoritative readback | Backup of the outbox, recreation of unsent purchases or automatic settlement of local operations |
| Original-binding revalidation | Classification of preserved immutable operations against retained server execution receipts and current permissions | Execution of a command, repair of the payload or reassignment to another account/device |

Bootstrap/readback must retain and reconcile the local queue; replacing a local
projection is not permission to discard it. If local queue data is deleted, the
backend cannot reconstruct commands it never received, their payloads or their
original operation ordering. Retained server records or execution receipts may
establish what was accepted, but absence of a record cannot recover lost local
intent. Any later manually reconstructed purchase requires explicit
reconciliation against server evidence; receipt re-entry is not a safe blanket
recovery action.

## Revalidate a preserved blocked queue

Keep operation IDs, account/home/device provenance, payloads, base revisions and
ordering immutable. The following describes the backend API and requirements
for coordinated Client tooling; it does not claim an installed recovery screen
exists or that a particular queue has been recovered.

Restore a legitimate session for the original account and device. Then call
`POST /api/v1/homes/{homeId}/sync/revalidate` with the original `accountId`,
`deviceId` and 1–100 queue-ordered entries. Each entry contains its preserved
`blockedCode` and full original protocol-v2 `command`. This endpoint is
read-only. It does not execute commands, change receipts, acknowledge local
operations or change stock.

| Action | Evidence and required next step |
| --- | --- |
| `readback` | The original account/home/device and immutable request hash match a retained accepted execution receipt. Retain that result, obtain authoritative readback and settle the local operation without a new purchase. |
| `retry_same_operation` | No execution receipt exists under the verified original identity, the preserved block was `device_binding_mismatch`, and current domain write permission is present. Retry exactly that original operation through normal push validation. This is eligibility to retry, not acceptance or permission to overwrite a stale revision. |
| `await_predecessor` | An earlier unresolved operation must finish first. Retain this operation and its dependencies; report the first blocker. |
| `review_required` | A retained receipt mismatches immutable identity/hash, a known result requires review, provenance is unknown, the original block was not device-related, or current permission does not permit retry. Preserve it and resolve the actual cause. |

Different account/device requests fail before receipt lookup. A missing result
from another device scope is never treated as evidence that the original
operation did not execute. Known results are filtered through current read
permissions. Request failures, unavailable evidence and interrupted recovery
leave the queue unresolved; allocate no replacement operation identity.

The Client must persist settlement/requeue decisions transactionally, retain new
operations created during recovery, recheck the active session after in-flight
requests, and drain the original dependency order. A successful pull means only
that authorized downloads finished. Uploads blocked, pending, accepted awaiting
readback and fully reconciled are distinct states.

## Verification boundaries

Backend unit and real HTTP/database regressions cover isolated browser cookie
contexts, scoped refresh/logout, original-binding recovery, lost-response
receipt readback, repeated lookup/retry, immutable-request mismatches,
permissions, revoked homes and absolute stock readback on a second device.
These tests do not constitute installed browser/native acceptance or recovery
of the user's existing encrypted queue. Matching frontend adapter/projection
work and installed-environment verification remain required before closing the
coordinated issue.
