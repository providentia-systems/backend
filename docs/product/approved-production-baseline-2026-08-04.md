# Approved production baseline — 4 August 2026

Status: **approved product direction**  
Applies to: Providentia backend Phases 0–10 and the Flutter client contract

## Release and platform contract

Providentia is a commercial, proprietary SaaS product. The authenticated Flutter
application must support Android, iOS, Windows, macOS, Linux, and modern web
browsers. Platform packaging belongs to the Flutter repository; this backend
must expose one versioned, platform-neutral API and synchronization contract.

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

The default sign-in is passwordless email magic-link authentication. A user
enters an email address and receives a single-use, short-lived link. The API
must not disclose whether an account already exists.

A user may belong to multiple homes. A home has one owner and may have multiple
administrators and members with adjustable roles and granular permissions.
Invitations are email-address based, revocable, expiring, and accepted by the
recipient. Ownership transfer requires an explicit proposal, recipient
acceptance, and recent/step-up magic-link verification. Device sessions are
listed and individually revocable. Industry-standard short access sessions,
rotating refresh credentials, replay detection, and inactivity controls apply.

Passwords are not the primary product workflow. Existing password support may
remain temporarily for migration compatibility until passwordless acceptance is
complete, but marketing and generated clients must default to magic links.

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
