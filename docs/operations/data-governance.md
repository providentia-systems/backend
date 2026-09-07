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
  protects the system owner from erasure, removes memberships, retires email-code
  and invitation challenges, removes delegated access, and replaces direct account
  identity and device metadata with an irreversible erased identity.
- Security and audit records remain access-restricted with actor/home links removed where the erased
  scope permits.
- Approved catalog and shared price facts may remain without user or household attribution.
- Billing and tax records are not deleted by this worker; their configured or statutory retention
  remains authoritative.
- Restic snapshots age out under infrastructure retention and must not be rewritten in place.

Failures remain in `failed` state with a bounded safe reason. Operators must correct the cause and
create a new request; do not manually mark a destructive request completed.

Email-code challenges are security metadata, not permanent identity records.
Schedule an hourly bounded cleanup:

```bash
php bin/providentia email-code:purge --limit=1000
```

Each pass removes up to the requested limit of expired challenges and up to
1,000 inactive authentication-rate buckets older than
`AUTH_RATE_LIMIT_RETENTION_DAYS` (default two days, configurable from one to
thirty). The limit accepts 1–10,000; repeat passes for a backlog. Active blocks
and recently used rate buckets are preserved. The command uses the current UTC
time and accepts no future cutoff. Its output contains only deletion counts
and completion time, never email addresses or proofs.

The code store keeps only keyed code/binding hashes and expires challenges after
ten minutes; notification payloads are separately encrypted. Account exports
exclude code, binding, access and refresh credential hashes.

Authorized operators can inspect application data using audited administrator
routes. Public sharing consent controls global catalog publication, not this
internal authority. See [current decisions](../unification-decision-record.md).
