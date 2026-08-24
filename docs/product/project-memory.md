# Project memory and controlling decisions

## Owner decision — 29 July 2026

- `Providentia` is the official project name, product name, package base,
  namespace base, contract prefix, deployment prefix, and documentation name.
- `StockHome` identifies only the former React/TypeScript prototype and its
  historical evidence. It is not a production identifier.
- The canonical repositories are:
  - `providentia-systems/backend`
  - `providentia-systems/client`
  - `providentia-systems/admin`
- The backend remains Mezzio plus selectively chosen Laminas components,
  explicit Laminas ServiceManager factories, Doctrine ORM/DBAL/Migrations, and
  a project-owned asynchronous bus with an Enqueue Redis adapter.
- The verified Flutter Linux support range records Ubuntu 20.04 through 24.04
  LTS. Ubuntu 26.04 is not claimed.
- The project is proprietary. No distribution licence is granted or selected
  yet; licensing will be decided before public distribution.
- The permanent application/distribution identifier is
  `com.vastdevelopmentmethod.providentia`.
- MySQL is the preferred production database. MariaDB remains a tested
  compatibility profile and SQLite remains the demonstration/test profile.
- Redis Open Source is the preferred production queue broker. Valkey remains a
  tested Redis-protocol-compatible deployment option.

These decisions supersede older working names without changing the verified
data, workflow, privacy, or architectural evidence.

## Production baseline — 4 August 2026

- Email-only, single-use **login links** are the primary authentication
  workflow. The originating client owns the private poll token and PKCE
  verifier; an emailed link may be reviewed and deliberately approved in a
  browser on another device; the originating client then polls and exchanges
  the approval for its own session. Opening a link is never itself approval,
  and the browser never receives the client session.
- Starting a login-link request does not create an account. Deliberate approval
  creates and verifies the account idempotently and creates one editable
  `My home` with that person as `owner`; the originating client's successful
  exchange issues its session and selects that home. Existing people keep all
  existing memberships and receive no extra default home.
- Access credentials last about 15 minutes. Web inactivity is sliding 30 days;
  native Android, iOS, Windows, macOS, and Linux inactivity is sliding 60 days.
  Rotation, replay detection, logout, revocation, and backend policy remain
  authoritative. Password endpoints are development/migration compatibility
  surfaces only, never the production onboarding fallback.
- Household invitations and ownership transfer require explicit recipient
  action. A person's `owner`, `manager`, `member`, or `viewer` role is scoped to
  one home; platform roles are separate and never confer home access.
- The bootstrap platform-administrator email becomes an administrator only
  after login-link verification. Administrators may add pending email grants or
  revoke peers through authenticated, actor-audited, revisioned APIs, but
  cannot revoke the final active administrator.
- Household authorization is permission based, configurable per role, and
  rechecked by every server use case. A person may belong to multiple homes.
- All pantry mutations supported by the API must have typed, idempotent offline
  commands. The server owns conflict checks and applies offline commands through
  the same application services as online requests.
- The supported offline window and tombstone retention are deployment settings.
  Defaults are 90 and 120 days respectively; compaction must never cause a
  supported device to miss a deletion.
- Transactional email is durable, encrypted at rest, retried, and recoverable
  through the notification outbox. SMTP remains provider neutral and works with
  operator-managed services such as Mailcow.
- OpenAI is the primary supported AI provider. Anthropic Claude, Google Gemini,
  xAI, OpenAI-compatible/self-hosted Llama, and Ollama are supported provider
  families. Retryable failover and independent validation are policy driven;
  extraction results always require human review before inventory changes.
- Original media may be transient or retained at the household's choice.
  Retained private media is encrypted and quota controlled; infrastructure
  backups remain an operator-owned Restic concern rather than application
  backup code.
- MySQL is preferred, MariaDB is a supported production profile, and Redis or
  Valkey is used for queues. Deployments may use self-hosted or external managed
  services and must not embed a public domain.
- Product/catalog identity, image, and price sharing are separately consented.
  Household quantities and private attribution are never global contributions.
- Commercial plans, feature entitlements, quotas, promotions, PayPal, and
  hosted-card checkout are operator-configurable and disabled unless explicitly
  enabled. Public pricing and the final payment processor credentials remain
  deployment/business configuration.

Public domains, final pricing amounts, legal text, store listings, and release
signing identities remain distribution decisions. They do not change the
backend architecture or weaken its acceptance gates.

## Superseded Phase 6 implementation checkpoint — 30 July 2026

- `manual_only` is the fail-closed default for every home and deployment.
- The checkpoint did not persist receipt or stock-photo media. The approved
  production baseline now permits encrypted transient or retained media under
  explicit household policy and quota controls.
- Cloud extraction uses only the encrypted server proxy after deployment,
  home, and user consent gates are all satisfied.
- `local_direct` is a client-owned path to a user-controlled endpoint.
- Advanced native direct cloud BYOK remains disabled pending a native-client
  vault and warning design.

## Phase 8 intelligence decision — 30 July 2026

- `stock_movements` is factual truth; `inventory_balances` remains a
  rebuildable projection.
- Only confirmed lines from complete, explicitly reliable closed count
  sessions may estimate consumption.
- Recommendation arithmetic is deterministic fixed-point arithmetic. A
  confidence score is an evidence grade, not a probability.
- With insufficient count history, demand is zero and a suggestion may use
  only the home-configured minimum reserve.
- Historical evaluation uses only facts known at each cutoff. Later purchases,
  missed-stock-out counts, and overbuying counts are labelled as proxies.
- Private household prices are compared only within the same currency. No
  exchange-rate or cross-home price-sharing policy is implied.
- Owner and manager roles control replenishment policy; owner, manager, and
  member roles may generate and give feedback; viewers remain read-only.
- Seasonality, menu-plan demand, garden production, and learned models remain
  deferred until their evidence and product decisions are explicit.

## Superseded inventory-integration contract checkpoint — 11 August 2026

The architecture and privacy decisions in this checkpoint remain controlling.
Its two-repository/API-1.13.2 release identity is historical and is superseded
by the 24 August three-repository contract below.

- P0–P3 are cross-repository integration priorities, not renumbered historical
  product phases. Their authoritative delivery boundary is
  `docs/inventory-integration-roadmap.md` in both repositories.
- The backend OpenAPI artifact is the contract source for Flutter clients.
  Clients pin the exact SHA-256 bytes and regenerate their Dart clients;
  generated output is never hand edited.
- API 1.13.2 exhaustively documents non-disclosing home denials for AI and
  shopping operations and adds the revisioned receipt-line unresolved decision.
- Platform and catalog roles never grant implicit home access. Support-access
  tables do not authorize a request because no support-access service or route
  is exposed.
- Approved catalog contribution facts are globally readable only through a
  bounded type-specific allowlist. Moderator and public DTOs omit household,
  contributor, consent-receipt, source-fingerprint, and reviewer attribution;
  non-approved rows remain private.
- Backend capability and automated tests are not a production-acceptance
  claim. Client convergence, staging providers, backup/restore, distribution
  signing, payment sandboxes, and other operator rows remain evidence gates.
- Stock-count cancellation is a revisioned protocol-v2 terminal transition.
  It publishes `cancelled`, applies no observations, and creates no movement.
- Direct AI extraction uploads are request-transient and are not added to
  application media storage. The separate private-media path requires an
  explicit transient/retained choice and persists authenticated ciphertext,
  never plaintext media at rest.

## Three-repository stabilization contract — 24 August 2026

- Backend API `1.15.0` is the sole runtime and contract authority. The backend
  exposes HTTPS API, login-link browser approval pages and narrow audited CLI
  owner commands; it exposes no authenticated homeowner or operator GUI.
- `providentia-systems/client` is the homeowner Flutter application across its
  supported mobile/desktop targets. `providentia-systems/admin` is a separate
  Flutter security principal, initially packaged only for Linux desktop. It
  uses the same authentication protocol but a distinct application ID,
  installation/session, keyring entry, data directory and generated client.
- Billing enforcement, paid subscription checkout, PayPal/cards and discount
  tokens remain disabled. Stabilization is free-access; operator subscription
  summaries are informational and must not gate a household feature.
- Home-private categories and private products stay tenant scoped. Protocol-v2
  owns typed category create/update and home-product create/update commands;
  global `categoryId` and private `homeCategoryId` are never interchangeable.
- A separately consented product-identity contribution is attribution-free in
  moderator projections. Reviewer approval does not publish it. A curator
  selects an existing published category and creates exactly one durable,
  revision-bound ordinary product proposal; the existing proposal review is
  the only canonical publication path. Approved queue rows expose the optional
  privacy-safe proposal link so Admin can recover after restart.
- Consent withdrawal before proposal approval changes the source and blocks
  canonical publication. Once published, the moderated canonical record is an
  attribution-free public fact; later withdrawal removes the contribution feed
  item but does not retroactively delete canonical or audit history.
- Backend contract bytes and lock ship first. Both Flutter repositories copy
  those exact bytes, verify the same SHA-256 and regenerate before compatible
  client releases. Neither client owns handwritten divergent API shapes.
