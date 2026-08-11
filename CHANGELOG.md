# Changelog

## Unreleased

- Added API v1.13 revision-bound stock-count cancellation to the inventory
  resource and typed synchronization protocol. Cancellation is idempotent,
  publishes the terminal session revision, and creates no stock movement.
- Corrected the AI settings privacy contract: direct extraction uploads are
  transient and not added to media storage, while the separate opt-in private
  media path requires an explicit retention choice and stores ciphertext only.
- Made the home item master a fully typed, deterministic paged feed with total
  and continuation metadata, pack identities, authorized aliases, optional
  home-product linkage, and factual quantities.
- Documented the existing non-disclosing `404` response for revoked or foreign
  homes across synchronization and item-master reads so clients purge stale
  home data instead of retrying indefinitely.
- Fixed login-link session exchange and restoration by returning the stable
  `installationId` separately from the account-scoped `deviceId` in session
  credential responses.
- Added API v1.11 cross-device login-link onboarding with scanner-safe browser
  approval, PKCE-bound device exchange, 15-minute access credentials, sliding
  web/native sessions, first-home ownership, recipient invitation bootstrap,
  platform-administrator governance, editable home settings/permissions, and
  bounded login-request/authentication-throttle retention.
- Added Phase 2 identity, device-session, home, membership, invitation,
  ownership-transfer, tenant authorization, audit, and global-catalog
  foundations.
- Added strict authoritative catalog seed verification/reconciliation and a
  one-command MySQL/Redis/Mailpit developer environment.
- Added the Phase 4 home-scoped offline synchronization prototype with signed
  cursors, optimistic revisions, idempotent operation receipts, frozen
  high-water pagination, bootstrap, tombstones, audit, outbox, and metrics.
- Added production fail-closed secret/mail/URL configuration, CSRF protection,
  explicit CORS, security headers, authentication rate limiting, and focused
  authorization/cursor/synchronization tests.
- Made registration responses generic across new/existing addresses (with
  synchronous-mail timing explicitly retained as a prototype limitation),
  revalidated inviter authority at invitation acceptance, recovered partial
  development registration safely, and fixed completed sync cursors to observe
  the next frozen change window.
- Enforced the synchronization contract's closed envelope/operation shapes and
  aligned private-note and timezone validation documentation with runtime
  behavior.
- Hardened SOLID boundaries by separating application failures from HTTP,
  injecting secure token/UUID generation, decomposing synchronization
  validation/policy/hashing/presentation, and capturing bootstrap records and
  their high-water cursor atomically.
- Expanded focused workflow, policy, security middleware, readiness, and
  synchronization tests; PHPUnit now fails on notices, warnings, deprecations,
  and risky tests.

## 0.1.0 - 2026-07-29

- Established the Providentia Mezzio/Laminas modular-monolith foundation.
- Added explicit factory composition, Doctrine portability proof, public
  server-rendered site, versioned API/design contracts, transactional outbox,
  Enqueue Redis adapter, worker commands, queue metrics, Compose profiles, and
  CI matrices.
- Preserved all Phase 2 business behavior as intentionally unimplemented at
  that release boundary.
