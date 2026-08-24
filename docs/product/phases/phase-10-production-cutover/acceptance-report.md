# Phase 0–10 acceptance report

> Historical release-candidate snapshot: the API 1.13.2 identity below is
> preserved as evidence, not the current contract. API 1.15.0 and the separate
> Linux Flutter Admin client are governed by `docs/product/project-memory.md`
> and `docs/inventory-integration-roadmap.md`; a new acceptance run must record
> their immutable commits and artifacts.

This document is the release-candidate evidence index. Automated results are
attached by immutable commit SHA; operator evidence is recorded against the
same container digests. A blank or failed evidence reference keeps the release
candidate out of production.

## Candidate identity

| Field | Evidence |
|---|---|
| Git commit | Pending final branch head |
| Runtime image digest | Pending CI publication |
| Media-worker image digest | Pending CI publication |
| Web image digest | Pending CI publication |
| OpenAPI contract | `1.13.2`, SHA-256 `1b6b7f09240ace0ba6b7e7279259687569dfbacb112ea7dbd4094fe27ccd0108` |
| Migration range | `Version20260729000100`–`Version20260809001600` |

## Automated acceptance

| Gate | Required result | Evidence |
|---|---|---|
| Formatting, PHPStan, architecture, contract | Pass | GitHub Quality workflow |
| Unit/integration tests | Pass | GitHub Quality workflow |
| MySQL and MariaDB migration/test matrix | Pass | GitHub Quality workflow |
| Redis and Valkey outbox redelivery | Pass | GitHub Quality workflow |
| Coverage and mutation ratchets | Pass | Uploaded workflow artifacts |
| Secret, SAST, dependency and image scans | Pass | GitHub Security workflow |
| Production runtime/media/web image smoke | Pass | Production image workflow |
| Runtime/OpenAPI route parity | Pass | Structural and contract gates |
| Catalog contribution privacy | Pass | Implemented with unit/SQLite regressions; branch CI pending |
| Platform-role/home isolation | Pass | Implemented with permission-boundary regressions; branch CI pending |

## Operator acceptance

| Scenario | Required evidence | Status |
|---|---|---|
| Login-link email and invitation delivery | Mailcow/SMTP retry and dead-letter rehearsal | Pending staging |
| Two-device offline pantry convergence | Inventory, counts, receipts and lists over supported offline window | Pending client staging |
| Cross-home authorization | Randomized tenant isolation suite and manual review | Pending staging |
| AI extraction | OpenAI primary, alternate failover, independent validation and review | Pending sandbox keys |
| Private media | Image/video upload, quota, retained restore, deletion and export | Pending staging |
| Payments | PayPal and selected hosted-card sandbox checkout/webhook/replay | Pending provider credentials |
| Data rights | Account/home export, one-time download and erasure disclosure | Pending staging |
| Backup and restore | MySQL/MariaDB, app artifacts, retained media and escrowed keys | Pending operator rehearsal |
| Load and soak | Approved latency/error/queue-lag objectives | Pending isolated target |
| Cutover and rollback | Canary, worker drain, migration, rollback/forward-fix record | Pending deployment |

## Release decision

The backend may be called **testing-ready** when all automated gates pass. It
may be called **production accepted** only after every operator row above has a
dated evidence link, named approver, measured result, and rollback decision.
