# Data-governance operations

Run `php bin/providentia data-governance:process --once` for a single queued request, or omit
`--once` to drain the queue. The production worker must share `DATA_EXPORT_ROOT` with the API and
receive `DATA_EXPORT_KEK` from the secret manager. Rotating that key requires re-encrypting every
unexpired artifact first.

Exports are encrypted at rest, expire after one day, and require an authenticated, 15-minute,
one-time download token. Artifact files may be removed after `artifact_expires_at`; never expose the
filesystem reference directly.

Erasure is deliberately conservative:

- Home erasure rechecks the requesting user's active owner membership and deletes the private home
  aggregate transactionally.
- Account erasure rechecks that the account owns no active home, revokes credentials and sessions,
  removes memberships, and replaces direct account identity with an irreversible erased identity.
- Security and audit records remain access-restricted with actor/home links removed where the erased
  scope permits.
- Approved catalog and shared price facts may remain without user or household attribution.
- Billing and tax records are not deleted by this worker; their configured or statutory retention
  remains authoritative.
- Restic snapshots age out under infrastructure retention and must not be rewritten in place.

Failures remain in `failed` state with a bounded safe reason. Operators must correct the cause and
create a new request; do not manually mark a destructive request completed.
