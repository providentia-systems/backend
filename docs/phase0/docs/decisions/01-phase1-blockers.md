# Decisions Required Before Phase 1 Is Closed

**Status:** Phase 0 approved; Phase 1 authorized; unresolved decisions retain
their recorded completion/later-phase deadlines

**Rule:** This document recommends defaults but does not approve them. A recommendation becomes a project decision only when the owner records an explicit answer.

## 1. Classification

- **Start blocker:** Phase 1 work would create rework or cross a security/data-ownership boundary without the answer.
- **Completion blocker:** Neutral foundations may proceed, but Phase 1 cannot be accepted until the answer is recorded.
- **Later-phase blocker:** It does not block Phase 1; the affected later phase may not start or ship without the answer.
- **Non-blocking commercial/release decision:** Engineering can proceed using non-commercial/internal wording, but no public claim or launch may precede the decision.

## 2. Decision summary

| ID | Decision requested by V1 | Phase 1 classification | Recommended default, not approved | Decision deadline |
|---|---|---|---|---|
| D-01 | Confirm Android and iOS as first-class release targets alongside Windows, macOS, Linux, and web | **Completion blocker** | Confirm both as first-class. V1 already selects Flutter across both and requires Android/iOS build jobs; explicit confirmation fixes CI and release acceptance scope. | Before Phase 1 CI/packaging baseline is accepted |
| D-02 | Record the official public/project name | **RESOLVED 2026-07-29** | Owner selected `Providentia` as the official project/product name and base for repositories, namespaces, packages, contracts, deployment resources, and documentation. Domain/app-store/trademark due diligence remains a public-launch gate and does not reopen the name. | Closed |
| D-03 | Permit cloud images to transit the application backend without persistence, or require native direct cloud calls | Later-phase security blocker | Permit an encrypted server-proxy mode that streams without persistence, especially for web; retain direct local/self-hosted mode. Do not claim on-device-only processing. | Before Phase 6 cloud adapter/credential implementation |
| D-04 | Select default AI privacy mode and whether advanced native direct BYOK is permitted | Later-phase security blocker | Default to explicit **local/self-hosted direct** when available; otherwise show AI unconfigured. Make encrypted proxy the recommended cloud mode. Defer advanced native direct BYOK from first release unless explicitly accepted with warning. | Before Phase 6 settings/UX contract |
| D-05 | Include optional encrypted private-media backup in first release or later | Later-phase scope/privacy blocker | Defer to a later release. Keep originals device-local and synchronize structured results only. | Before any server private-media schema/storage work; no later than Phase 6 |
| D-06 | Choose first authentication methods beyond email/password | Later-phase identity blocker, not Phase 1 | Ship Phase 2 foundation with verified email/password, reset, revocable device sessions, and MFA-ready interfaces. Evaluate passkeys as the first addition; add no social provider without owner/provider/privacy approval. | Before Phase 2 authentication scope is frozen |
| D-07 | Automatically create sanitized global proposals for unknown home products or require per-item opt-in | Later-phase privacy/product blocker | Require explicit per-item opt-in. Unknown products remain immediately usable and private regardless. | Before Phase 2 catalog proposal contract; definitely before Phase 7 |
| D-08 | Confirm first-release locales, currencies, units, time zones, and languages | Later-phase data/UX blocker | Preserve only the prompt-confirmed migration baselines `en-NA` and NAD. Treat metric/common household units and Africa/Windhoek as unapproved recommendations until handover evidence and owner choice confirm them. Design locale/currency/unit/time-zone fields without hard-coding. | Before Phase 3 localization architecture and Phase 5 import |
| D-09 | Select default production DB deployment: external, self-contained, or equal paths | **Completion blocker for recommended-default documentation only** | Document both; recommend self-contained MariaDB for smallest self-hosted installation and external MySQL/MariaDB for managed/scale deployments. Do not call them operationally equal: use-case guidance is clearer. | Before Phase 1 deployment guide is accepted |
| D-10 | Select Redis Open Source, Valkey, or both as equally tested deployment profiles | **Completion blocker for default-image/docs selection only** | Test both through the same queue contract; recommend Valkey as the bundled default and keep Redis Open Source supported after licence/version verification. | Before Phase 1 Compose defaults and support policy are accepted |
| D-11 | Define licensing, pricing, free tier, and operator responsibilities | Not a Phase 1 blocker; public-claims blocker | Make no pricing/free-tier/SaaS responsibility claims. Keep core operation open-source, self-hostable, provider-cost-neutral, and document that users/operators supply infrastructure/provider credentials until a business decision exists. | Before Phase 9 public marketing and terms |

## 3. Decisions that genuinely block beginning Phase 1

**None of the listed product questions must block starting neutral Phase 1 foundations.**

The V1 architecture already fixes the fundamental choices: two repositories, Flutter, Mezzio/Laminas, Doctrine, three database profiles, Drift, a project-owned queue port, Enqueue, Redis/Valkey compatibility, server-rendered public pages, and generated contracts.

The owner approved the Phase 0 package and explicitly directed work to proceed
to Phase 1 on 2026-07-29. D-01, D-09, and D-10 remain **Phase 1 completion
blockers** because they determine the published CI/release scope and default
deployment presentation. Foundations must keep these choices reversible.

## 4. Required engineering verification, not user product decisions

These must be completed in Phase 1 but should not be presented as preference questions:

| Verification | Why it is not guessed |
|---|---|
| Exact Flutter stable pin and updated platform matrix | Platform support changes over time |
| Exact PHP version | Must satisfy official support horizon and all selected dependency constraints |
| Exact Mezzio/Laminas/Doctrine/Enqueue versions | Compatibility, maintenance and licence state require resolution and tests |
| Supported MySQL/MariaDB/SQLite versions | Three-engine migrations and concurrency must pass |
| Supported Redis/Valkey versions | Enqueue protocol, persistence, redelivery and licence tests must pass |
| Router, reverse proxy, process supervisor and open-source scheduler selections | Must meet specific ports/operational requirements and licence policy |
| Identifier implementation | V1 permits UUIDv7 or another globally unique sortable strategy; benchmark and portability proof should choose one consistently |
| Cookie/host topology details | Providentia domains are not yet selected, but secure host separation, cookie scope, CORS and CSRF can be designed generically |

## 5. Owner response form

No value below is preselected. Record one explicit answer per item:

```text
D-01 Mobile first-class targets:
  [ ] Android and iOS
  [ ] Android only
  [ ] iOS only
  [ ] Neither in first release

D-02 Official name:
  [x] Providentia — owner-approved 2026-07-29

D-03 Cloud image route:
  [ ] Encrypted server proxy permitted, streamed and not persisted
  [ ] Native direct only for cloud providers
  [ ] No cloud image processing

D-04 AI default / native BYOK:
  Default: [ ] Unconfigured  [ ] Strict local  [ ] Encrypted proxy cloud
  Advanced native direct BYOK: [ ] Permit  [ ] Exclude from first release

D-05 Private-media backup:
  [ ] Later release
  [ ] First release, after separate privacy/security ADR
  [ ] Never provide server backup

D-06 Authentication additions:
  [ ] Email/password only initially, MFA-ready
  [ ] Add passkeys
  [ ] Add named social providers: ____________________

D-07 Catalog proposal consent:
  [ ] Explicit per-item opt-in
  [ ] Automatic sanitized proposal
  [ ] No global proposals in first release

D-08 First-release internationalization:
  UI languages/locales: ____________________
  Currencies: ______________________________
  Unit systems: ____________________________
  Time-zone behavior: ______________________

D-09 Production deployment default:
  [ ] External MySQL/MariaDB
  [ ] Self-contained MariaDB/MySQL
  [ ] Both, with no preferred default
  [ ] Both, with use-case recommendations

D-10 Queue broker default:
  [ ] Valkey
  [ ] Redis Open Source
  [ ] Both, with no preferred default

D-11 Commercial model/operator responsibility:
  Licence: _________________________________
  Pricing/free tier: ________________________
  Operator pays/provides: __________________
```

## 6. Contradictions and non-decisions surfaced

1. The amended V1 resolves the former naming conflict: `Providentia` is
   official. `StockHome` is retained only when identifying the historical
   React/TypeScript prototype or its evidence. Domain, app-store, and
   trademark-risk work remains due diligence, not a naming decision.
2. V1's platform table stated Ubuntu support through 26.04 LTS. Current official Flutter documentation lists through 24.04 LTS. This is a factual correction, not a user preference.
3. Supporting both deployment profiles does not decide which one should be presented first to operators. D-09 controls documentation and Compose defaults, not whether compatibility exists.
4. Queue abstraction and Redis/Valkey compatibility are accepted architecture. D-10 selects the default profile, not the domain or application dependency.

## 7. Approval record

When answers arrive, preserve them in a dated decision record containing:

- decision ID and selected answer;
- decision owner and date;
- affected ADRs/contracts/phases;
- privacy, migration and compatibility consequences;
- implementation issue or change reference; and
- conditions that would justify reopening the decision.

Silence, an empty checkbox, or `Not sure` is not approval.
