> Current authority: [pre-release product decisions](../unification-decision-record.md).

# Security posture

- Protected requests accept a hashed opaque bearer credential or an
  `HttpOnly`, `Secure`, `SameSite=Strict` cookie credential.
- Cookie-authenticated mutations also require a double-submit CSRF value whose
  server-side hash is bound to the current session.
- Access and refresh credentials are stored only as keyed hashes. Refresh
  credentials rotate with compare-and-swap semantics; reuse of a retired
  credential revokes the affected device-session family.
- There is no password surface: no password field, hash, route, or
  configuration toggle exists. Eight-digit email codes for login, email verification and
  security confirmation expire after ten minutes, allow five attempts and are
  consumed once. Codes and installation binding tokens are keyed-hashed;
  resend requests have a sixty-second cooldown.
- Authentication attempts are throttled through persistent hashed IP and
  normalized email/IP buckets.
- Every protected home use case resolves current membership server-side.
  Unauthorized and cross-home object requests return the same `404` posture.
- Administrator groups are independent of household memberships. Authorized
  operators inspect application data through dedicated audited endpoints; the
  system owner can delegate these rights. Public catalog sharing is separate
  from internal operator access. Secret credentials remain excluded.
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
- Tombstones have an explicit retention boundary; the sync compactor enforces
  the configured supported offline window. Production retention and restore
  policies must be verified before launch.
- No database or broker port is published by Compose.
- Public problem responses hide exception detail unless development debug is
  explicitly enabled.
- `/metrics` is operational data and the Caddy baseline denies it at the
  public edge.
- The system-information response contains no host, credential, DSN, user,
  home, path, or secret.
- Queue envelopes are validated, handlers are allow-listed, delivery IDs are
  persisted, and poison messages are retained for review.
- Transactional account notifications use a durable retryable outbox. Template
  context is authenticated-encrypted at rest and decrypted only by the
  delivery worker.
- Database changes and required messages use a transactional outbox; broker
  publication is not represented as an atomic database commit.
- The backend has no browser login ceremony or public templates. API responses
  use no-store and restrictive security headers.
- `.env`, database files, generated credentials, and dependency output are
  excluded from version control.
- Catalog contribution consent defaults off independently for product
  identity, public product-image metadata, and store-price facts. Moderator
  and global projections are explicit allowlists: they omit household, user,
  consent-receipt, source-fingerprint, and reviewer attribution. Only approved
  rows appear in the public projection, which also omits the moderation ID so
  it cannot be joined to the household or reviewer DTO. Quantities, locations,
  receipts, private notes, and private media are never contribution fields.
  Disabling a category withdraws its pending and approved rows from
  publication without changing the other switches; re-enabling never
  republishes old rows.
- Private AI media requires home permission, is encrypted before object
  storage, is quota controlled, and is either short-lived or retained by an
  explicit household choice. AI results remain proposals and cannot directly
  mutate inventory.

Production startup rejects development/placeholder secrets, plaintext SMTP,
and a non-HTTPS public URL. Implicit-TLS SMTP verifies
the peer certificate and hostname. Development Mailpit remains plaintext on the
private Compose network and must never be used as a production profile.

## Known limitations and acceptance boundary

- Passkeys and MFA are not active. Email-code verification, installation binding, session
  rotation/replay detection, and fresh security confirmation are the current
  production authentication boundary.
- Generic email-code request responses resist direct account discovery, and mail
  delivery is asynchronous. This is not a claim that
  provider-side observations or all timing channels are indistinguishable.
- Bootstrap is paged through a signed, home-bound snapshot cursor and lost
  writes can be queried through the device-bound operation-status endpoint.
  The supported offline window and tombstone compaction still require an
  operator-approved deployment policy and rehearsal.
- Historical `support_access_grants` and fixed platform-role groundwork do not
  grant runtime authority. Current scoped administrator assignments control
  operator access; each household request still requires its own membership.
- The built-in PHP server, local Compose credentials, Mailpit and generated
  client handoff are local-development mechanisms only. OTPs are never exposed
  in API responses, including development.
- Repository controls do not replace production acceptance. TLS/trusted-proxy
  configuration, authenticated operational access, image scanning, encrypted
  backup and restore, key escrow, durable-mail recovery, randomized tenant
  isolation, and penetration evidence remain operator gates in the Phase 10
  acceptance report.
