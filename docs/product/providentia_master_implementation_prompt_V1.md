# Providentia

## Master architecture and implementation prompt — Version 1

Prepared from the full `Pantry_Stock_Project_Handover_2026-07-29.zip`
handover on 29 July 2026.

Version 1 architecture approved on 29 July 2026: Mezzio plus Laminas
Components, Doctrine, and Redis-compatible queuing. The backend and Flutter
application are separate repositories.

Naming amendment approved on 29 July 2026: `Providentia` replaces `StockHome`
as the official project and product name and as the base for namespaces,
packages, contracts, deployment resources, documentation, and repository
names. The authoritative repositories are
`vast-development-method/providentia-laminas` and
`vast-development-method/providentia-flutter`.

Owner implementation amendment approved on 30 July 2026:

- Providentia is proprietary. No distribution licence is granted or selected
  yet; licensing is deferred until a later explicit decision.
- The permanent application/distribution identifier is
  `com.vastdevelopmentmethod.providentia`.
- MySQL is the preferred production database. MariaDB remains a tested
  compatibility profile and SQLite remains the development/test profile.
- Redis Open Source is the preferred production queue broker. Valkey remains a
  tested Redis-protocol-compatible profile.

Owner licensing amendment approved on 26 August 2026:

- The licensing deferral above is superseded. Providentia is proprietary
  software under `LicenseRef-Proprietary`. The root
  [LICENSE](../../LICENSE) grants no licence except as expressly authorised in
  writing by Vast Development Method Trading Pty Ltd; viewing or forking the
  public GitHub repository grants no licence.

This document is intended to be given directly to an AI engineering agent. It
defines the complete product direction, the evidence that must be preserved,
the target architecture, the non-negotiable privacy and security boundaries,
the phased delivery plan, and the first assignment.

---

# MASTER PROMPT

## 1. Your role and mandate

Act as the principal software architect and senior implementation engineer for
a production-grade, high-volume, multi-platform household stock-control
product.

The current project is a working React/TypeScript PWA formerly named
`StockHome`.
It was developed through a substantial product-discovery and data-consolidation
process. It is not a disposable mock-up. Your job is to preserve its proven
workflows, design language, product data, aliases, product-identity rules,
history, and user decisions while replacing its prototype storage and hosting
model with a secure, scalable, offline-first Flutter application and a
production backend.

The official project and product name is `Providentia`. This name forms the
base of repository names, application identifiers, namespaces, package names,
deployment resources, contracts, documentation, and future related projects.
`StockHome` is retained only when identifying the historical React/TypeScript
prototype or its evidence.

Work incrementally. Do not attempt to implement the entire system in one
unreviewable change. Every phase must end in a coherent, tested, documented,
deployable state with a parity report and explicit evidence.

## 2. Authoritative input and required initial investigation

The primary source package is:

`Pantry_Stock_Project_Handover_2026-07-29.zip`

Before changing code:

1. Extract it safely and verify `00_START_HERE/SHA256SUMS.txt`.
2. Read every file in `documentation/`.
3. Inspect the source in `01_app_source/vdm-pantry-stock/`.
4. Inspect the migration exports in `03_data_exports/`.
5. Review the spreadsheets in `04_original_project_files/Stock control/`,
   especially:
   - `Pantry_Household_Inventory_Monthly_Purchases_UPDATED.xlsx`
   - `Pantry_Product_Consolidation_Review(1).xlsx`
6. Inspect the selected visual direction in
   `05_design_and_review/fresh-market-selected-direction.png`.
7. Verify and inspect the Git bundle in
   `06_git_history/vdm-pantry-stock.git.bundle`.
8. Inventory and classify every supplied image. Do not infer its contents from
   its directory or filename.
9. Produce a written source-evidence report before proposing migrations.

Use this source-of-truth precedence:

1. Explicit decisions in this master prompt.
2. Explicitly approved user decisions in
   `Pantry_Product_Consolidation_Review(1).xlsx`.
3. Machine-readable product rules in `product-rules.json`.
4. Current post-consolidation data in `03_data_exports/`.
5. Current application behavior in `app/PantryApp.tsx`.
6. Earlier spreadsheets and source media as lineage and evidence.

Earlier workbooks must not silently overwrite later product decisions. Blank
review cells and values such as `Not sure` are not approvals.

## 3. Verified baseline that must be preserved

The handover represents app version 7 at source commit:

`b01b5ef14783b4ad1c1bfc0be7ba0dba32629af8`

Verified data totals:

| Record set | Count |
|---|---:|
| Product-and-pack entries in the current item master | 292 |
| Current counted stock lines | 60 |
| Recent receipt-derived purchase lines | 16 |
| Historical shopping lines | 452 |
| Monthly-purchase summary rows | 261 |
| Hidden alias groups | 13 |
| Individual hidden aliases | 19 |
| Product-identity rules | 19 |
| Unresolved current-stock descriptions | 8 |

The current 60 stock lines contain 159 counted units or packs in total. The
prototype fixed threshold marks 44 of those 60 lines as low stock, demonstrating
why the future system must replace the `quantity <= 2` rule with a contextual,
explainable recommendation model.

The eight unresolved descriptions must remain unresolved until evidence or an
explicit user decision resolves them:

- Elbow Macaroni
- Elbow Pasta
- Tea
- Candi Soda
- Washing Powder - Sunlight
- Washing Powder - Bio Classic
- Insect Spray - Doom
- Trotters Jelly

The permanent import must reconcile these exact totals and emit a
machine-readable reconciliation report. Any difference must be explained and
approved. Never discard a raw purchase description after matching it.

## 4. Current implemented behavior

Create a feature-parity matrix from the source and retain at least the following
behavior.

### 4.1 Dashboard

- Personal greeting.
- Item, counted-stock, and recent-purchase totals.
- Quick access to receipt capture and stock-photo counting.
- Recent product rows with quantities and low-stock indications.
- Navigation to low-stock shopping suggestions.
- Four primary application areas: Home, Stock, Purchases, and Lists.

### 4.2 Stock

- Two views:
  - currently counted stock
  - complete product-and-pack item master
- Search over canonical product names, hidden aliases, brands, pack sizes, and
  categories.
- Category filtering.
- Manual quantity adjustment.
- Stock-photo count sessions.
- Keep the selected stock photo visible while products are counted.
- Show uncounted products before products already confirmed from the photo.
- Mark confirmed rows as `Photo counted`.
- Add previously uncounted item-master products to home inventory.

### 4.3 Purchases

- Recent receipt-derived purchases grouped by date and store.
- Historical purchases grouped and summarized by month.
- Receipt photo or PDF selection.
- Recent spend and receipt-group summaries.

The current receipt picker stores only a filename. It performs no upload, OCR,
vision analysis, byte retention, or durable matching. The production
implementation must not describe the existing behavior as a real scan.

### 4.4 Shopping lists

- Suggested items derived from low stock and April-June 2026 purchase history.
- A manually entered list alongside suggestions.
- Check-off state and progress.
- Suggested quantity to buy.

The current recommendation is only a prototype:

`max(1, ceil(three-month purchase average - current quantity))`

Do not preserve that formula as the final intelligence model.

### 4.5 Current visual language

Preserve the approved Fresh Market direction:

- warm cream canvas
- dark forest green and fresh green accents
- compact recent-item rows
- soft panel shadows
- rounded touch-friendly controls
- clear quantity and low-stock states
- bottom navigation on phone-sized layouts
- reduced-motion support
- visible keyboard focus

Adapt it for larger screens instead of stretching the phone layout. Use bottom
navigation on phones, a navigation rail or sidebar on tablets and desktops, and
responsive master-detail views where they improve stock counting and catalog
administration.

## 5. Current limitations that must not be carried forward

The current PWA has:

- static JSON compiled into the client
- five browser-local storage keys for operational changes
- no central operational database
- no server application API
- no real multi-user authentication in the application
- no home or membership model
- no durable cross-device synchronization
- no receipt OCR or vision processing
- no central image persistence
- an empty database schema
- null D1 and R2 bindings
- only a network-first service-worker shell
- receipt filenames without receipt bytes
- only one shallow rendered-HTML test
- a portrait-first PWA manifest that is unsuitable as the desktop contract

The existing optional ChatGPT identity helper is not used to protect the app.
The current live installation relies on a private hosting access rule and falls
back to the name `Roline` when no identity header is present. Do not treat this
as production authentication.

## 6. Architecture decision

### 6.1 Decision

Use Flutter for the authenticated application experience across:

- Android
- iOS
- Windows
- macOS
- Linux
- modern web browsers

Use a separate document-centric, server-rendered public marketing surface for
the anonymous website. Do not build the SEO-oriented marketing site as a
Flutter canvas. Flutter remains the correct choice for the interactive
application, but its own web guidance states that it is not suitable for
text-rich, flow-based static websites and that its web output is not optimized
for search indexing.

Recommended topology:

| Surface | Technology | Purpose |
|---|---|---|
| Native and authenticated web app | Flutter/Dart | Unified household stock application |
| Public website | Mezzio with `laminas-view` | Server-rendered marketing, documentation, privacy, downloads, and sign-in entry |
| API and domain backend | Mezzio/PHP with Laminas Components | Authentication, homes, catalog, inventory, sync, administration |
| Production database | MySQL or MariaDB | Durable relational system of record |
| Server development/test database | SQLite | Zero-configuration test and local demonstration profile |
| Client database | SQLite through Drift | Offline-first local source and synchronization outbox |
| Persistence layer | Doctrine ORM, DBAL, and Migrations | Repository adapters, transactions, portable schema, and migrations |
| Background jobs | Project-owned queue port with Enqueue Redis adapter | Durable asynchronous work without framework lock-in |
| Queue broker | Redis Open Source or compatible Valkey deployment | Queue transport, delayed delivery, retries, and coordination |
| Shared public assets | S3-compatible object storage | Catalog icons and approved public assets |
| Optional private media backup | S3-compatible object storage, disabled by default | Explicit opt-in encrypted backup only |

Use a modular monolith for the first production backend. It gives strong
module boundaries and one transactional database without imposing the
operational and consistency cost of premature microservices. Design module
contracts so a module can be extracted later if measured scaling or ownership
needs justify it.

### 6.2 Why Mezzio plus Laminas Components is the selected backend

Use the current stable PHP, Mezzio, Laminas, and Doctrine releases that satisfy
the published support policy. Pin exact dependency versions in
`composer.lock`.

Mezzio is the selected HTTP application framework because it provides a small,
standards-based PSR-7 and PSR-15 middleware core while leaving architectural
decisions with this project. Laminas Components provide enterprise-grade,
independently composable implementations for dependency injection,
configuration, HTTP messages, validation, authentication, authorization,
sessions, caching, templating, CLI tooling, and other required concerns.

This selection is deliberate:

- the project owns its module boundaries, domain model, use cases, persistence
  ports, queue ports, and authorization policies
- Mezzio supplies the HTTP and middleware composition layer, not the domain
  architecture
- `laminas-servicemanager` supplies explicit factory-driven dependency
  injection through PSR-11
- Doctrine supplies ORM, DBAL, migrations, transactions, optimistic locking,
  and portable database integration
- Redis queue access remains behind an application-owned interface
- each external provider remains an adapter, not a dependency of the domain
- the result is fully self-hosted PHP without a proprietary platform

Do not substitute Laravel, Symfony full-stack, Laminas MVC, or a different
backend framework unless the project owner explicitly revisits this
architecture decision. Individual open-source components may be adopted when
they satisfy a clearly defined port and pass the dependency and licence policy.

Generate and version an OpenAPI contract. Generate the Flutter API client and
model bindings from that contract. Do not maintain hand-written request and
response types independently in Dart and PHP.

### 6.3 Required backend component model

The backend must use explicit constructor injection. The composition root uses
`laminas-servicemanager` factories to construct services. Never inject or pass
the container into domain, application, handler, or repository classes. Do not
use the service locator pattern, hidden static dependencies, or reflection
autowiring as a substitute for an intentional object graph.

Use Mezzio and Laminas components selectively:

- `mezzio/mezzio` and `laminas/laminas-stratigility` for PSR-15 pipelines
- `laminas/laminas-diactoros` for PSR-7 and PSR-17 messages and factories
- `laminas/laminas-servicemanager` for factory-driven dependency injection
- `laminas/laminas-config-aggregator` for module configuration
- a supported Mezzio router adapter behind `RouterInterface`
- `mezzio/mezzio-problem-details` for API problem responses, updated to the
  current Problem Details RFC contract
- `mezzio/mezzio-authentication` and project-owned authentication adapters
- `mezzio/mezzio-authorization` plus
  `laminas/laminas-permissions-rbac` and domain authorization policies
- `mezzio/mezzio-session` and
  `mezzio/mezzio-authentication-session` where server-side browser sessions are
  appropriate
- `laminas/laminas-inputfilter`, `laminas/laminas-filter`, and
  `laminas/laminas-validator` at input boundaries
- `laminas/laminas-view` for the anonymous server-rendered public site
- `laminas/laminas-cli` for migrations, imports, workers, maintenance, and
  scheduled commands
- Doctrine ORM for aggregate persistence, Doctrine DBAL for specialized
  queries and bulk work, and Doctrine Migrations for schema evolution
- Enqueue with its Redis transport as the initial asynchronous transport,
  hidden behind project-owned producer and consumer interfaces

Do not install every Laminas component by default. Add a component only when a
module has a documented requirement for it.

For browser authentication, prefer secure `HttpOnly`, `Secure`, `SameSite`
cookies with CSRF protection. For native Flutter clients, use short-lived
revocable access credentials and rotated device-bound refresh credentials held
in the operating-system secure store. Store only hashes of opaque refresh
credentials on the server. Authentication transport must not leak into the
domain model.

The authenticated household application and catalog-administration workbench
remain Flutter so they share one responsive design system. The backend
repository renders only the anonymous public site, authentication hand-off
pages where needed, transactional email, and error pages. Share approved design
tokens and assets between the two repositories as versioned generated
artifacts; do not attempt to share Flutter widgets with PHP templates.

### 6.4 SOLID and module boundaries

Organize the backend as bounded modules:

- `SharedKernel`
- `Identity`
- `Home`
- `Catalog`
- `Inventory`
- `Purchasing`
- `Shopping`
- `Synchronization`
- `AiIntegration`
- `Administration`
- `Reporting`
- `PublicSite`

Each business module uses this dependency direction:

```text
Http -> Application -> Domain
Infrastructure -> Application and Domain ports
Composition root -> all concrete adapters
```

Each module contains only the layers it needs:

```text
ModuleName/
  Domain/
  Application/
  Infrastructure/
  Http/
  ConfigProvider.php
```

Enforce these rules:

- Domain code is pure PHP and has no dependency on Mezzio, Laminas,
  Doctrine, Redis, HTTP, or Flutter.
- Application services express complete use cases and depend on domain types
  and interfaces.
- Repository, clock, identity, transaction, storage, mail, AI, and queue
  interfaces are owned by the layer that consumes them.
- Infrastructure implements those interfaces with Doctrine, Redis, object
  storage, mail, and provider-specific adapters.
- PSR-15 handlers parse transport input, invoke one application use case, and
  map the result to an HTTP response. They contain no business rules.
- Doctrine mappings live in Infrastructure so persistence metadata does not
  control the domain model.
- Cross-module calls use published application interfaces, immutable DTOs, or
  domain events. No module reaches into another module's Infrastructure
  namespace or tables.
- Circular module dependencies are forbidden and checked automatically.
- Apply SOLID principles pragmatically. Do not create interfaces with only
  speculative value or split simple behavior into ceremonial layers.
- Use a stock-movement ledger and transactional outbox where the domain
  requires them; do not turn the whole system into event sourcing.

Add architecture tests that enforce namespace and dependency rules, plus
module-level unit, integration, contract, and authorization tests.

### 6.5 Alternatives considered

- Continuing only as a PWA would reduce migration work but would preserve the
  current weaknesses around native camera workflows, secure local credentials,
  desktop packaging, durable SQLite behavior, and consistent offline support.
- Electron or Tauri would be reasonable if the product were desktop-first, but
  the receipt and stock-photo workflows make mobile support first-class.
- React Native with separate Windows, macOS, and web layers would create more
  platform-specific surface area than Flutter for this product.
- A Dart backend would reduce the number of implementation languages but has a
  smaller mature ecosystem for this project's Doctrine-style persistence,
  MariaDB/MySQL operations, queue tooling, and PHP maintainership.
- Symfony is a credible PHP alternative with excellent standalone components,
  but its full-stack conventions are not selected because this project
  explicitly wants to own the architecture. Use of a narrowly scoped
  standalone component requires an ADR and must not make Symfony the hidden
  application framework.
- A TypeScript or Python backend is technically compatible with Flutter, but
  neither offers a decisive project advantage over the selected PHP stack.

Flutter is therefore the preferred application framework, with a deliberately
separate public web surface and a Mezzio/Laminas modular backend.

### 6.6 Open-source and anti-lock-in boundary

Every component required for normal operation must be open source,
self-hostable, and replaceable. Core operation must not require a paid language
subscription, proprietary backend service, hosted identity provider, hosted
database, hosted queue, or hosted AI provider.

CI must:

- allow only approved OSI licences in required production dependencies
- reject proprietary, Commons Clause, SSPL-only, or BSL-only dependencies
  unless an explicitly optional adapter has been separately approved
- generate an SBOM and third-party licence report for every release
- scan Composer, Dart, container, and system dependencies
- pin dependencies and container images

Redis Open Source may be used under an approved open-source licence. Valkey
must also remain a supported Redis-protocol deployment option. Queue and cache
code must depend on project-owned interfaces so either can be selected without
changing domain or application code.

## 7. Supported platform contract

Never promise to support "every version" of Windows, macOS, or Linux. That is
not technically testable or supportable.

At project kickoff, record the exact current Flutter stable version and publish
a support matrix. As of the handover investigation, Flutter 3.44.7 documents:

| Platform | Supported deployment baseline |
|---|---|
| Windows | Windows 10 and 11, x64 and Arm64 |
| macOS | macOS 10.15 Catalina through macOS 26, x64 and Arm64 |
| Debian | Debian 10 through 13, x64 and Arm64 |
| Ubuntu | Ubuntu 20.04 LTS through 26.04 LTS, x64 and Arm64 |
| Chrome | Latest two supported releases |
| Firefox | Latest two supported releases |
| Safari | 15.6 and newer |
| Edge | Latest two supported releases |
| Android | API 24 through 37 at the documented baseline |
| iOS | iOS 13 through 26 at the documented baseline |

If a newer Flutter stable version is adopted, update the matrix from official
Flutter documentation and rerun the full platform suite before changing the
published contract.

Required release formats:

- signed Windows installer or MSIX
- signed and notarized universal macOS application where feasible
- documented Linux packages, beginning with AppImage and Debian package
- hosted Flutter web build with PWA metadata
- Android application bundle and test APK
- signed iOS archive when Apple credentials are available

Do not claim support for an operating-system version that CI and release
testing do not cover. Other Linux distributions may be documented as
best-effort community targets.

## 8. Repository and project organization

Use two primary version-controlled repositories with independent releases:

1. a PHP backend repository
2. a Flutter application repository

Do not combine them into a monorepo. Do not create a third shared-code
repository at the outset. Share only versioned contracts, schemas, design
tokens, and generated artifacts.

Recommended backend repository:
https://github.com/vast-development-method/providentia-laminas
```text
providentia-backend/
  public/
  bin/
  config/
  src/
    SharedKernel/
    Identity/
    Home/
    Catalog/
    Inventory/
    Purchasing/
    Shopping/
    Synchronization/
    AiIntegration/
    Administration/
    Reporting/
    PublicSite/
  contracts/
    openapi/
    json-schema/
    design-tokens/
  templates/
  migrations/
  tests/
  infrastructure/
    compose/
    caddy/
    backup/
    monitoring/
  migration/
    source/
    importers/
    fixtures/
    reconciliation/
  docs/
    architecture/
    deployment/
    operations/
    security/
    product/
  .github/
    workflows/
```

Recommended Flutter repository:
https://github.com/vast-development-method/providentia-flutter
```text
providentia-app/
  lib/
    core/
      database/
      networking/
      synchronization/
      security/
      design_system/
    features/
      identity/
      homes/
      catalog/
      inventory/
      purchasing/
      shopping/
      ai_integration/
      administration/
      reporting/
  assets/
  contracts/
    generated/
    design-tokens/
  integration_test/
  test/
  tool/
  docs/
  .github/
    workflows/
```

The backend repository owns the authoritative OpenAPI and JSON Schema
contracts. Every tagged backend API release publishes immutable contract
artifacts. The Flutter repository pins a contract release and generates its
API client and DTOs from it. Generated code must not be hand-edited.

Contract changes require:

- semantic API versioning
- backward-compatibility checks
- server conformance tests
- generated Dart client tests
- a documented release order when both repositories must change
- a deprecation window for supported client versions

The backend repository also owns Compose, database migrations, queue workers,
public-site templates, backend deployment, migration importers, and operational
documentation. The Flutter repository owns all authenticated application and
catalog-administration UI, local Drift migrations, platform packaging, and
client release workflows.

Keep the original ZIP unchanged under a protected migration evidence location
or reference it through a documented checksum. Do not commit real secrets,
medical documents, private receipt images, generated credentials, or production
database dumps.

Use architecture decision records for at least:

- Flutter and public-web split
- two-repository split and contract publication
- Mezzio plus Laminas Components backend selection
- modular-monolith and module dependency rules
- Laminas ServiceManager factory-driven dependency injection
- Doctrine ORM/DBAL/Migrations persistence strategy
- local SQLite and synchronization design
- MySQL/MariaDB compatibility strategy
- Redis/Valkey queue transport, Enqueue adapter, and queue semantics
- tenant isolation
- product-catalog publication model
- AI-provider and credential modes
- media privacy and retention
- inventory ledger and projection design

## 9. Client storage and server database rules

### 9.1 No direct database connection from Flutter

The Flutter application must never connect directly to MySQL or MariaDB. It
must never contain the database host, database user, database password, or a
publicly reachable database port.

The client receives only an HTTPS API base URL and public application
configuration. Database credentials belong only on the backend server.

The word `AJAX` is not the architecture contract. Use a versioned HTTPS API with
typed JSON, idempotent operations, pagination, compression, timeouts, retry
classification, and observable request IDs.

### 9.2 Two distinct SQLite roles

SQLite has two valid but different roles:

1. Every Flutter client uses a local SQLite database through Drift as its
   offline-first store, cache, mutation outbox, sync cursor store, and local
   media metadata store.
2. The Mezzio backend supports SQLite through Doctrine as a
   zero-configuration development, demonstration, and automated-test profile
   when MySQL or MariaDB is not configured.

SQLite is not the high-volume production server database.

### 9.3 Production database compatibility

Production must support both MySQL and MariaDB through Doctrine DBAL. Use
portable Doctrine migrations and avoid relying on behavior that differs
between MySQL, MariaDB, and SQLite without an explicit adapter and tests.

Use Doctrine ORM for transactional aggregate persistence and DBAL for
specialized reporting, bulk import, reconciliation, and synchronization
queries where ORM object hydration is inappropriate. Domain and application
code depend on repository and transaction interfaces, not on Doctrine classes.
Do not expose Doctrine entities or lazy-loading proxies through the API.

CI must execute database integration tests against:

- SQLite
- a supported MySQL release
- a supported MariaDB release

SQLite-only passing tests are insufficient because SQLite can hide locking,
type, JSON, index-length, collation, and concurrency differences.

Use TLS for remote database connections. Keep the database on a private network
or firewall allowlist. Do not expose port 3306 publicly.

## 10. Multi-home tenancy and authorization

The tenant concept is `Home`.

A user may:

- own one or more homes
- be invited to other homes
- switch between homes
- have different roles in different homes
- leave a home without deleting it
- help manage a relative's home without exposing one home's data to another

All private records must be scoped by an immutable `home_id`. Do not accept a
client-supplied home identifier as authorization. Resolve the authenticated
identity, membership, active home, and permission on the server for every
request and every object.

Recommended home roles:

| Role | Capabilities |
|---|---|
| Owner | Full home control, membership, ownership transfer, export, deletion |
| Manager | Home settings, invites, stock, purchases, lists, locations |
| Member | Normal stock, receipt, count, and shopping-list work |
| Viewer | Read-only access |

Recommended platform roles:

| Role | Capabilities |
|---|---|
| Platform administrator | Platform operation and account administration without automatic home-data access |
| Catalog curator | Shared product catalog, aliases, packs, barcodes, and icons only |
| Catalog reviewer | Review and moderation queues only |
| Support operator | No home access unless the user grants a time-limited, audited support session |

Platform and catalog roles must not imply access to home stock, prices,
receipts, lists, AI credentials, or private media.

Implement:

- email verification
- password reset
- device-session listing and revocation
- optional multi-factor authentication
- rate limiting and lockout controls
- secure web cookie sessions with CSRF protection
- revocable per-device tokens for native clients
- membership invitations with expiration and single use
- ownership transfer safeguards
- audit events for role, invitation, ownership, export, and deletion changes

Authorization must be server-side. Add automated horizontal, vertical, and
cross-home authorization regression tests for every protected resource.

## 11. Shared global catalog and private home data

The shared product catalog is global. Stock, quantities, prices, receipts,
lists, preferences, and usage are home-private.

### 11.1 Global data

Global catalog data may include:

- canonical product families
- product variants
- pack sizes
- units and base-unit conversions
- brands where identity or matching requires them
- barcodes and GTINs
- sanitized product aliases
- categories
- generic product and category icons
- catalog revision and merge history
- approved product-identity rules

### 11.2 Home-private data

Home-private data includes:

- stock quantities
- storage locations
- stock counts and adjustments
- purchase quantities and prices
- receipt headers and lines
- raw receipt descriptions until approved for publication
- shopping lists
- consumption estimates
- preferred products and packs
- private notes
- home-produced or garden stock
- AI provider settings and credentials
- local media references

### 11.3 Catalog growth and moderation

When a user scans or enters an unknown product:

1. Make it usable immediately in that home as a private proposed product.
2. Search exact global barcodes and aliases.
3. Search normalized global product and pack identities.
4. Suggest possible matches.
5. Let the user choose an existing product, add a pack, create a private
   product, or leave it unresolved.
6. Create a sanitized catalog proposal without price, quantity, home identity,
   image, receipt number, or private notes.
7. Publish it globally only after moderation or a sufficiently safe,
   explicitly designed automated policy.

Catalog curators require a responsive administrative workbench for:

- missing icons
- duplicate candidates
- unresolved aliases
- barcode conflicts
- new product proposals
- pack and unit normalization
- category review
- merge previews
- reversible merges
- complete audit history

A merge must re-link every affected record to the surviving canonical identity.
It must not delete a duplicate and orphan its history.

## 12. Product identity and matching rules

Preserve the governing principle:

> One real product has one permanent canonical identity. Receipt, packet, and
> everyday wording become hidden aliases. Pack sizes and meaningful variants
> remain distinct.

Matching order:

1. exact barcode or GTIN
2. exact canonical product and pack
3. exact approved alias
4. normalized match after punctuation, spacing, hyphen, and singular/plural
   cleanup
5. deterministic candidate scoring using brand, variant, unit, and pack
6. optional AI-assisted candidate suggestion
7. human decision

AI must never silently merge catalog records.

Retain all 19 current identity rules, including:

- whole mushrooms versus pieces and stems
- elbow versus straight macaroni
- Bokomo versus Bakpro vetkoek flour
- tea bags versus loose leaf, tea type, and flavour
- All Gold versus generic tomato sauce
- stain-remover brand and powder/liquid form
- thin versus thick bleach
- creamstyle versus whole-kernel canned corn
- mild, hot, and extra-hot chakalaka
- basmati versus other rice
- brown, dark-brown, and light-brown sugar
- instant-maize-porridge flavour
- ground, instant, and bean coffee plus meaningful brand/range
- Oros flavour
- Candi Soda flavour
- automatic versus handwash washing powder
- crawling, flying, and multi-insect spray
- jelly flavour
- long-life, fresh, and non-dairy cream

Stock quantities must not combine incompatible packs without an explicit
conversion. Store original values and normalized base-unit values separately.
Never destroy the original pack text.

## 13. Core relational model

Use UUIDv7 or another globally unique, sortable identifier strategy consistently
across client and server. Client-created IDs must survive synchronization.

The final schema may refine names, but it must cover these concepts.

### 13.1 Identity and tenancy

- `users`
- `user_profiles`
- `devices`
- `homes`
- `home_memberships`
- `home_invitations`
- `auth_sessions` or framework-equivalent token records
- `support_access_grants`

### 13.2 Global catalog

- `categories`
- `units`
- `products`
- `product_variants`
- `product_packs`
- `product_aliases`
- `product_barcodes`
- `product_identity_rules`
- `catalog_icons`
- `catalog_proposals`
- `catalog_revisions`
- `catalog_merge_events`

### 13.3 Home inventory

- `home_locations`
- `home_products`
- `stock_count_sessions`
- `stock_count_lines`
- `stock_movements`
- `inventory_balances`
- `stock_threshold_preferences`

`inventory_balances` is a rebuildable projection. `stock_movements` and closed
count sessions are the auditable source of truth.

Purchases create stock-in movements only after line approval. Physical counts
create reconciliation adjustments. Manual corrections require a reason.
Deletes are controlled reversals or tombstones, not history erasure.

### 13.4 Purchases and pricing

- `stores`
- `receipts`
- `receipt_lines`
- `receipt_line_matches`
- `price_observations`
- `discounts`
- `tax_observations`
- `ai_extraction_runs`

Preserve raw printed descriptions and the approved canonical link. Price
history is private to the home unless a future, separately consented,
anonymized price-sharing feature is designed.

### 13.5 Lists and intelligence

- `shopping_lists`
- `shopping_list_lines`
- `shopping_suggestion_runs`
- `suggestion_explanations`
- `user_suggestion_feedback`
- later: `recipes`, `recipe_ingredients`, `menu_plans`

Keep Nextcloud Cookbook as the present recipe source of truth until recipe
integration is deliberately designed.

### 13.6 Synchronization and audit

- `client_operations`
- `change_log`
- `sync_cursors`
- `record_tombstones`
- `audit_events`
- `outbox_events`

Private operational and audit tables require `home_id` where applicable,
appropriate compound indexes, and database constraints that make accidental
cross-home joins difficult.

## 14. Offline-first client and synchronization

Flutter repositories are the application source of truth and combine local and
remote data sources. UI code must not call HTTP or SQLite directly.

### 14.1 Local-first write path

1. Validate the action locally.
2. Write the domain change and a client operation to local SQLite in one
   transaction.
3. Update the UI immediately from the local database.
4. Synchronize when connectivity is available.
5. Mark the operation acknowledged only after a server response.
6. Pull server changes using a durable cursor.

Every mutation must include:

- client operation ID
- device ID
- authenticated user
- home ID derived and verified by the server
- entity ID
- base revision or expected version
- operation type
- client timestamp for diagnostics
- payload schema version

The server must:

- make retries idempotent
- persist accepted changes transactionally
- return per-operation success, conflict, validation error, authorization
  failure, or retryable failure
- emit a monotonically ordered home change cursor
- retain tombstones long enough for all supported offline windows

### 14.2 Conflict rules

Do not use one global last-write-wins policy.

- Membership and role changes are server-authoritative and unavailable as
  offline grants.
- Closed stock counts are append-only facts.
- Concurrent count sessions for the same product and location require an
  explicit reconciliation workflow.
- Shopping-list check state may use revision-based last accepted update.
- Notes may use optimistic locking and user-visible conflict resolution.
- Catalog edits require revision checks and moderation.
- Product merges are server-only audited commands.

### 14.3 Sync resilience tests

Test:

- hours or days offline
- duplicate requests
- out-of-order requests
- network loss during push
- process death after local commit
- server commit followed by lost response
- token expiry during sync
- revoked membership
- concurrent edits from two devices
- schema upgrade with pending operations
- clock skew
- tombstone replay
- MySQL and MariaDB failover/restart

Background sync is best effort because mobile operating systems restrict
background work. Always sync on app start, resume, home switch, manual refresh,
and after a successful foreground mutation when connectivity permits.

## 15. Queue and scalability model

Define an application-owned `AsyncMessageBus` or equivalent port. Use Enqueue
with its Redis transport as the initial adapter and Redis Open Source or Valkey
as the broker. No domain or application class may depend directly on Enqueue,
the Redis extension, Predis, Redis command names, or broker-specific payloads.

Run workers from the backend repository as separate long-running CLI processes
using the same immutable application build as the API. Use `laminas-cli` for
worker, retry, dead-letter, import, reconciliation, cleanup, and scheduled-job
commands. Use the operating system, container orchestrator, or a small
open-source process supervisor to keep workers alive. Use cron or an equivalent
open-source scheduler to invoke idempotent scheduled commands; do not create a
second scheduling framework inside the domain.

Do not place every database query behind a message queue.

### 15.1 Synchronous operations

Reads and user-facing commands that require immediate confirmation use normal
HTTPS request/response and database transactions.

When the database is unavailable:

- the API returns a classified retryable error
- the Flutter client leaves the operation in its durable local outbox
- retry uses exponential backoff with jitter
- the UI shows `Saved on this device - waiting to sync`

This is safer than acknowledging a write merely because a message was placed in
a queue.

### 15.2 Asynchronous operations

Use queues for:

- catalog proposal moderation preparation
- emails and home invitations
- catalog icon processing
- imports and reconciliation reports
- analytics and inventory projections
- scheduled suggestion generation
- exports
- optional server-proxied AI work
- cleanup and retention jobs
- webhook delivery
- large audit-safe merges

Use:

- named queues by priority and workload
- a versioned message envelope with message ID, message type, schema version,
  correlation ID, causation ID, creation time, and minimum tenant context
- idempotent job IDs
- bounded retries
- exponential backoff
- an explicit dead-letter queue and persistent failed-job review record
- timeouts
- payload size limits
- queue lag alerts
- job correlation IDs
- tenant-aware rate limits
- graceful worker shutdown and visibility-timeout/redelivery testing
- minimal message payloads that reference authoritative records rather than
  copying private receipt, credential, or media content into the broker

Use a transactional outbox when a database commit must cause a background job.
Persist the outbox record in the same Doctrine transaction as the domain
change. A dispatcher publishes it and records successful dispatch. Consumers
must remain idempotent because delivery is at least once. Do not create a race
between a successful database transaction and a failed queue publish.

Provide an operational queue view or metrics dashboard showing queue depth,
age of oldest message, processing rate, failure rate, retries, dead letters,
worker heartbeats, and per-workload latency. No framework-specific monitoring
console is assumed. The monitoring surface must not expose private payloads.

## 16. AI-provider architecture and privacy truth

The application must support multiple vision-capable AI providers through a
versioned provider-adapter interface.

First-class provider modes:

1. OpenAI, presented as the prominent and easiest cloud option.
2. Generic OpenAI-compatible HTTPS provider.
3. Ollama or another self-hosted vision endpoint.
4. A future on-device model adapter.

No AI-dependent feature is available until the user configures a capable
provider or the deployment operator explicitly supplies a permitted shared
provider. The default business model must not silently charge the application
owner for users' AI work.

### 16.1 Non-negotiable privacy distinction

Never claim that an image "does not leave the device" when a cloud AI provider
is used.

Use precise language:

- `Not stored on Providentia servers` means the application server does
  not persist the image.
- `Sent directly to your selected AI provider` means the image leaves the
  device and is processed by that provider.
- `Strict local mode` means the image is sent only to an on-device or
  user-controlled local/LAN model and does not go to a cloud provider.

If strict local privacy is selected, disable cloud providers for that scan.

### 16.2 Credential modes

OpenAI explicitly advises against deploying API keys in browsers or mobile
applications. Implement and document three modes rather than hiding the
trade-off:

| Mode | Credential location | Image path | Use |
|---|---|---|---|
| Encrypted server proxy | Envelope-encrypted server vault | Device -> application proxy -> provider, streamed and not persisted | Recommended cloud mode, especially for web |
| Local/self-hosted direct | OS credential vault or no credential | Device -> local or user-controlled endpoint | Strict local privacy |
| Advanced native direct BYOK | OS credential vault on native device | Device -> cloud provider | Opt-in advanced mode with explicit key-exposure warning; not the web default |

Never store provider credentials in Flutter source, browser local storage,
analytics, crash logs, ordinary database columns, screenshots, or support
exports.

For web clients, do not persist a provider key in IndexedDB or local storage.
Use the encrypted proxy or a deliberately designed local connector.

Server-side BYOK credentials must use envelope encryption with a deployment key
outside the database, per-user isolation, key rotation, deletion, access audit,
and no plaintext display after entry.

### 16.3 Media handling

By default:

- original receipt and stock images remain local to the originating device
- an outbound working copy strips unnecessary EXIF metadata
- the app shows a preview and obtains explicit confirmation before transmission
- the selected provider and privacy mode are visible
- the backend stores only validated structured results, provider/model
  metadata, timestamps, confidence, and user corrections
- the UI clearly states when the original exists only on one device
- switching devices restores structured scan history, not the original image

An optional encrypted image-backup feature may be added later, but it must be
off by default and require explicit consent and retention settings.

### 16.4 Sensitive or unrelated media

The handover contains a concrete warning: the four files inside the directory
named `Receipt photos` appear to be medicine-information leaflet pages rather
than grocery receipts. The grocery receipts are located among the stock-control
images.

Therefore:

- never classify media from a folder or filename alone
- quarantine all handover media before import
- require visual or deterministic classification
- do not send the four medicine-related images to any AI provider
- do not import them as receipts
- do not retain them in production fixtures
- report their filenames privately for user confirmation

Development and automated tests must use synthetic or redacted image fixtures,
not the user's private photos.

### 16.5 AI extraction contract

Every provider adapter must return the same versioned application schema.
Validate it before displaying or storing it.

Receipt extraction must support:

- document classification
- purchase date
- store
- receipt number
- currency
- product line raw text
- brand
- product or family
- variant
- pack size
- quantity
- unit price
- line total
- discounts
- tax or VAT data when present
- notes
- per-field and per-line confidence
- warnings and unresolved values

Stock-photo extraction must support:

- candidate product
- candidate pack
- visible quantity or range
- confidence
- bounding region when available
- ambiguity and occlusion warnings

Use strict JSON Schema or equivalent structured output when the provider
supports it. Handle refusals, truncation, invalid output, timeouts, and models
without structured-output support.

AI output is a proposal, never a committed stock or purchase transaction.
Require a human confirmation screen. The confirmation creates catalog matches,
receipt lines, price observations, and stock movements through normal domain
commands.

Record provider, model, prompt-template version, schema version, processing
time, token or cost metadata when available, and the user's corrections. Do not
store hidden chain-of-thought.

## 17. Receipt workflow

Implement:

1. Start a receipt scan locally.
2. Select or photograph one or more pages.
3. Preview and rotate/crop locally.
4. Show privacy mode and selected provider.
5. Obtain explicit transmission consent.
6. Extract a structured result.
7. Present header and line review.
8. Match exact aliases first.
9. Present candidate canonical products and packs.
10. Allow:
    - assign to an existing product and pack
    - add a pack to an existing product
    - create a private home product
    - submit a sanitized catalog proposal
    - leave unresolved
11. Remember approved aliases according to their private or global scope.
12. Commit approved receipt lines.
13. Create idempotent stock-in movements once.
14. Add private price history.
15. Update projections and future recommendation inputs.

Do not create stock movements from unapproved AI output. Do not double-add
stock when a receipt is reprocessed or synchronized twice.

## 18. Stock-photo counting workflow

Implement:

1. Start a count session for a home and location.
2. Select a shelf, pantry, fridge, freezer, or household-supply photo.
3. Keep the original local by default.
4. Optionally request local or cloud vision suggestions under the selected
   privacy mode.
5. Keep the photo visible during the count.
6. Show suggested but unconfirmed products first.
7. Search the complete global and private item master.
8. Confirm, correct, or add each product and quantity.
9. Move confirmed lines below outstanding lines.
10. Close the count session explicitly.
11. Compare observed quantities with projected quantities.
12. Create auditable count-adjustment movements.
13. Show variance and unresolved items.

A count session may contain more than one photo. A product may appear in more
than one photo, so the closing workflow must detect possible double counting.

## 19. Intelligent shopping suggestions

Replace the fixed threshold with an explainable recommendation service.

Start with deterministic statistics before introducing opaque machine learning.
For each home, product, and pack, estimate:

- observed consumption between reliable counts
- purchase cadence
- current projected stock
- next expected shopping date
- supplier lead time when known
- minimum reserve or safety stock
- preferred pack sizes
- pack conversion
- seasonal patterns only when enough history exists
- active menu-plan demand later
- home-produced or garden stock
- user overrides

Suggested quantity should be derived from:

`expected demand until next replenishment + safety stock - usable stock`

Then map the need to available pack sizes and explain the result in ordinary
language.

Every suggestion requires:

- confidence
- data coverage
- explanation
- editable quantity
- dismiss/snooze
- `always keep`, `never suggest`, and preferred-pack controls
- feedback capture

Never present a weak estimate as a fact. With insufficient history, say so and
fall back to a user-configured minimum.

Backtest suggestion formulas against historical periods. Report precision,
missed stock-outs, overbuying, and user override rates before promoting a new
model.

## 20. Public website and unauthenticated behavior

Build a fast, accessible, server-rendered public site that:

- explains the product
- demonstrates stock, receipt, photo count, lists, homes, offline work, and
  privacy modes
- provides platform downloads
- offers sign in and account creation
- includes privacy, terms, security, support, and data-deletion information
- has correct metadata, sitemap, robots, structured data, and social previews
- meets WCAG 2.2 AA

Implement this surface as the backend repository's Mezzio `PublicSite` module
with `laminas-view`. Use the same approved colours, typography, spacing,
iconography, and content rules as the Flutter design system through versioned
design tokens. Visual uniformity does not require using Flutter for
document-centric public pages.

The authenticated web app is Flutter. An unauthenticated visit to the
application entry point may redirect to or render the public marketing/sign-in
surface. A valid authenticated session proceeds to the Flutter app and the
last active home.

Prefer separate public and application hosts so public-content and
authenticated-session security boundaries remain clear, for example:

- public marketing host
- authenticated app host
- API host

The final hostnames are selected after the Providentia domains are approved.

## 21. Deployment profiles

Provide reproducible Docker Compose deployment with health checks, persistent
volumes, secrets, documented upgrades, backups, and restore tests.

### 21.1 Production external-database profile

Services:

- reverse proxy with TLS
- Mezzio API and server-rendered public web
- Mezzio/Laminas CLI queue workers
- cron or equivalent scheduler invoking idempotent Laminas CLI commands
- Redis Open Source or Valkey queue broker
- optional S3-compatible object storage for catalog assets
- external MySQL or MariaDB

The operator supplies:

- application URL values
- database host
- database port
- database name
- database username
- database password
- database TLS CA and verification mode where required
- Redis/Valkey secret and connection values
- application encryption key
- mail settings
- object-storage settings when enabled

Use secret files or the deployment platform's secret manager. Do not put
production secrets into committed Compose files.

### 21.2 Self-contained production profile

Provide an optional bundled MySQL or MariaDB service for small self-hosted
installations, with persistent volumes, health checks, non-root application
credentials, backups, and an upgrade path to an external database.

### 21.3 SQLite demonstration/test profile

When no server database is supplied, support a SQLite profile for local
demonstration and tests. Clearly label it non-clustered and not the high-volume
production mode.

### 21.4 Readiness and failure behavior

Compose must wait for dependency health, not merely container startup. The API
must expose liveness and readiness endpoints. Workers must stop gracefully and
finish or safely release jobs.

Document:

- initial installation
- environment validation
- migration
- administrator creation
- queue start and monitoring
- failed-message inspection, retry, and dead-letter procedures
- backup
- restore
- update
- rollback
- log inspection
- key rotation
- database migration from SQLite to MySQL/MariaDB
- scaling API and worker replicas

## 22. Security, privacy, and operations

Use OWASP API and multi-tenant guidance as the security baseline.

Required controls:

- TLS everywhere outside the trusted container network
- server-side object-level authorization
- strict home scoping
- validated OpenAPI requests
- parameterized database access
- CSRF protection for cookie-authenticated web requests
- secure headers and restrictive CORS
- rate limiting per user, home, IP, and AI provider
- secure password hashing
- MFA-ready authentication
- revocable device sessions
- encrypted secrets
- log redaction
- no images, keys, tokens, receipt content, or medical content in logs
- dependency scanning and lockfiles
- signed release artifacts
- software bill of materials
- audit logs for privileged operations
- tested backup and restore
- data export and deletion
- retention policies
- incident-response documentation

Platform administrators must not gain implicit access to home data. Any support
access must be user-granted, time-limited, narrowly scoped, visible, revocable,
and audited.

Use threat models for:

- cross-home IDOR
- malicious invitations
- stolen device tokens
- sync replay
- poisoned catalog proposals
- alias takeover
- queue payload tampering
- AI prompt injection from receipt text or labels
- malicious images and decompression bombs
- SSRF through custom AI base URLs
- credential exfiltration
- unsafe product-icon uploads
- backup exposure

## 23. Migration and cutover

### 23.1 Structured migration source

Use `03_data_exports` as the operational migration source. Use spreadsheets and
media as evidence, not as the primary import path.

Recommended import order:

1. categories and units
2. canonical products
3. variants and packs
4. aliases and identity rules
5. stores
6. historical purchases
7. recent purchases
8. opening stock-count session and stock lines
9. monthly summaries as validation only

Recalculate monthly summaries from source purchase lines where possible and
compare them with the 261 supplied summary rows.

### 23.2 PDF warning

`Shopping 2026.pdf` is a 586-page spreadsheet print export. The purchase data is
visible in the first nine pages; later pages contain print-range noise,
repeated placeholders, and `#DIV/0!` output. Do not use all 586 PDF pages as an
OCR source. Prefer the verified 452-line CSV export and use the PDF only for
lineage checks.

### 23.3 Device-local data recovery

The ZIP does not contain operational changes that exist only in a browser
profile under:

- `pantry-counts`
- `pantry-receipts`
- `pantry-stock-photos`
- `pantry-manual-list`
- `pantry-list-checks`

Before retiring the current PWA:

1. add or use a device-data export
2. export from every relevant browser profile
3. validate the export
4. import it into a staging home
5. reconcile it with the ZIP baseline
6. obtain user approval

Do not clear browser data or uninstall the old PWA before this is complete.

### 23.4 Import guarantees

The importer must be:

- idempotent
- resumable
- dry-run capable
- transaction-safe
- explicit about conflicts
- able to emit JSON and human-readable reports
- able to map every source row to its destination ID
- able to quarantine unresolved rows without data loss

Never silently deduplicate by name alone.

## 24. Testing and quality gates

### 24.1 Flutter

- Dart analysis with zero warnings
- unit tests for domain and repositories
- Drift migration tests
- widget tests
- golden tests for approved visual states
- accessibility tests
- navigation and deep-link tests
- camera/file-picker adapter tests
- sync integration tests
- Android, iOS, Windows, macOS, Linux, and web build jobs
- responsive tests at phone, tablet, laptop, and large desktop widths

### 24.2 Backend

- PHP static analysis at a strict level
- Laminas coding-standard, formatter, and lint checks
- architecture tests for layer direction, forbidden dependencies, and module
  cycles
- ServiceManager factory and configuration compilation tests
- unit tests
- module integration tests
- PSR-15 handler and API tests
- authentication, RBAC, and domain-policy tests
- tenant-isolation matrix
- MySQL, MariaDB, and SQLite integration suites
- Doctrine mapping validation
- Doctrine migration up/down, upgrade-path, and clean-install tests
- Enqueue Redis-adapter contract tests against Redis Open Source and Valkey
- queue retry, idempotency, redelivery, outbox, and dead-letter tests
- OpenAPI conformance
- rate-limit tests
- backup and restore smoke tests
- dependency-licence, SBOM, and prohibited-framework checks

### 24.3 AI

- synthetic and redacted fixtures only
- provider contract tests
- invalid JSON and partial response tests
- schema-version tests
- low-confidence and ambiguous extraction tests
- medical/unrelated document rejection tests
- prompt-injection tests
- no automatic inventory mutation tests
- cost and timeout boundaries
- local Ollama integration test when enabled
- live provider tests opt-in only and excluded from ordinary CI

### 24.4 End-to-end acceptance scenarios

At minimum:

1. A new user registers, verifies their email, and creates a home.
2. The owner invites another user.
3. One user belongs to three homes and switches safely between them.
4. A member cannot read another home's data by changing an ID.
5. Two devices work offline and later synchronize without duplicate stock
   movements.
6. A receipt is extracted, reviewed, matched, and committed once.
7. An unknown product becomes private immediately and enters catalog review
   without leaking price or home data.
8. A curator updates a missing icon without access to any home.
9. A stock-photo count closes and creates an auditable variance adjustment.
10. A weak shopping suggestion explains its limited evidence.
11. The app works with no AI provider, with OpenAI, and with local Ollama.
12. The SQLite server profile starts without external database credentials.
13. The MySQL and MariaDB profiles both pass the same domain tests.
14. The system restores successfully from backup.

No phase is complete merely because it builds.

## 25. Phased delivery plan

### Phase 0 - Evidence, decisions, and migration safety

Deliver:

- verified handover audit
- current feature-parity matrix
- data dictionary
- media classification and quarantine report
- architecture decision records
- threat model
- target schema draft
- OpenAPI domain outline
- platform support matrix
- Providentia naming decision record and public-launch due-diligence process
- device-local export and cutover plan
- prioritized questions requiring user decisions

Do not implement the full rewrite in this phase.

### Phase 1 - Repositories and production foundations

Deliver:

- separate Flutter and Mezzio/Laminas backend repositories
- modular backend skeleton with Domain, Application, Infrastructure, and HTTP
  boundaries
- explicit ServiceManager factories and module configuration providers
- Doctrine ORM, DBAL, and Migrations integration proof
- server-rendered `PublicSite` module using `laminas-view`
- contract publication and pinned Flutter client-generation workflow
- Redis/Valkey Enqueue adapter, worker CLI, transactional-outbox proof, and
  queue metrics
- pinned toolchains
- local development commands
- Compose SQLite, MySQL, and MariaDB profiles
- Compose Redis Open Source and Valkey-compatible queue profiles
- CI matrix
- code quality and test baselines
- health endpoints
- generated OpenAPI client proof

### Phase 2 - Identity, homes, and catalog foundation

Deliver:

- users and authentication
- homes, memberships, invitations, and switching
- platform and catalog roles
- global catalog schema
- tenant authorization policies
- catalog seed import
- complete cross-home isolation tests

### Phase 3 - Flutter design system and offline data layer

Deliver:

- approved responsive Fresh Market design system
- adaptive navigation
- Drift schema and migrations
- repository layer
- local outbox
- sync status UI
- web SQLite/WASM persistence
- feature shell with no direct HTTP from widgets

### Phase 4 - Synchronization

Deliver:

- push/pull sync protocol
- cursors, tombstones, revisions, idempotency
- conflict handling
- multi-device tests
- retry and offline status
- operational metrics

### Phase 5 - Existing feature parity

Deliver:

- dashboard
- stock and item-master views
- search and category filtering
- manual counting
- stock count sessions
- purchase history
- manual and suggested lists
- import of all baseline data
- parity report against the current PWA

### Phase 6 - Receipt and stock-photo intelligence

Deliver:

- provider settings
- OpenAI adapter
- OpenAI-compatible adapter
- Ollama adapter
- privacy modes
- secure credential handling
- structured extraction schemas
- receipt review and approval
- stock-photo proposals and count workflow
- AI contract and privacy tests

### Phase 7 - Global catalog administration

Deliver:

- product proposal workflow
- curator and reviewer workbench
- duplicate and alias review
- icon management
- reversible merge operations
- catalog audit history

### Phase 8 - Intelligent suggestions and reporting

Deliver:

- movement-based balances
- consumption estimates
- explainable shopping suggestions
- price and pack comparison
- confidence and feedback
- household reports
- backtesting and evaluation

### Phase 9 - Public launch surfaces and packaging

Deliver:

- selected name and brand application
- public marketing website
- platform installers and signing
- PWA hosting
- download and update documentation
- privacy and terms
- release and update workflows

### Phase 10 - Migration, hardening, and production cutover

Deliver:

- dry-run import
- source-to-destination reconciliation
- device-local import
- performance and load tests
- security review
- backup/restore rehearsal
- production deployment
- monitored cutover
- rollback plan
- final acceptance report

## 26. Working rules for every iteration

For every phase:

1. Inspect existing work before changing it.
2. State assumptions and unresolved decisions explicitly.
3. Do not replace user-approved behavior without explaining the reason.
4. Use complete implementations, not placeholder handlers or fake success
   responses.
5. Keep changes scoped to the active phase.
6. Maintain migrations, tests, documentation, and deployment files with the
   code.
7. Preserve unrelated work and source evidence.
8. Run the relevant full validation suite.
9. Provide exact commands used and results.
10. Provide a changed-file list.
11. Provide screenshots for meaningful UI changes.
12. Update the parity matrix and risk register.
13. Stop and request a decision when a missing choice would materially alter
    security, data ownership, compatibility, or architecture.

Do not claim:

- that all operating-system versions are supported
- that cloud-processed images remain on the device
- that a queued message is the same as a committed database transaction
- that Flutter clients can safely receive database credentials
- that SQLite-only tests prove MySQL/MariaDB compatibility
- that AI extraction is accurate without human review
- that a catalog administrator may browse private home data

## 27. Required response format at the end of each phase

Return:

1. Outcome
2. Evidence inspected
3. Decisions made
4. Changes implemented
5. Database and API changes
6. Privacy and security impact
7. Tests and exact results
8. Platform results
9. Migration reconciliation
10. Known limitations
11. Decisions required from the user
12. Recommended next phase

## 28. First assignment

Begin with Phase 0 only.

Your first response must:

1. Confirm the ZIP checksum verification.
2. Provide a complete current-feature parity matrix.
3. Provide a source-data and media audit, including the mislabeled medical
   leaflet files and the 586-page PDF print-range problem.
4. Produce the proposed target data model with global-versus-home ownership for
   every entity.
5. Produce architecture decision records for Flutter, the two-repository
   split, Mezzio plus Laminas Components, the modular monolith, explicit
   ServiceManager factories, Doctrine ORM/DBAL/Migrations,
   MySQL/MariaDB/SQLite, Drift, Enqueue with Redis/Valkey, public-web
   separation, AI privacy, and tenant isolation.
6. Produce the API resource and synchronization outline.
7. Produce the migration and reconciliation specification.
8. Produce the security threat model and authorization-test matrix.
9. Produce the supported-platform and packaging matrix.
10. Identify only the user decisions that genuinely block Phase 1.

Do not scaffold or rewrite the application until the Phase 0 package has been
reviewed and approved.

### 28.1 Known decisions to surface without guessing

At minimum, determine whether each of these is a Phase 1 blocker and obtain an
explicit answer when it is:

- Confirm Android and iOS as first-class release targets in addition to the
  explicitly requested Windows, macOS, Linux, and web targets.
- Record `Providentia` as the owner-selected official project and product name.
  Domain, app-store, and trademark-risk due diligence remains required before
  public launch but does not reopen the naming decision.
- Decide whether cloud images may transit the application backend without being
  persisted, or whether all cloud calls must go directly from native devices.
- Select the default AI privacy mode and whether advanced native direct BYOK is
  permitted.
- Decide whether optional encrypted private-media backup belongs in the first
  release or a later release.
- Confirm the first authentication methods beyond email and password, such as
  passkeys or selected social identity providers.
- Confirm whether unknown home products may automatically create sanitized
  global catalog proposals or require an explicit per-item opt-in.
- Confirm the first-release locales, currencies, units, time zones, and
  languages. Preserve `en-NA` and NAD behavior as the migration baseline, not
  as a permanent global assumption.
- **Resolved 30 July 2026:** MySQL is the preferred production database;
  MariaDB and SQLite retain their recorded compatibility/test roles.
- **Resolved 30 July 2026:** Redis Open Source is the preferred production
  broker; Valkey remains a tested compatible profile. The project-owned queue
  port and Enqueue adapter remain decided.
- **Resolved 26 August 2026:** the project is proprietary under
  `LicenseRef-Proprietary`. The root [LICENSE](../../LICENSE) grants no licence
  except as expressly authorised in writing. Pricing, free-tier, and operator
  responsibilities remain required before public commercial claims.

---

## 29. Official technical references

Use current official documentation at implementation time. The following
references support the architecture decisions in this prompt:

- Flutter supported platforms:
  https://docs.flutter.dev/reference/supported-platforms
- Flutter offline-first architecture:
  https://docs.flutter.dev/app-architecture/design-patterns/offline-first
- Flutter web suitability and SEO limitations:
  https://docs.flutter.dev/platform-integration/web/faq
- Drift platform support:
  https://drift.simonbinder.eu/platforms/
- Mezzio:
  https://docs.mezzio.dev/mezzio/v3/
- Mezzio modular applications:
  https://docs.mezzio.dev/mezzio/v3/features/modular-applications/
- Mezzio with Laminas ServiceManager:
  https://docs.mezzio.dev/mezzio/v3/features/container/laminas-servicemanager/
- Mezzio authentication:
  https://docs.mezzio.dev/mezzio-authentication/
- Mezzio session authentication:
  https://docs.mezzio.dev/mezzio-authentication-session/
- Mezzio authorization:
  https://docs.mezzio.dev/mezzio-authorization/
- Mezzio sessions:
  https://docs.mezzio.dev/mezzio-session/
- Mezzio problem details:
  https://docs.mezzio.dev/mezzio-problem-details/
- Mezzio with `laminas-view`:
  https://docs.mezzio.dev/mezzio/v3/features/template/laminas-view/
- Laminas Components:
  https://docs.laminas.dev/components/
- Laminas ServiceManager:
  https://docs.laminas.dev/laminas-servicemanager/
- Laminas InputFilter:
  https://docs.laminas.dev/laminas-inputfilter/
- Laminas RBAC:
  https://docs.laminas.dev/laminas-permissions-rbac/
- Laminas CLI:
  https://docs.laminas.dev/laminas-cli/
- Doctrine ORM:
  https://www.doctrine-project.org/projects/doctrine-orm/en/current/
- Doctrine DBAL:
  https://www.doctrine-project.org/projects/doctrine-dbal/en/current/
- Doctrine Migrations:
  https://www.doctrine-project.org/projects/doctrine-migrations/en/current/
- Enqueue:
  https://github.com/php-enqueue/enqueue-dev
- Enqueue Redis transport:
  https://github.com/php-enqueue/enqueue-dev/blob/master/docs/transport/redis.md
- Redis Open Source licensing and release information:
  https://redis.io/docs/latest/operate/oss_and_stack/stack-with-enterprise/release-notes/redisce/redisos-8.0-release-notes/
- Valkey:
  https://valkey.io/
- Valkey Redis migration and protocol compatibility:
  https://valkey.io/topics/migration/
- OpenAI API key safety:
  https://help.openai.com/en/articles/5112595-best-practices-for-api-key-safety
- OpenAI image input:
  https://developers.openai.com/api/docs/guides/images-vision
- OpenAI structured outputs:
  https://developers.openai.com/api/docs/guides/structured-outputs
- Ollama vision:
  https://docs.ollama.com/capabilities/vision
- Ollama OpenAI compatibility:
  https://docs.ollama.com/api/openai-compatibility
- Docker Compose startup and readiness:
  https://docs.docker.com/compose/how-tos/startup-order/
- OWASP multi-tenant security:
  https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html
- OWASP authorization:
  https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html
- OWASP API Security:
  https://owasp.org/www-project-api-security/

# END MASTER PROMPT
