# SOLID and backend quality audit — 2026-07-30

## Executive verdict

The Phase 2–4 backend is a credible modular-monolith prototype, not spaghetti
code. Its strongest qualities are strict types, constructor injection,
module-owned persistence ports, explicit factories, tenant authorization, and
transactional synchronization writes. The repository was nevertheless **not
ready for an unconditional Phase 4 completion claim** at the start of this
audit:

- the 470-line synchronization application service mixed orchestration,
  schema validation, entity policy, canonical hashing, and response mapping;
- Application code depended on an HTTP exception type;
- security-sensitive randomness and UUID allocation were selected statically
  inside use cases;
- bootstrap read its high-water mark and records separately, allowing a
  concurrent write to produce a snapshot newer than its returned cursor;
- the last green baseline contained only 32 tests / 126 assertions and one
  PHPUnit deprecation, leaving important workflows and middleware uncovered;
- the documented Phase 4 product scenarios remain broader than the implemented
  prototype.

This change corrects those design defects, grows the focused test suite, and
makes warning/deprecation-free test execution a release gate. It does not
mislabel deferred product behavior as complete.

## Method

The audit covered all PHP source, tests, dependency configuration, migrations,
OpenAPI/architecture guards, Phase 0 decisions, the Phase 2 and Phase 4 scope
documents, and the supplied project handover and controlling implementation
prompt. Review used:

- dependency-direction and forbidden-import analysis;
- class, interface, branch, and file-size hotspot inspection;
- constructor/composition-root tracing;
- transaction, tenancy, idempotency, cursor, and credential-path review;
- existing-test-to-production-class mapping;
- PHP syntax parsing and repository structural checks;
- the previous GitHub Actions result as the executable baseline.

Line count is a diagnostic signal, not a verdict by itself. A long persistence
adapter may legitimately contain SQL; a shorter class may still combine
unrelated reasons to change.

## SOLID assessment

| Principle | Meaning | Finding before this change | Remediation and current position |
|---|---|---|---|
| **S — Single Responsibility** | One class/module owns one cohesive reason to change. | `SynchronizationService` owned protocol orchestration, validation, entity-specific rules, hashing, and presentation. | Split those reasons into envelope/operation validators, entity policies and registry, request hasher, result presenter, typed operation/envelope/snapshot values, and a 206-line orchestrator. Large Identity, Home, and DBAL classes remain explicit follow-up hotspots. |
| **O — Open/Closed** | Extend behavior through stable abstractions without repeatedly editing unrelated control flow. | Adding a synchronized entity required editing a central conditional validator. | `SyncEntityPolicy` and its registry make entity rules independently addable and testable. Protocol-wide operation validation remains closed and centralized. |
| **L — Liskov Substitution** | Every implementation honors the observable contract of its abstraction. | Ports generally had coherent adapters, but bootstrap exposed two independent calls that could not promise one consistent boundary. | `SyncStore::captureSnapshot()` now returns one immutable `SyncSnapshot` from one transaction. Port implementations and tests share typed operation/snapshot contracts. |
| **I — Interface Segregation** | Consumers depend only on the capabilities they use. | HTTP/application boundaries were blurred by Application services throwing `HttpProblem`; `IdentityStore` remains a broad 20-method port. | Introduced transport-neutral `Problem`; HTTP maps it at the middleware edge. The broad Identity/Home persistence ports are recorded follow-up debt rather than hidden as “fully SOLID.” |
| **D — Dependency Inversion** | Policy depends on owned abstractions; infrastructure supplies details. | Use cases called `random_bytes()` or static Ramsey UUID generation directly. | Added `SecureTokenGenerator` and reused `UuidGenerator`; native implementations are selected only in the composition root. Architecture tests now prevent transport imports and direct random/UUID allocation in Application code. |

## Material changes

### Application and transport boundary

`Providentia\SharedKernel\Application\Problem` represents an expected use-case
failure without depending on PSR-7, Mezzio, or an HTTP namespace. The existing
`HttpProblem` remains a compatibility subtype while `ProblemDetailsMiddleware`
is the single public HTTP mapper. Architecture checks reject new Application
imports from `SharedKernel\Http`.

### Security-sensitive generation

Authentication and invitations consume `SecureTokenGenerator`; foundation
records consume `UuidGenerator`. Native randomness and Ramsey UUID details live
under Infrastructure and are wired by explicit factories. Tests can now supply
deterministic generators without patching global functions.

### Synchronization

The new design separates:

- envelope identity/batch/device rules;
- closed operation schema and protocol-level validation;
- per-entity payload policy;
- deterministic request hashing;
- public result/change representation;
- orchestration and authorization;
- typed persistence input and captured snapshots.

Bootstrap now captures its high-water mark and current rows in one database
transaction. Oversized snapshots still fail safely before a cursor is
acknowledged.

### Test policy

The previous executable baseline was 32 tests / 126 assertions with one PHPUnit
deprecation. This change adds tests for authentication registration/login,
refresh rotation and replay, reset and authentication; home creation,
invitation, ownership, role, and leave rules; catalog query validation;
synchronization validators, policies, hashing, presentation, atomic snapshot,
conflicts, tombstones, replay, and cross-home denial; readiness aggregation;
identifier/token generation; problem mapping; request IDs, CORS, and security
headers.

PHPUnit is pinned to its current 12.5 schema and fails on framework or
application notices, warnings, deprecations, and risky tests. The final test and
assertion count must come from GitHub Actions; source-level counting is not a
substitute for execution.

Tests target meaningful observable behavior. Requiring a test for every private
helper or trivial line would couple the suite to implementation details and is
not treated as a quality metric. Production branches, security boundaries, and
failure modes are the priority.

## Remaining risks and phase gates

| Priority | Residual risk | Required gate |
|---|---|---|
| Blocker for full Phase 4 | Bootstrap supports one consistent page only and rejects homes above 250 current records. | Implement device/home-bound durable snapshot tokens and multi-page snapshot tests. |
| Blocker for full Phase 4 | No standalone operation-status recovery API; automated multi-day offline, token-refresh-during-sync, restart/failover, and full multi-device E2E scenarios remain open. | Complete the acceptance matrix in the synchronization protocol and run it across supported databases. |
| Blocker for production | Verification/reset/invitation email is synchronous, so delivery failure and timing enumeration remain possible. | Transactional mail outbox, uniform public path, retries, and failure tests. |
| High | `AuthenticationService` and `HomeService` remain large workflow façades; `IdentityStore` (20 methods) and `HomeStore` (12 methods) remain broad. | Split only when new use cases create distinct consumers; preserve transactions and avoid interface fragmentation without benefit. |
| High | DBAL adapters are SQL-heavy hotspots and do not yet have direct integration coverage for every method. | Add adapter contract suites for Identity, Home, and Catalog on SQLite/MySQL/MariaDB as their behaviors stabilize. |
| High | Statement/branch coverage and mutation score are not currently enforced. | Add PCOV/Xdebug coverage thresholds and establish a non-flaky Infection baseline in CI. |
| Medium | HTTP handlers, SMTP transport, worker failure paths, and several factories have limited isolated coverage. | Prefer request-level/container integration tests over one test per wiring line. |

## Readiness decision

This refactor is a quality-hardening gate for the existing Phase 2–4 prototype.
It is ready to merge only when the complete GitHub Actions suite passes without
warnings or deprecations. After that, the backend is suitable for the next
increment **without calling Phase 4 fully accepted**. The Phase 4 blockers above
must remain visible in planning and release documentation.
