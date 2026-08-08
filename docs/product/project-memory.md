# Project memory and controlling decisions

## Owner decision — 29 July 2026

- `Providentia` is the official project name, product name, package base,
  namespace base, contract prefix, deployment prefix, and documentation name.
- `StockHome` identifies only the former React/TypeScript prototype and its
  historical evidence. It is not a production identifier.
- The canonical repositories are:
  - `providentia-systems/backend`
  - `providentia-systems/client`
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

- Passwordless, single-use email magic links are the default authentication
  workflow. Password endpoints may remain disabled migration compatibility
  surfaces; household invitations and ownership transfer require explicit
  recipient action.
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
