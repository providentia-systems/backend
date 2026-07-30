# Phase 2 identity, homes, and catalog

## Implemented increment

Phase 2 establishes real protected accounts and tenant boundaries without
pulling Phase 3 inventory behavior forward. All runtime services are composed
through explicit Laminas ServiceManager factories, and all persistence remains
behind application interfaces.

## Identity and devices

The identity API supports registration, email verification and resend, login,
access authentication, refresh rotation/replay response, password-reset request
and completion, session listing/revocation, and logout. Native clients receive
opaque access/refresh values; web clients can request secure cookies and must
send the server-verified double-submit CSRF token on state changes.

Registration uses the same public `202` response shape for new and existing
addresses. That narrows direct content-based enumeration but is not a timing
guarantee: verification SMTP is synchronous and only runs when a token is
issued. Durable asynchronous notification delivery with a uniform request path
remains required before production-grade timing resistance can be claimed.

Device IDs are UUIDs owned by one account and cannot be reassigned. An access
session carries one device and optional active home; the active home is a
navigation preference, never authorization evidence. Account and one-time
credential rows have no household data.

## Homes and authorization

Creating a home atomically creates its owner membership and audit event.
Current membership is re-read for every protected home action. The roles are:

| Role | Membership view | Invite | Change roles | Write sync | Read sync |
|---|---:|---:|---:|---:|---:|
| owner | yes | manager/member/viewer | yes, non-owner | yes | yes |
| manager | yes | member/viewer | no | yes | yes |
| member | no | no | no | yes | yes |
| viewer | no | no | no | no | yes |

Ownership transfer is a dedicated, transactional operation that promotes an
active target and demotes the current owner. A generic role update cannot
modify the owner, and the owner cannot leave. Role updates use an expected
membership revision.

Invitation values are random and stored only as hashes. They are tied to
normalized email, expire after seven days, are accepted once, and never copy
private home data into email. Acceptance atomically rechecks that the original
inviter is still an active member and still has authority to grant the invited
role; a revoked or demoted inviter cannot leave a latent privilege grant.

## Global catalog

The Phase 2 migration creates global categories, units, products, variants,
packs, aliases, barcodes, identity rules, icons, proposals, revisions, merge
events, quarantine, and seed-run records. Only sanitized global catalog
identity fields cross the migration boundary. Household quantities, notes,
receipt/media paths, provenance comments, and other private evidence are not
seeded.

Public product search returns only approved global data and is bounded by a
fixed maximum result size. The full evidence gate is documented in
`docs/operations/catalog-seed.md`.

## Remaining Phase 2 acceptance boundary

This increment is ready for review when migrations work on
SQLite/MySQL/MariaDB, the container
resolves the new object graphs, the OpenAPI contract matches routes, catalog
reconciliation passes exactly, and authorization tests prove that absent or
cross-home memberships cannot read or mutate another home.

It must not be called complete Phase 2 yet. Invitation revocation is not
exposed as an owner/manager command. Ownership transfer is an immediate
owner-only optimistic command; it does not yet use a proposed/accepted,
step-up-authenticated workflow. MFA/passkeys/social login, production mail
retry, catalog review workflows, support-grant operations, inventory, purchase
history, and household media also remain later work.
