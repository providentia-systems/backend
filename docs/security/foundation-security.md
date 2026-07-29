# Security posture

- Protected requests accept a hashed opaque bearer credential or an
  `HttpOnly`, `Secure`, `SameSite=Strict` cookie credential.
- Cookie-authenticated mutations also require a double-submit CSRF value whose
  server-side hash is bound to the current session.
- Access and refresh credentials are stored only as keyed hashes. Refresh
  credentials rotate with compare-and-swap semantics; reuse of a retired
  credential revokes the affected device-session family.
- Passwords use PHP's Argon2id implementation. Verification, reset, and
  invitation credentials are random, hashed at rest, expiring, and single-use.
- Authentication attempts use persistent IP and normalized email/IP buckets,
  and repeated account failures create a temporary lock.
- Every protected home use case resolves current membership server-side.
  Unauthorized and cross-home object requests return the same `404` posture.
- Owner, manager, member, and viewer ceilings are explicit. Ownership changes
  use a dedicated transactional command; the owner cannot leave or be changed
  through the generic role endpoint.
- Invitations are bound to normalized email, expire after seven days, are
  single-use, and cannot grant a role above the inviter's current ceiling.
  Acceptance rechecks the inviter's active membership and grant authority
  inside the same transaction.
- Sync cursors are HMAC-signed, home-bound, expiring, and opaque. Sync
  operations bind the authenticated user, registered device, route home,
  immutable request hash, base revision, and client operation ID.
- Accepted sync writes atomically persist the resource revision, change feed,
  tombstone where applicable, operation receipt, home audit event, and
  transactional outbox record.
- Tombstones are currently retained without automated compaction. A supported
  offline window and compaction policy must be explicitly approved before any
  `retain_until` values or cleanup job are enabled.
- No database or broker port is published by Compose.
- Public problem responses hide exception detail unless development debug is
  explicitly enabled.
- `/metrics` is operational data and the Caddy baseline denies it at the
  public edge.
- The system-information response contains no host, credential, DSN, user,
  home, path, or secret.
- Queue envelopes are validated, handlers are allow-listed, delivery IDs are
  persisted, and poison messages are retained for review.
- Database changes and required messages use a transactional outbox; broker
  publication is not represented as an atomic database commit.
- Public templates escape dynamic values and use a restrictive CSP at the
  supplied edge.
- `.env`, database files, generated credentials, and dependency output are
  excluded from version control.

Production startup rejects development/placeholder secrets, development token
exposure, plaintext SMTP, and a non-HTTPS public URL. Implicit-TLS SMTP verifies
the peer certificate and hostname. Development Mailpit remains plaintext on the
private Compose network and must never be used as a production profile.

## Known prototype limitations

- Verification, reset, and invitation mail is sent synchronously after the
  database transaction commits. A transient SMTP failure can therefore leave a
  valid token that was not delivered. Move notification intent to a dedicated
  transactional mail outbox before production.
- This foundation is ready to add MFA/passkeys, but neither is active. Final
  authentication methods remain an owner decision.
- Registration returns the same generic `202` shape for a new or existing
  address. Only the explicitly enabled local-development profile can include a
  verification token for setup automation. This is response-shape resistance,
  not full account-enumeration resistance: verification SMTP is still sent
  synchronously only when a token is issued, so request timing and mail-side
  observations can differ. A durable asynchronous mail outbox with a uniform
  request path is required before making a timing-resistance claim.
- The synchronization bootstrap is deliberately capped at 250 current records.
  Larger homes receive a safe `409` instead of a partial snapshot until the
  snapshot-token paged bootstrap is implemented.
- Invitation revocation, step-up/proposed ownership transfer, and a standalone
  synchronization operation-status endpoint are not implemented yet.
- The built-in PHP server, local Compose credentials, exposed development
  tokens, and generated client handoff are local-development mechanisms only.
- Before public deployment, add a production TLS edge, trusted-proxy policy,
  authenticated operational access, container/runtime scanning, encrypted
  backups, restore rehearsal, durable mail delivery, and randomized
  multi-home integration/penetration tests.
