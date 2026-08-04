# Phase 9 — launch readiness

Phase 9 supplies backend launch surfaces without embedding Flutter packaging,
store publication, public domains, or operator pricing decisions in the API.

## Delivery scope

- consent-controlled global catalog and price contributions;
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
