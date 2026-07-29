# Foundation security posture

- No business or private household data endpoint exists in Phase 1.
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

Before public deployment, add TLS, trusted-proxy handling, central request IDs,
rate limiting, authenticated operational access, container/runtime scanning,
secret injection, encrypted backups, and restore rehearsal. Tenant isolation,
session security, CSRF, device credentials, and authorization tests are Phase 2
work because no protected business resource exists yet.

