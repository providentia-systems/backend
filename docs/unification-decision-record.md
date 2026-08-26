# Providentia unification decision record

**Status:** Adopted 2026-08-26 · **Round:** Usable Pilot 0.1 convergence
**Scope:** This record is the shared product/decision baseline for the
`providentia-systems/backend`, `providentia-systems/client`, and
`providentia-systems/admin` repositories. The same record is synchronized into
all three repositories; the backend copy is canonical when they diverge.

Where an older document, comment, or implementation conflicts with this record,
this record controls. Conflicts are resolved in this order: (1) the owner's
latest explicit product decisions recorded here, (2) an approved acceptance
criterion implementing them, (3) the pinned API contract and cross-repository
privacy/authorization invariants, (4) the master implementation prompt and the
July 29 migration handover, (5) existing implementation details, which are
evidence of work — not authority to preserve a superseded decision.

## 1. Integration manifest (round baseline)

| Repository | Merged `main` baseline for this round |
| --- | --- |
| backend | `a64fac766daa8ca4811f7c9d6dbbef936154f65c` |
| client | `b21e93e7d8de6e16bc92275b46bd5f02e83f6b37` |
| admin | `3c532282557e8f311219fb8ce2e4f830eddc4620` |

Contract at baseline: **API 1.18.0**, canonical OpenAPI JSON SHA-256
`fb7f18cc8d2e0f7aaf3ec9f1bd3039316c6f44af0023110936778a8d616a6759`,
deterministic gzip SHA-256
`b17569f05e8384416498254e882c7e790399a4cde8677439a3efa52c74181d25`.

This round changes the contract **once**: the backend removes and adds the
operations listed in §4, publishes the updated contract, and both Flutter
clients repin and regenerate from that single publication. Repeated generated
code churn while the contract is moving is explicitly avoided.

## 2. Settled decision register

| Decision | State |
| --- | --- |
| Person identity | **Settled.** One immutable user identity resolved through a verified, normalized email. The email string is not a home or membership identifier. |
| Sign-in | **Settled.** Email login-link only. Zero human-account password artifacts anywhere: no password field, hash, route, DTO, service branch, environment toggle, setup-script provisioning, or hidden development path, in any repository. Mailbox ownership is proved only through the one-time, application-bound login-link exchange. |
| Trusted-device session | **Settled.** A trusted installation remains signed in until explicit sign-out, device/session revocation, global account disablement, credential-rotation failure, or a named account-level security invalidation. There is **no finite inactivity ceiling**. Losing membership in one home revokes and purges only that home's permissions and private local state. |
| Tenant and roles | **Settled.** The tenant is `Home`. Roles are `owner`, `manager`, `member`, `viewer`; permissions are server-authoritative and revisioned at role level. |
| Multi-home behavior | **Settled.** One identity can create, join, switch between, and hold different roles in multiple isolated homes. Owners retain ultimate membership, role, and ownership-transfer authority, including owner-driven member removal. |
| Invitee onboarding | **Settled.** A first-time login-link account with pending invitations is shown those invitations plus an explicit "Create a home" choice. No automatic `My home` is created without the person choosing it. |
| Product visibility | **Settled.** Home-private by default. Global catalog entry happens only through explicit, sanitized, consent-bound contribution and moderation — never as a side effect. |
| Starter experience | **Settled.** A fresh deployment bootstraps the approved starter categories/products automatically and idempotently. No home inherits another household's private stock or history. |
| Data intake | **Settled.** Desktop spreadsheet import (stage → map → review → confirm), receipt/document capture, and storeroom stock-photo counting are pilot-critical. Manual stock control must work fully with no AI provider configured. |
| AI review boundary | **Settled.** AI output is a proposal. A human must review and explicitly commit any inventory-changing result. |
| AI billing/credentials | **Settled.** Bring-your-own-key. No silent platform-funded key for household AI use. Endpoint and token are supplied at the same ownership scope. |
| AI credential owner scope | **Settled (adopted per handover recommendation).** The default is a **private per-person provider profile** used for that person's scans. An explicitly authorized home-shared profile is a later, deliberate owner choice — sharing is never inferred from storage scope. |
| Custom AI endpoints | **Settled.** OpenAI-compatible and Ollama endpoints are owned at the same scope as the credential (person/home), not deployment-wide, with HTTPS/allowlist/SSRF validation and a deliberately separate policy for user-controlled LAN/Ollama endpoints. |
| Client-to-backend binding | **Settled.** The backend URL is compiled into each client at build time (`PROVIDENTIA_API_BASE_URL` dart-define, HTTPS-only outside loopback development, origin-only). There is no end-user UI to change it: one backend, multiple fixed-aim clients. |
| Backend surface | **Settled.** The backend stays a headless JSON API: no browser login, no public site, no administrative UI. A minimal non-privileged link-handoff page is acceptable where platform deep-link behavior requires it. |
| Admin boundary | **Settled.** Admin receives only sanitized, consent-bound, attribution-free global-catalog projections and privacy-safe account metadata. It never gains household access. |
| Billing | **Settled.** Deliberately disabled during the free stabilization phase; not represented as complete. |
| Optional MFA | Later roadmap; not a Pilot 0.1 gate. |
| Home description/image | Preserved roadmap outcome; home name is the pilot minimum. |

## 3. Superseded decisions (labelled per Gate 0)

The following remain visible in history but are **superseded** and are being
removed or corrected in this round:

- Any human-account password or password-reset capability (backend service
  branches and routes, client development-password transport/UI, admin
  reset/verification operations and UI, setup-script password provisioning,
  and the hidden random password hash minted for login-link users).
- Finite session idle ceilings (30-day web / 60-day native) as an accidental
  default.
- Deployment-wide OpenAI-compatible/Ollama endpoint configuration as the only
  endpoint mechanism.
- Automatic `My home` creation for first-time invitees.
- Old public-site language and API 1.13.2 references in permanent documents.

## 4. Round scope (Usable Pilot 0.1)

Backend, in order: zero-password removal with negative contract tests;
durable trusted-device session policy; owner-driven member removal; explicit
invitee onboarding; person-scoped BYOK provider profiles with owned endpoints
and SSRF policy; automatic starter-catalog bootstrap for prebuilt/production;
then one contract publication.

Client, after the contract lands: repin/regenerate; remove development-password
artifacts; ownership-transfer and member-removal UI; invitation-first
onboarding; desktop CSV/XLSX import over the existing stage/review/confirm API;
BYOK endpoint/token settings; receipt and stock-photo journey closure.

Admin, after the contract lands: repin/regenerate; remove password-reset and
standalone verification operations and UI while keeping the generic
session/keyring purge boundaries.

Definition of done for the round: every workflow green in all three
repositories at the final heads, one integration manifest pinning those heads,
and an owner-facing runbook for spinning up the backend and aiming both clients
at it for real testing.

Out of scope unless explicitly promoted: full Admin catalog CRUD workbench,
advanced/cross-home reporting, MFA, store signing, billing enforcement,
production cutover.
