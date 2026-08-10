# Providentia product implementation

This directory is the ordered, human-readable implementation record for the
Providentia backend. Phase evidence, delivered behavior, acceptance gates,
known limitations, and forward work live under `phases/`. Cross-cutting
engineering material remains under `docs/architecture`, `docs/security`,
`docs/operations`, and `docs/deployment`.

| Phase | Outcome | Record |
|---|---|---|
| 0 | Verified source evidence and migration facts | [Phase 0](phases/phase-00-evidence/README.md) |
| 1 | Runtime, storage, observability, queue, and security foundation | [Phase 1](phases/phase-01-foundation/README.md) |
| 2 | Identity, homes, membership authority, and canonical catalog | [Phase 2](phases/phase-02-identity-homes-catalog/README.md) |
| 3 | Client contracts and generated-client readiness | Contract history in `contracts/` |
| 4 | Offline synchronization protocol | [Phase 4](phases/phase-04-synchronization/README.md) |
| 5 | Inventory, purchasing, shopping lists, and baseline cutover | [Phase 5](phases/phase-05-inventory-purchasing/README.md) |
| 6 | Privacy-controlled AI provider integration | [Phase 6](phases/phase-06-ai-integration/README.md) |
| 7 | Governed catalog administration | [Phase 7](phases/phase-07-catalog-administration/README.md) |
| 8 | Movement-derived intelligence and reporting | [Phase 8](phases/phase-08-intelligence-reporting/README.md) |
| 9 | Launch-facing governance, commercial controls, and distribution contracts | [Phase 9](phases/phase-09-launch-readiness/README.md) |
| 10 | Hardened deployment, recovery, security, and production acceptance | [Phase 10](phases/phase-10-production-cutover/README.md) |

The controlling scope remains
[`providentia_master_implementation_prompt_V1.md`](providentia_master_implementation_prompt_V1.md).
The phase records clarify implementation decisions; they do not silently
weaken its acceptance criteria.

The historical product phases above are distinct from the current P0–P3
cross-repository integration priorities. Backend/client responsibilities,
privacy invariants, contract pinning, delivery order, and current API digest
are synchronized in the
[inventory integration roadmap](../inventory-integration-roadmap.md).
