# Providentia pre-release product and access decisions

**Adopted:** 2026-09-05. **Scope:** backend, homeowner client and administrator
client. **Contract:** API 2.0.0; exact artifacts are pinned by each repository's
contract lock. This record supersedes the August pilot decisions wherever
identity, permissions, administrator visibility, profiles or onboarding differ.
The system has no deployed clients or live user data. Code may be aligned
directly; production database migrations and future release discipline remain.

## Identity and registration

Both clients request an eight-digit email code from the backend. The user enters
that number in the requesting client; no login URL, browser approval, polling,
password or development authentication bypass is part of the protocol. A code
expires after ten minutes, permits at most five guesses, and is single-use.
Requests have a sixty-second resend cooldown. Keyed hashes protect the code and
request binding; the encrypted notification outbox delivers the email. The
request fixes the application kind, installation UUID and session transport.
Native clients retain credentials in protected storage. Browser refresh
credentials remain in hardened cookies with CSRF protection. Email access is
the account recovery boundary; email OTP is not phishing-resistant MFA.

After verifying email, a new user supplies a name, published country and current
privacy-notice acceptance; region and city are optional. No home is silently
created. Country settings select the account's starter group: standalone new
accounts can own one home by default, while accounts with pending invitations
receive the invited group, initially allowing home membership and zero owned
homes. Administrators can change these assignments. Existing group membership
does not change simply because another home invites an established account.

One immutable account can have multiple verified email addresses. Adding an
address requires a code to that address; primary-address changes and removal
require fresh security confirmation. At least one verified primary address must
remain. Verification never merges separate accounts. Invitations are matched
against all the account's verified addresses.

People can edit their name and choose a default avatar, opt-in Gravatar based
on a verified address, or a cropped uploaded image. A home has a name,
description, image/default icon, optional map location, country/place, currency
and timezone. Household image access follows home membership and feature
permissions; operator access follows administrator permissions.

## Scoped groups and home permissions

| Scope | Single assignment | Controls |
| --- | --- | --- |
| Account | One account group per person | Home creation/joining and owned/joined home limits |
| Home | One home group per home | Available functions, resource limits, role defaults and owner delegation ceiling |
| Administrator | One administrator group per approved operator | Administrative areas, data inspection, approvals and management actions |

A person may be an owner in one home, manager in another and member in a third.
Each home evaluates its own group and the person's membership role. More
permissive rules in one home never grant access in another home. All co-owners
share the same home's group. Account-level allowances remain independent of
which home is open.

Home groups define owner capabilities and manager/member/viewer defaults.
Owners may choose individual `inherit`, `allow` or `deny` overrides only for
permissions the administrator makes delegable. An explicit allow cannot enable
a feature disabled by the home group. The backend computes effective
permissions and returns them to the client; hiding a button is not the
authorization boundary. Role, group and override changes are revisioned and
audited.

Inviting additional owners/managers/members is an explicit home feature,
disabled in the initial starter group. Administrators set total and per-role
limits. A recipient must explicitly accept an invitation in the client;
acceptance rechecks current inviter authority and available account/home quota.
Invitation decline, revocation, expiry and repeated acceptance are bounded
server operations. Ownership transfer requires fresh security confirmation.

Reducing a limit preserves existing records. For example, a home with twenty
categories moved to a limit of ten retains all twenty, can edit/remove them,
and cannot create another until below ten. Existing members keep working when
invitations are disabled. Removing an operation feature, such as AI use or
credential management, immediately prevents that operation. No automatic
trimming, account deletion or stock loss occurs on downgrade.

## Administrator authority and public sharing

The first system owner is authorized with `php bin/providentia system:owner
owner@example.test`, then signs in through the normal email-code flow. The
system-owner group is protected and contains all administrator permissions.
Other Admin sign-ins create pending access requests. An operator with approval
authority approves a request into an unprotected administrator group; permission
to list administrators does not imply permission to approve or manage them.

The system owner can inspect all application data and delegate the relevant
areas to authorized staff. Dedicated audited operator endpoints expose account
and home information, stock, products, categories and other operational records
according to granular administrator permissions. People visibility is separately
gated. Credential values, verification codes, session proofs and encryption keys
are never returned by these inspection endpoints. Internal staff agreements are
an organizational responsibility; the product enforces the assigned access.

Public sharing is a different decision. Homeowners control contributions of
catalog metadata for other homes, with field-specific consent and moderation.
Starter categories and product definitions contain no household quantities.
Private quantities, locations, purchases and notes are not published through
the global catalog. Other homeowners only see homes in which they have an
active permitted membership. The privacy notice accurately explains authorized
internal use of application data to operate and improve the product; it must
not promise that the platform operator can never see stored household data.

## Countries and privacy notices

The official `dr5hn/countries-states-cities-database` source supplies country,
region and city reference data. A background updater validates downloads before
publishing an import, retains source identifiers and records job status.
Administrators request updates from the country administration area. Updates
must preserve local publication decisions, groups and privacy configuration.
Only Namibia is initially published. Country settings select default account,
invited-account and home groups, currency, timezone and a published privacy
notice. Policy versions and each user's acceptance are recorded. The bundled
policy is an editable starting notice, not a representation that country-specific
legal review is already complete.

## Inventory, AI and later billing

Manual inventory, categories/products, quantities, purchases, shopping and
synchronization remain usable without AI. AI extraction produces reviewable
proposals and never silently changes stock. Source images can stay on the
client and be deleted there; optional backend media paths remain separately
permissioned and quota controlled. Structured confirmed inventory data is
stored on the backend.

Bring-your-own AI credentials and future platform-provided AI are separate
features. Credentials are encrypted, write-only and scoped to their configured
owner. Platform AI and query billing are future commercial work. Billing
enforcement and checkout remain disabled during stabilization. Administrators
manually assign groups now; later paid plans can map to those same groups
without making payments an authorization bypass.

## Completion and release

The three repositories share one coordinated branch name and linked pull
requests because GitHub pull requests are repository-specific. Runtime, the
canonical contract, generated clients, documentation and meaningful regression
checks must agree. Required checks must pass on the published heads. Draft PRs
or code coverage alone do not establish deployment readiness; actual deployed
acceptance, backups, mail and the selected production environment must also be
verified before launch.
