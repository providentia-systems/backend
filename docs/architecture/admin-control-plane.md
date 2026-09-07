# Administrator control plane

The backend owns domain rules, persistence, authorization, deployment and the
canonical API 2.0.0 contract. The homeowner and administrator Flutter clients
remain separate applications, installations and protected credential stores.
Authentication is numeric email OTP entered in the requesting app. The backend
serves no login page or application management UI.

## Operator identity and delegation

Bootstrap the first owner before signing in:

```bash
php bin/providentia system:owner owner@example.test
```

The owner must still verify the emailed code. The CLI is idempotent for the same
address and refuses to replace an existing system owner. Other administrator
sign-ins create pending requests. An approved administrator belongs to one
administrator group. The protected owner group carries every administrator
permission; ordinary groups explicitly list their permitted operations.

`GET /api/v1/admin/administrators` requires administrator-list access. Reviewing
`POST /api/v1/admin/administrators/{userId}/review` requires approval authority
and current revisions. Group definition management, account/home assignments,
people visibility and administrator approval are independent permissions.
Suspension revokes sessions and denies further authenticated work. The system
owner cannot be removed, suspended or reassigned through delegated operations.

## Data inspection

Authorized staff can inspect stored application data to operate and improve the
service. Homeowner public-sharing switches do not block authorized internal
inspection. Operator routes are dedicated and audited; they do not fabricate a
home membership or let a household request cross the active-home boundary.

| Endpoint area | Permission boundary |
| --- | --- |
| `/api/v1/admin/accounts` | `accounts.read`; personal details also require `people.read` |
| Account status changes | `accounts.manage` with expected revision |
| `/api/v1/admin/homes` and home records | `homes.read`; people fields gated separately |
| Access-group definitions | `groups.manage` |
| Account/home assignments | `accounts.assign` / `homes.assign` |
| Administrator review | `administrators.approve` |
| Country/reference configuration | `countries.manage` |
| Privacy notice editing | `policies.manage` |
| Audit inspection | `audit.read` |

Records include stock and home catalog data, with public-sharing state shown
separately. Secret credentials, session proofs and encryption material remain
excluded. Operators without a required permission receive a server rejection,
even if they construct a request outside the Admin interface.

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

## Billing and synchronization

Billing enforcement remains disabled. Manual scoped group assignments control
features today and form the future mapping point for paid plans. A plan or
subscription display does not itself grant access.

The backend publishes the canonical contract and lock; both Flutter clients
copy those exact artifacts and regenerate. See the controlling
[pre-release decisions](../unification-decision-record.md) for registration,
quota downgrade, home delegation and country policy semantics.
