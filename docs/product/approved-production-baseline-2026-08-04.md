# Approved production baseline — 4 August 2026

Status: **approved product direction**  
Applies to: Providentia backend Phases 0–10 and the Flutter client contract

Amended 2 September 2026: emailed login links now use the backend's narrow
browser approval ceremony; application links remain only for step-up actions.

## Release and platform contract

Providentia is a commercial, proprietary SaaS product. The authenticated Flutter
application must support Android, iOS, Windows, macOS, Linux, and modern web
browsers. Platform packaging belongs to the Flutter repository; this backend
must expose one versioned, platform-neutral API and synchronization contract.
The backend exposes versioned JSON API, health checks, explicitly secured
metrics, and narrow owner CLI commands; it renders no public site or operational
GUI. Its sole HTML exception is the unauthenticated login-link approval/denial
ceremony. Every authenticated account, home, invitation, device-session, and
platform-administration screen belongs to the appropriate Flutter application.

There is no artificial household, product, inventory, or catalog-size product
limit. Operational limits exist only to protect availability and must use
pagination, quotas, back-pressure, and subscription entitlements rather than
silently truncating data.

## Offline-first contract

All pantry mutations must be representable as typed, idempotent synchronization
operations. A client may work offline and later converge without duplicate
movements or lost accepted changes. This includes inventory adjustments, count
sessions and lines, receipts and review decisions, shopping lists and lines,
private products, preferences, and catalog proposals.

The server must provide paged bootstrap snapshots, operation-status recovery,
durable tombstones for the supported offline window, deterministic conflict
policies, and two-device acceptance evidence. Server authorization remains
authoritative for every replayed operation.

## Identity and homes

The only primary sign-in workflow is an email-only **login link**. The
user enters their email address in the originating client. That client creates
a private poll token and PKCE verifier and sends only their challenges, a state
value, and device metadata when it starts the login-link request. The API gives
the same generic response whether or not the account already exists.

The emailed fragment link opens the backend approval origin in any browser,
possibly on a different device. Opening it must show an explicit review and
must not approve the request: approval or denial is a separate deliberate form
submission. The browser receives no originating session. The originating
client polls with its private token and exchanges the approved request with the
PKCE verifier; polling is the authoritative cross-device handoff.

Access, refresh, session, poll, and PKCE credentials must never appear in the
email URL query, HTTP request target, analytics, or logs. The email carries
only a short-lived, single-use approval credential in the URI fragment. The
browser launch removes it from the address bar before posting it to a clean,
same-origin capture route. Requests expire, are cancellable, and are
single-exchange. Email scanners, replay, a wrong application binding, a wrong
state, a wrong poll token, or a wrong PKCE verifier must not create a session.

An unknown address is not provisioned merely because a request was started.
Deliberate approval creates and verifies the account idempotently, creates one
editable home named `My home`, and grants that user its `owner` membership. The
originating client's successful exchange issues its session and selects that
home. An existing account retains its homes and receives no additional default
home.

A user may belong to multiple homes, with a distinct role in each. The
household roles are `owner`, `manager`, `member`, and `viewer`; platform roles
are separate and never imply home membership. Invitations are email-address
based, revocable, expiring, discoverable after sign-in, and accepted explicitly
by the intended verified recipient. Ownership transfer requires an explicit
proposal, recipient acceptance, and recent login-link step-up verification.

Device sessions are listed and individually revocable. Access credentials last
approximately 15 minutes. Web sessions have a sliding 30-day inactivity limit;
native Android, iOS, Windows, macOS, and Linux sessions have a sliding 60-day
inactivity limit. Normal refresh/use extends the relevant inactivity deadline
up to the backend policy; a client may request a shorter duration but never a
longer one. Rotation, replay detection, logout, revocation, and security events
end or constrain sessions regardless of those maximums.

The first platform administrator is configured by normalized email through the
deployment bootstrap setting and receives that role only after successfully
verifying the address through an administrator-bound login link. That grant creates no home access.
An administrator may list, add, and revoke other administrators through the
authenticated, audited API, including a pending email grant for a person who
has not signed in yet. Revisions prevent stale mutations, every change records
its actor, and the final active administrator cannot be revoked.

Password registration, verification, reset, and login may remain temporarily
as explicitly enabled development or migration-compatibility surfaces. They
are not a production onboarding fallback and must not appear as the default in
marketing, generated clients, tester instructions, or release acceptance.
*Superseded 26 August 2026: the zero-password unification
([decision record](../unification-decision-record.md)) removed every password
surface from API 1.19.0; no password compatibility remains.*

## AI and media

OpenAI is the primary supported AI provider. Anthropic Claude, Google Gemini,
xAI, and self-hosted vision-capable OpenAI-compatible/Llama or Ollama endpoints
are supported adapters behind project-owned interfaces.

Household owners and delegated administrators may configure multiple encrypted
household-owned provider credentials and grant household roles permission to
use them. A policy may select one provider for extraction and another for
independent validation. It must support ordered failover, budgets, timeouts,
capability checks, discrepancy reporting, and a mandatory human-review boundary
before inventory mutation.

Images and videos may transit the backend for processing. Originals and derived
media are private household data. Persistence is an explicit per-household
choice: transient processing deletes source bytes after the processing window;
private retention consumes the household's media quota. The initial included
allowance is configurable (2 GiB is the commercial starting assumption, not a
hard-coded domain constant). Media deletion, export, retention, encryption, and
provider disclosure must be visible to the user.

Multi-photo and video-assisted counts must ask the AI orchestration layer to
identify overlap and potential duplicate observations. The server persists
evidence, confidence, and deduplication decisions, but never treats AI output as
an automatic inventory adjustment.

## Catalog and community data

Every household has a private catalog view backed by the global catalog.
Scanning an unknown product creates a usable private product immediately.
Submitting sanitized product metadata, public product images, store identity,
and observed prices to the global pool is opt-in. Household identity and
quantities are never published.

Global submissions enter a reviewer/curator workbench before publication.
Duplicate detection, aliases, reversible merges, provenance, and audit history
are mandatory. Operators can offer configurable subscription benefits for
consented sharing; such benefits are entitlement rules, not hard-coded prices.

## Localization

The domain model supports BCP 47 locales, ISO 4217 currencies, IANA time zones,
Unicode text, and explicit measurement units. en-NA, NAD, and
Africa/Windhoek remain the migration baseline, not a permanent global default.
Reference data must be updateable through versioned imports or a replaceable
adapter; no external free API may be a runtime single point of failure.

## Commercial administration

Operators manage plans, feature entitlements, quotas, trials, vouchers, and
free access through an administrative API/GUI. Payment adapters must support
PayPal and a PCI-scoped hosted credit-card processor. Providentia stores
provider customer/payment references and webhook evidence, never raw card data.
Payment providers, plans, and features can be enabled or disabled independently.

## Infrastructure and operations

The backend is deployable without a domain assumption. TLS and domains terminate
at an operator-controlled edge such as HAProxy/OPNsense; a direct Caddy TLS
profile may also be documented.

MySQL is the preferred production database. MariaDB is a fully supported
production profile. Both self-hosted Compose and external/managed database
connections are documented. Redis or compatible Valkey provides queues,
coordination, and durable append-only persistence.

Transactional email uses standard SMTP, including Mailcow-compatible servers.
Authentication, invitation, and billing notifications use a durable
transactional outbox with retry, dead-letter state, and operator replay.

Application data persistence is declared explicitly. Restic remains an
infrastructure concern, not an in-application backup implementation. Production
documentation must identify database dumps, object/media storage, encryption
keys, and configuration that require backup and must include restore rehearsal
commands.

## Acceptance principle

A phase is complete only when implementation, migrations, contracts, automated
tests, human acceptance evidence, production configuration, rollback, and
documentation agree. Unchecked acceptance items cannot be represented as
complete.
