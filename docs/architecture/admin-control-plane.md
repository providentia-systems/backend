# Linux administrator control plane

Providentia has three separately released repositories. The backend owns domain
rules, persistence, authorization, deployment and the authoritative OpenAPI
contract. `providentia-systems/client` is the homeowner Flutter application for
the supported mobile and desktop platforms. `providentia-systems/admin` is a
separate Flutter application initially packaged only for Linux desktop. The
backend provides no public site, authenticated browser login, household GUI,
or operator GUI. Its only HTML exception is the unauthenticated browser
approval/denial ceremony for emailed login links; that browser never receives
a session. Owner-only recovery or bootstrap work remains a narrow CLI concern.

Both clients use the same login-link and bearer/session protocol, but they are
different security principals at the installation boundary. The Admin package
uses its own application identifier, installation UUID, device/session, keyring
entry and data directory. It must never import or reuse homeowner tokens,
offline databases or keyring material. A platform role grants no implicit home
membership and no household-content endpoint.

## Operator account boundary

The Linux Admin account view uses only these operations:

- `GET /api/v1/admin/accounts`
- `GET /api/v1/admin/accounts/{userId}`
- `PATCH /api/v1/admin/accounts/{userId}/status`
- `PUT /api/v1/admin/accounts/{userId}/roles/{role}`
- `DELETE /api/v1/admin/accounts/{userId}/roles/{role}`

They require `platform_administrator`. Their allowlist is identity metadata,
account status/revision/timestamps, active-session and home counts, platform
roles, and home name plus membership role/status. A home may include only a
small subscription status/plan/cycle/period summary. Stock, products, counts,
receipts, purchases, locations, prices, notes, reports, AI media, credentials
and provider references are excluded. Status and role mutations are audited,
revision-bound and serialized; suspension/closure revokes sessions, closure is
terminal, and the last active administrator cannot be removed or disabled.
Actual grants, reactivations, and revocations advance the same account revision
regardless of entry point; an idempotent request leaves both revision and audit
history unchanged.

`POST /api/v1/platform/administrators` is the invitation-by-email surface. It
creates a pending invitation for an address without a verified account and
activates the administrator role when that account is verified. The account
role operation above addresses an existing account by UUID and expected
account revision. These are two workflows over the same canonical
`user_platform_roles` state, not parallel role stores; accepting, granting,
reactivating, or revoking through either workflow invalidates stale account
snapshots used by the other.

After the intended owner has an active verified account, the narrow audited
owner path is:

```bash
php bin/providentia platform:role \
  --email owner@example.test \
  --role platform_administrator
```

`PLATFORM_BOOTSTRAP_ADMIN_EMAILS` remains a deployment bootstrap alternative:
a matching verified account receives the administrator grant during a
successful login-link approval. Subsequent delegation should use Admin. The
owner CLI does not create pending invitations and the account control-plane
role operation does not resolve accounts by email.

## Global catalog and contribution flow

Published categories are selected from the attribution-free paged
`GET /api/v1/catalog/categories` projection (`id`, `canonicalName`, `revision`).
Category creation is a `category` proposal with sanitized `{canonicalName}`;
product creation is a `product` proposal with sanitized
`{canonicalName, brand, categoryId}`. Both use the existing proposal workbench
and reviewer decision, which is the only path that writes canonical category
or product rows.

A reviewer may first approve a consent-bound `product_identity` contribution.
A curator then uses the idempotent, revision-bound
`PUT /api/v1/catalog-contributions/{contributionId}/proposal` with an explicit
published category ID. The durable link creates exactly one ordinary product
proposal; it neither guesses from the submitted category label nor publishes a
canonical product. If the household withdraws consent before proposal review,
the contribution revision/status changes and publication is blocked. Withdrawal
also removes the contribution from the public fact feed and does not revive on
later opt-in.

Approved review-queue rows expose an optional privacy-safe `proposalLink`
containing the linked contribution revision, proposal ID/status, selected
published category ID/name and link time. Admin therefore reconstructs linked
versus unlinked state after restart without retaining transient local state.
An exact `PUT` replay returns that same link. A mismatched revision or category
returns `409`; Admin then reloads the approved queue to recover the canonical
link rather than guessing or creating another publication path.

After a proposal has already been reviewed and published, the canonical record
is an attribution-free moderated public fact. Later consent withdrawal does not
retroactively delete that canonical record or its non-household audit/revision
history; erasure must not recreate a contributor/home link in operator output.

## Billing posture and release order

The stabilization default is `BILLING_ENABLED=0`. The client is not blocked by
subscription state, checkout providers remain disabled, and the operator
subscription summary is informational only. PayPal, cards, price enforcement,
discount tokens and paid activation are future slices and must not be implied
by this control plane.

Contract changes ship in one direction: implement backend runtime, migration,
OpenAPI, lock and tests; publish the backend contract; copy the exact contract
and lock bytes into both client repositories and regenerate; then release
compatible clients. Neither Flutter repository owns a divergent handwritten
API model.
