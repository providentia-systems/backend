# Phase 9 — launch readiness

Phase 9 supplies backend launch surfaces without embedding Flutter packaging,
store publication, public domains, or operator pricing decisions in the API.

## Delivery scope

- separately consent-controlled product-identity, public product-image, and
  store-price contributions with attribution-free moderation;
- asynchronous home export and account/home erasure workflows;
- operator-configured plans, entitlements, quotas, promotions, and overrides;
- provider-neutral PayPal and hosted-card checkout boundaries;
- passwordless authentication, durable SMTP notifications, invitation
  revocation, and accepted ownership transfer;
- API contracts suitable for Android, iOS, web, Windows, macOS, and Linux
  clients; and
- explicit privacy, retention, commercial, and deployment configuration.

Flutter installers, signing, PWA/service-worker behavior, app-store listings,
screens, localization resources, and subscription-management UI belong to the
Flutter repository. The Laminas backend must remain platform neutral.

## Acceptance boundary

Phase 9 implementation is complete only when its migrations apply on MySQL and
MariaDB, authorization and tenant-isolation tests pass, every route is present
in the locked OpenAPI contract, provider webhooks are idempotent, and disabled
commercial features cannot create a charge.

Public pricing, payment production credentials, legal documents, domains, and
store metadata are operator/distribution inputs and are not fabricated by this
repository.

An approved catalog contribution is published through bounded
`GET /api/v1/catalog-contributions`. The persistence query selects only
approved, supported types and the application layer applies a second
type-specific field allowlist. Public output contains only
`contributionType`, the sanitized fact payload, and `publishedAt`; it never
contains the internal contribution ID, home, user, consent receipt, source
fingerprint, reviewer, quantity, private note, receipt, or private-media
attribution. Canonical product merges and catalog identity curation continue
to use the separate Phase 7 governance workflow.

Switching off any sharing category atomically withdraws both pending and
previously approved facts of that category for the household. Because the
global projection reads approved rows only, consent withdrawal also
unpublishes those facts without deleting the private moderation record or
affecting either of the other consent categories. Re-enabling a category does
not republish withdrawn facts; publication requires a new submission and a new
moderator approval.
