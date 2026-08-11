# Providentia backend documentation

## Current phase

- [Backend/client inventory integration roadmap — P0–P3](inventory-integration-roadmap.md)
- [Phases 04–06 testing-readiness report](product/phases/phase-04-06-testing-readiness.md)
- [Phase 0 verified evidence](product/phases/phase-00-evidence/README.md)
- [Phase 1 foundation](product/phases/phase-01-foundation/README.md)
- [Phase 2 identity, homes, and catalog](product/phases/phase-02-identity-homes-catalog/README.md)
- [Phase 4 synchronization protocol](product/phases/phase-04-synchronization/README.md)
- [Phase 5 inventory and purchasing](product/phases/phase-05-inventory-purchasing/README.md)
- [Phase 6 AI integration](product/phases/phase-06-ai-integration/README.md)
- [Phase 7 catalog administration](product/phases/phase-07-catalog-administration/README.md)
- [Phase 8 intelligence and reporting](product/phases/phase-08-intelligence-reporting/README.md)
- [Phase 9 launch readiness](product/phases/phase-09-launch-readiness/README.md)
- [Phase 10 production cutover](product/phases/phase-10-production-cutover/README.md)
- [Project memory and owner decisions](product/project-memory.md)
- [Controlling implementation prompt](product/providentia_master_implementation_prompt_V1.md)
- `docs/product/phases/phase-00-evidence/` — corrected evidence and
  architecture package, retained as the reviewed Phase 0 documentation trail

## Engineering

- [Module and dependency boundaries](architecture/module-boundaries.md)
- [SOLID and backend quality audit — 2026-07-30](architecture/solid-quality-audit-2026-07-30.md)
- [Contracts and release order](architecture/contracts.md)
- [Run published production images locally](deployment/prebuilt-images.md)
- [Source-build local development and deployment profiles](deployment/local-development.md)
- [Client login, test users, household roles, and platform administrator](deployment/client-user-testing.md)
- [Authoritative catalog seed and reconciliation](operations/catalog-seed.md)
- [Queue, outbox, retries, and failed review](operations/async-messaging.md)
- [Phase 10 hardening and release evidence](operations/phase-10-hardening.md)
- [Security posture](security/foundation-security.md)

The unchanged handover ZIP remains external protected evidence and is
identified by checksum in the Phase 0 package. This repository contains no
private receipt or stock images, medical documents, production credentials,
or source database dumps. See
[Source-build local development](deployment/local-development.md#obtain-or-create-the-development-handover)
for where authorized testers obtain the archive and how to package the minimal
checksum-verified subset accepted by development setup.
