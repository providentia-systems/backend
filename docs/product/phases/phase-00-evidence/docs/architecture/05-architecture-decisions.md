# Providentia Phase 0 architecture decision record set

**Document status:** Accepted Phase 0 architecture baseline

**Decision authority:** `providentia_master_implementation_prompt_V1.md`

**Scope:** Architecture decisions authorizing the separately reviewable
Providentia Phase 1 foundations. Individual later decisions retain their
recorded deadlines.

## Status convention

- **Accepted — V1 authority:** The master prompt makes the decision explicitly. Phase 0 records, clarifies, and makes the decision testable; it does not reopen it.
- **Proposed for review:** The master prompt requires the concern but leaves a material implementation choice open.
- **Superseded:** A later numbered ADR replaces the decision and gives the migration consequences.

Version numbers, package selections not named in V1, hostnames, domains, commercial terms, and provider contracts remain unapproved until separately verified or decided.

---

## ADR-001: Flutter for the authenticated application

**Status:** Accepted — V1 authority

### Context

Providentia requires one offline-first application experience on Android, iOS,
Windows, macOS, Linux, and modern browsers. Mobile camera and file workflows
are first-class, while desktop stock-management and administration workflows
require adaptive layouts.

### Decision

Build the native and authenticated web application in Flutter/Dart. Use responsive feature modules, Drift-backed repositories, and platform adapters. UI code must not call HTTP, SQLite, provider SDKs, or operating-system services directly.

Phone layouts use bottom navigation; larger layouts use a navigation rail or sidebar and may use master-detail patterns. The Fresh Market design direction and WCAG 2.2 AA requirements apply across targets.

### Consequences

- One feature and design-system implementation serves all authenticated targets.
- Native credentials use the operating-system secure store; web credentials use server-side browser-session patterns.
- Camera, file selection, background work, notification, and secure-storage behavior require platform-specific adapter and acceptance tests.
- Flutter web is an application surface, not the SEO marketing surface.

### Enforcement and evidence

- Flutter analysis must have zero warnings.
- CI builds every approved target and runs repository, Drift, widget, accessibility, responsive, and sync tests.
- Architecture tests or lint rules forbid direct network/database access from widgets.
- The exact Flutter stable version and supported platform floor are pinned and recorded in the support matrix at Phase 1 kickoff.

### Revisit when

A supported target cannot meet a critical capability or accessibility requirement after a measured proof, or the cost of a shared Flutter implementation exceeds separately maintained clients.

---

## ADR-002: Separate server-rendered public web surface

**Status:** Accepted — V1 authority

### Context

The public website is document-centric and requires search indexing, semantic flow content, privacy and support documentation, social metadata, downloads, and fast anonymous rendering. Flutter web is optimized for interactive applications, not text-rich SEO pages.

### Decision

Render the anonymous public website from the backend repository through the Mezzio `PublicSite` module and `laminas-view`. Keep the authenticated web application in Flutter. Prefer distinct public, application, and API hosts so anonymous content, browser sessions, and APIs have explicit security boundaries.

Approved design tokens and public assets are shared as versioned generated artifacts. PHP templates do not reuse Flutter widgets.

### Consequences

- Public content is accessible without executing a Flutter canvas application.
- Authentication hand-off, CSP, caching, and deployment rules differ between public, app, and API hosts.
- Design parity depends on contract-tested tokens rather than shared UI code.
- The official name is Providentia; final hostnames wait for Providentia
  domain acquisition and security/legal due diligence.

### Enforcement and evidence

- Public pages pass semantic HTML, metadata, sitemap, robots, social-preview, performance, CSP, and WCAG 2.2 AA checks.
- Authenticated application routes do not expose private content through public caches.
- Host and cookie scope tests prove that public-site cookies do not unintentionally authorize API access.

---

## ADR-003: Two primary repositories with published contracts

**Status:** Accepted — V1 authority

### Context

The PHP backend and Flutter application have distinct release cadences, toolchains, packaging, secrets, and ownership. A monorepo would couple releases; a third shared-code repository would add premature coordination.

### Decision

Use two primary repositories:

1. `vast-development-method/providentia-laminas` for the backend, authoritative OpenAPI and JSON Schemas, public site, migrations, workers, infrastructure, and migration tooling.
2. `vast-development-method/providentia-flutter` for the authenticated application, Drift migrations, generated client, assets, and platform packaging.

The backend publishes immutable, semantically versioned contract artifacts. The Flutter repository pins a contract release and generates Dart clients and DTOs. Generated code is never hand-edited.

### Consequences

- Cross-repository releases require an explicit compatibility and release-order plan.
- Backward-compatibility checks and a supported-client deprecation window are mandatory.
- Design tokens and schemas are copied only through versioned publication, not filesystem coupling.
- No third shared repository is created in Phase 1.

### Enforcement and evidence

- OpenAPI server conformance and backward-compatibility checks run in backend CI.
- Generated Dart client tests run against the pinned contract.
- CI fails on uncommitted generated-code drift or manual changes to generated files.
- A contract manifest records source version, checksum, generator version, and generation command.

---

## ADR-004: Mezzio plus selected Laminas Components

**Status:** Accepted — V1 authority

### Context

The project requires a self-hosted PHP backend while retaining ownership of its domain architecture, boundaries, use cases, persistence, authorization, and provider adapters.

### Decision

Use Mezzio as the PSR-7/PSR-15 HTTP composition layer and add Laminas Components only for documented module requirements. The selected baseline includes Stratigility, Diactoros, ServiceManager, Config Aggregator, a supported Mezzio router adapter, Problem Details, authentication, authorization/RBAC, sessions where appropriate, input filtering/validation, `laminas-view`, and `laminas-cli`.

Laravel, Symfony full-stack, Laminas MVC, or another backend framework may not be substituted without owner approval. A narrowly scoped standalone component requires an ADR, licence approval, and a demonstrated port boundary.

### Consequences

- The project must provide its own deliberate composition root and module conventions.
- Domain code remains framework-independent.
- Exact compatible stable versions are an engineering verification and lockfile task in Phase 1, not a Phase 0 guess.

### Enforcement and evidence

- Composer dependency checks reject prohibited frameworks and unapproved licences.
- PSR-15 handlers invoke one application use case and contain no business rules.
- `composer.lock`, container image digests, SBOM, and licence reports are release artifacts.

---

## ADR-005: Modular monolith with enforceable module boundaries

**Status:** Accepted — V1 authority

### Context

The initial product needs transactional consistency and strong boundaries without the operational cost and distributed consistency risks of premature microservices.

### Decision

Build one deployable backend modular monolith containing:

`SharedKernel`, `Identity`, `Home`, `Catalog`, `Inventory`, `Purchasing`, `Shopping`, `Synchronization`, `AiIntegration`, `Administration`, `Reporting`, and `PublicSite`.

The dependency direction is:

```text
Http -> Application -> Domain
Infrastructure -> Application and Domain ports
Composition root -> concrete adapters
```

Cross-module collaboration uses published application interfaces, immutable DTOs, or domain events. Modules may not reach into another module's Infrastructure namespace or tables. Circular dependencies are forbidden.

### Consequences

- One relational transaction can preserve cross-cutting invariants where an explicitly owned application service coordinates them.
- Modules can be extracted later only after measured scaling or ownership evidence.
- SOLID is applied pragmatically; speculative one-implementation interfaces and ceremonial layers are not required.

### Enforcement and evidence

- Automated namespace/layer dependency and module-cycle tests.
- Unit, module integration, contract, and authorization tests.
- Database queries and migrations are owned and reviewed by the responsible module.

---

## ADR-006: Explicit ServiceManager factories and constructor injection

**Status:** Accepted — V1 authority

### Context

Hidden dependencies, reflection autowiring, and service locators weaken architectural checks and make security-sensitive object graphs difficult to review.

### Decision

Use `laminas-servicemanager` as the PSR-11 composition container. Construct application services, handlers, repositories, policies, and adapters with explicit factories and constructor injection. The container exists only in the composition root.

Never inject or pass the container into Domain, Application, Infrastructure services, handlers, or repositories. Do not use static service access or reflection autowiring as a substitute.

### Consequences

- Factory code is intentionally explicit and reviewable.
- Configuration changes can fail fast during container-compilation tests.
- Some factory boilerplate is accepted in exchange for a controlled object graph.

### Enforcement and evidence

- Configuration-provider and factory-resolution tests instantiate every public service.
- Static architecture rules forbid `ContainerInterface` outside composition/configuration namespaces.
- Constructors expose all required runtime dependencies.

---

## ADR-007: Doctrine ORM, DBAL, and Migrations behind owned ports

**Status:** Accepted — V1 authority

### Context

The backend needs aggregate persistence, explicit transactions, bulk and reporting queries, schema evolution, optimistic concurrency, and compatibility across production and test databases.

### Decision

Use:

- Doctrine ORM for transactional aggregate persistence;
- Doctrine DBAL for bulk import, reconciliation, reporting, and synchronization queries where hydration is inappropriate; and
- Doctrine Migrations for portable schema evolution.

Repository and transaction interfaces are owned by consuming Domain/Application layers. Doctrine mappings remain in Infrastructure. Doctrine entities, collections, proxies, lazy-loading behavior, and DBAL result types never cross the API boundary.

### Consequences

- Domain objects remain independent of persistence metadata.
- Specialized DBAL work must still preserve tenant scope and transaction rules.
- Migrations must be tested on every server database profile.

### Enforcement and evidence

- Doctrine mapping validation.
- Clean-install, up/down, and supported upgrade-path migration tests.
- Static checks forbid Doctrine namespaces in Domain and Application.
- Integration tests prove rollback, optimistic-lock, unique-constraint, and transaction behavior.

---

## ADR-008: MySQL/MariaDB production compatibility and distinct SQLite server profile

**Status:** Accepted — V1 authority

### Context

Production requires a durable high-volume relational system of record and operator choice between MySQL and MariaDB. Local demonstrations and automated tests require a zero-configuration option.

### Decision

Support both MySQL and MariaDB through Doctrine DBAL for production. Support SQLite through Doctrine only for server-side development, demonstration, and automated-test profiles. SQLite is not a clustered or high-volume production server database.

Use portable migrations and explicit adapters when database behavior differs. Remote production databases use TLS and private-network/firewall restrictions; port 3306 is never publicly exposed.

### Consequences

- Passing SQLite tests never establishes production compatibility.
- JSON, collation, index length, data typing, generated defaults, locking, and concurrency differences require three-engine tests.
- The choice of recommended default deployment profile remains a user decision, while all V1-required profiles can be built.

### Enforcement and evidence

- Equivalent integration suites run against supported SQLite, MySQL, and MariaDB versions.
- Compatibility tests cover transaction isolation, locking, unique constraints, indexes, Unicode/collation, JSON use, and migrations.
- Exact supported engine versions are pinned only after Phase 1 compatibility verification.

---

## ADR-009: Drift/SQLite as the offline-first client store

**Status:** Accepted — V1 authority

### Context

Every Flutter target must remain useful offline, apply local writes immediately, survive process death, and synchronize idempotently. The client must never connect directly to the server database.

### Decision

Use local SQLite through Drift as the client source for UI-observable application state, cache, mutation outbox, sync cursors, tombstones, and local media metadata. Repositories combine local and remote data sources. Each local mutation writes the domain change and a versioned client operation in one SQLite transaction.

Client-created sortable globally unique IDs survive server synchronization. Acknowledgement occurs only after the server accepts the operation.

### Consequences

- Drift migrations and pending-operation upgrades are release-critical.
- Web requires a verified SQLite/WASM persistence implementation.
- Background synchronization is best effort; foreground triggers remain authoritative.
- Local storage contains private data and requires platform-appropriate protection and redacted diagnostics.

### Enforcement and evidence

- Drift migration tests cover every supported upgrade path with queued operations.
- Process-death, lost-response, offline-window, schema-upgrade, and web-persistence tests.
- Static rules prevent UI code from issuing SQL or HTTP calls.

---

## ADR-010: Project-owned queue port, Enqueue Redis adapter, Redis/Valkey broker

**Status:** Accepted — V1 authority

### Context

Email, imports, reconciliation, projections, exports, AI proxy work, retention, and other durable asynchronous work need retries and observability without coupling the domain to a broker or framework.

### Decision

Define an application-owned asynchronous message-bus port and implement the initial adapter with Enqueue Redis transport. Support Redis Open Source and protocol-compatible Valkey deployment profiles. Domain and Application code may not depend on Enqueue, Redis clients, Redis command names, or broker payload details.

Delivery is at least once. Message envelopes are versioned and contain message ID, type, schema version, correlation/causation IDs, creation time, and minimum tenant context. Jobs are idempotent, payloads are minimal, retries are bounded with backoff, and failures reach an explicit dead-letter review record.

When a database commit must cause a job, persist a transactional outbox record in the same Doctrine transaction. A dispatcher publishes it and records dispatch; consumers remain idempotent. A queue acknowledgement is never treated as a committed business transaction.

### Consequences

- Workers run as separate `laminas-cli` processes from the same immutable build.
- Both Redis and Valkey require adapter contract and redelivery testing.
- Broker payloads reference authoritative records and do not carry receipt images, provider credentials, or sensitive content.

### Enforcement and evidence

- Contract tests against both approved broker profiles.
- Retry, timeout, idempotency, redelivery, outbox race, visibility-timeout, dead-letter, and graceful-shutdown tests.
- Metrics expose depth, oldest age, throughput, failure/retry rates, dead letters, heartbeats, and latency without payload content.

---

## ADR-011: AI provider boundary and truthful privacy modes

**Status:** Accepted — V1 authority for the provider boundary and privacy claims; Proposed for review for first-release defaults

### Context

Receipt and stock-photo assistance may use OpenAI, compatible cloud endpoints, self-hosted Ollama, or future on-device models. Provider transport and credentials materially change the privacy claim.

### Decision

Expose AI capabilities through a versioned project-owned provider adapter and a normalized extraction schema. AI is optional and unavailable until a capable provider is configured. Support these architectural modes:

1. encrypted server proxy for cloud use;
2. local/self-hosted direct use; and
3. advanced native direct BYOK only if explicitly approved.

Use exact privacy language:

- “Not stored on Providentia servers” does not mean the image stays on device.
- Cloud processing means the image leaves the device.
- “Strict local mode” permits only an on-device or user-controlled local/LAN endpoint.

AI output is an untrusted proposal. A human confirmation through normal domain commands is required before inventory, receipt, catalog, or price mutations. AI never silently merges catalog identities.

### Consequences

- The default mode, backend transit permission, and advanced BYOK availability remain explicit later-phase decisions.
- Web clients cannot persist provider keys in browser storage.
- Server BYOK requires envelope encryption with the deployment key outside the database, rotation, deletion, per-user isolation, and audit.
- Prompt injection, invalid structured output, model refusal, truncation, timeout, and cost limits are normal threat cases.

### Enforcement and evidence

- Provider contract, schema validation, injection, refusal, timeout, privacy-copy, and no-automatic-mutation tests.
- No credentials in Flutter source, browser storage, logs, analytics, crash reports, screenshots, support exports, or ordinary plaintext columns.
- Strict-local tests prove cloud adapters are disabled for that scan.

---

## ADR-012: Local-by-default media and explicit retention

**Status:** Accepted — V1 authority for local-by-default behavior; Proposed for review for optional backup release timing

### Context

Receipt and stock images may expose household habits, prices, addresses, and unrelated medical material. The handover explicitly shows that filenames and folder names are unreliable classifiers.

### Decision

Keep original receipt and stock images on the originating device by default. Strip unnecessary EXIF from a working copy; show a preview, selected provider, privacy mode, and transmission confirmation before any outbound processing. Persist on the backend only validated structured results, provider/model metadata, timestamps, confidence, and corrections.

Quarantine handover media and classify it visually or deterministically before import. Never send or import medicine-related leaflet images as receipts. Use only synthetic or redacted media in development and CI.

Optional encrypted private-media backup is a separate, off-by-default capability requiring explicit consent, retention settings, encryption design, deletion/export semantics, and threat review.

### Consequences

- Structured history synchronizes between devices; original images do not unless the optional backup is later enabled.
- The UI must tell users when an original exists on only one device.
- Media parsers require size, type, pixel, page-count, decompression, and malware safeguards before decoding.

### Enforcement and evidence

- Tests cover EXIF stripping, confirmation, no-backend-retention, device loss messaging, malformed media, decompression bombs, unrelated/medical rejection, and retention deletion.
- Logs, queues, analytics, fixtures, and support exports contain no original media.

---

## ADR-013: Stock-movement ledger with rebuildable balance projection

**Status:** Accepted — V1 authority

### Context

Stock values come from approved receipts, physical counts, manual corrections, reversals, and synchronization. A mutable quantity alone cannot explain or safely deduplicate history.

### Decision

Use `stock_movements` and closed count sessions as auditable facts. Use `inventory_balances` as a rebuildable projection, not the source of truth. Approved purchase lines create idempotent stock-in movements once. Closing a count creates explicit reconciliation adjustments. Manual corrections require a reason. Deletes use controlled reversals or tombstones.

This is not whole-system event sourcing. The ledger is used only where inventory auditability and reconstruction require it.

### Consequences

- Projection rebuild and drift detection become operational capabilities.
- Corrections append facts instead of erasing history.
- Concurrent count sessions require explicit reconciliation to prevent double counting.

### Enforcement and evidence

- Tests prove receipt reprocessing and sync retries do not duplicate movements.
- Projection rebuild equals the online balance after purchases, counts, corrections, reversals, and tombstone replay.
- Every movement is scoped to `home_id`, has a source/idempotency reference, and is included in authorization tests.

---

## ADR-014: Home-scoped multi-tenancy and deny-by-default authorization

**Status:** Accepted — V1 authority

### Context

A user can own or join multiple homes with different roles. Platform operators and catalog workers must not inherit access to household quantities, prices, receipts, lists, credentials, or media.

### Decision

The tenant is `Home`. Every private record is scoped by immutable `home_id` where applicable. The server derives the authenticated identity, membership, active home, and permission for every request and object. A client-supplied `home_id` is routing context only and never authorization evidence.

Home roles are Owner, Manager, Member, and Viewer. Platform administrator, Catalog curator, Catalog reviewer, and Support operator are separate platform roles. Platform/catalog roles confer no private home access. Support access requires a user-granted, narrowly scoped, visible, time-limited, revocable, audited grant.

Use server-side object-level and use-case authorization, compound tenant indexes/constraints, and deny-by-default policies. Membership and role grants are server-authoritative and cannot be created offline.

### Consequences

- Every protected query and command must include verified tenant context.
- Cache keys, queue references, change cursors, exports, logs, search, and reports must preserve tenant boundaries.
- Ownership transfer, home deletion, export, invitation, and support grants require step-up safeguards and audit.

### Enforcement and evidence

- Horizontal, vertical, and cross-home authorization regression tests for every protected resource.
- Repository contract tests attempt mismatched `home_id`, object ID, cursor, cache key, and role combinations.
- Database constraints make accidental unscoped relationships difficult.
- See `../security/02-authorization-test-matrix.md`.

---

## ADR-015: Global catalog publication separated from private home products

**Status:** Accepted — V1 authority for ownership boundary; Proposed for review for automatic proposal consent

### Context

The catalog must grow without exposing receipt descriptions, prices, quantities, identity, media, or household notes. Unknown products must remain usable immediately.

### Decision

Canonical catalog data, approved aliases, packs, barcodes, categories, icons, and identity rules are global. Quantities, locations, purchases, prices, raw receipt descriptions, preferences, notes, AI settings, and media references remain home-private.

An unknown item becomes a private home product immediately. Any global proposal is sanitized and excludes price, quantity, home identity, image, receipt number, and private notes. Publication requires moderation or a separately approved safe automated policy. Catalog merges are reversible, audited, and re-link all affected history.

### Consequences

- Whether proposals are automatic or per-item opt-in remains a Phase 2/7 product-privacy decision.
- Curators can manage proposals and catalog assets without home-data access.
- Raw receipt aliases do not become global aliases without approval and sanitization.

### Enforcement and evidence

- Proposal fixtures prove excluded private fields cannot cross the boundary.
- Curator/reviewer authorization tests deny all home-private resources.
- Merge tests prove complete relinking, rollback, conflict audit, and history preservation.

---

## ADR-016: Versioned HTTPS API and server-derived tenancy

**Status:** Accepted — V1 authority

### Context

The Flutter client requires typed, idempotent synchronization without database credentials or duplicated PHP/Dart transport models.

### Decision

Expose a versioned HTTPS API described by the backend-owned OpenAPI contract. Generate Flutter bindings from tagged contracts. Mutations contain operation/device/entity IDs, base revision, operation type, timestamp, and payload schema version; the server derives and verifies user and home context.

Use typed JSON, pagination, compression, timeouts, request IDs, explicit retry classification, Problem Details responses, and durable home change cursors. Never expose server database credentials or a public database port to Flutter.

### Consequences

- API compatibility and generated-client verification are release gates.
- Retry safety depends on server idempotency and client outbox semantics.
- “AJAX” and direct MySQL/MariaDB access are not architecture options.

### Enforcement and evidence

- OpenAPI request/response conformance and generated-client tests.
- Tests cover duplicate/out-of-order operations, lost responses, token expiry, membership revocation, clock skew, tombstones, and concurrent devices.

---

## ADR-017: Protect local private data and define device-backup behavior

**Status:** Proposed for review

### Context

Offline-first operation places household stock, purchases, prices, lists,
private products, sync state, and sometimes local media on the device. Server
session revocation prevents later API access but cannot erase an offline copy.
Operating-system and browser backup behavior differs by platform.

### Decision

V1 already accepts two narrower boundaries: credentials require secure storage,
and original receipt/stock media remains local by default. The additional local
database, backup, purge, and platform-baseline rules below are proposed and are
not approved merely by this record.

Keep the Drift database, outbox, caches, and media metadata in the
application-private sandbox. Keep refresh/provider credentials in the OS secure
store, never in Drift. Do not place originals or private exports in shared
storage. Where a platform permits application control, app-managed credentials,
app-managed original-media copies, and transient AI payloads are excluded from
OS/cloud backup. User-selected originals outside the application sandbox remain
subject to the user's device, gallery, and cloud-photo backup settings.

Phase 1 must evaluate a maintained open-source, Drift-compatible
database-encryption option on every target and document the chosen
platform-specific protection. Whether structured, non-secret application data
may participate in an encrypted OS backup is a separate, explicit retention
decision; it is not inferred here.

Logout revokes the session and offers a clear local-data removal path.
Account/home deletion and device de-registration define local purge behavior,
including pending outbox consequences. The UI and security documentation state
that remote revocation is not remote wipe.

### Consequences

- Offline availability and encryption key lifecycle must be tested together.
- Web protection depends on browser origin isolation and device/browser
  security; a compromised origin or unlocked profile remains a material risk.
- Backup/restore and uninstall/reinstall can affect pending operations and must
  not silently duplicate them.
- Support cannot promise remote erasure of an offline or compromised device.

### Enforcement and evidence

- Per-platform sandbox, backup-exclusion, encryption, logout/purge, account
  deletion, backup/restore, browser eviction, and revoked-offline-device tests.
- Security review verifies that diagnostics, crash reports, exports, and shared
  directories do not receive private local records or media.
- The published platform matrix identifies any target where database-level
  encryption is unavailable and the required device-security baseline.

---

## Phase 1 decision boundary

The owner reviewed Phase 0 and authorized repository foundations on
2026-07-29. These ADRs do **not** silently answer the remaining product
decisions in `../decisions/01-phase1-blockers.md`. AI defaults, optional media
backup, authentication expansion, catalog proposal consent,
internationalization scope, commercial terms, and deployment defaults retain
their recorded gates. The official project/product name is resolved as
`Providentia`; only domain/app-store/trademark due diligence remains before
public launch.
