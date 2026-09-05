# Providentia backend contributor contract

## Repository boundary

This repository owns the API, domain services, persistence, authorization,
CLI, deployment images, and the canonical OpenAPI contract. The multi-platform
homeowner application lives in `providentia-systems/client`; the Linux Flutter
operator application lives in `providentia-systems/admin`. Do not add an
authenticated household or operator management UI to the backend. Authentication uses email codes entered in the originating client. The backend
serves no browser login ceremony.

## Start every development session

On a Linux contributor host, run:

```bash
bash tools/agent-setup.sh
source .agent-env
```

Read `tools/agent-requirements.json` and
`docs/deployment/agent-development.md` before changing toolchains or workflows.
Use `bash tools/agent-setup.sh --check` for a non-mutating setup check and
`bash tools/agent-setup.sh --matrix` for the pinned database/broker matrix.
Use `bash tools/agent-setup.sh --doctor` for the complete local validation lane.
Do not describe unrun checks as passing; do not weaken gates because a managed
runner lacks package or Docker access.

## Architecture and privacy

- Preserve module and Domain/Application/Infrastructure/Http boundaries.
- Keep authorization and tenant checks in application services and stores,
  not in presentation-specific conditionals.
- Homeowner access is isolated by membership and the active home group. The
  system owner can inspect all application data and delegates operator access
  through administrator groups. Use dedicated, audited operator endpoints;
  global catalog sharing is separate from internal operator visibility.
- Credentials and session proofs remain encrypted or hashed and are never
  returned by administrative inspection endpoints.
- Contribution is explicit, field-scoped, attribution-free outside the home,
  and moderated before global publication.
- AI credentials are encrypted, write-only, and home-scoped. AI extraction is
  a proposal that requires review; approved commits must be idempotent.
- Do not import, log, send, or commit private handover media. In particular,
  non-grocery or medical source images are never AI test fixtures.

## Contract and synchronization

`contracts/openapi/providentia-v1.json` is authoritative. Runtime routes,
schemas, the lock hash, generated clients in the other repositories, and
synchronization protocol changes move together. Use distinct global and
home-private identifiers. Backfill ordering must create dependencies before
records that reference them.

## Completion

Keep changes focused and human-readable. Prefer existing ports, services, and
stores over parallel paths or speculative abstractions. Before handoff run the
doctor lane, review `git diff --check`, and ensure required GitHub checks are
green. Production billing enforcement remains disabled unless an explicitly
approved release slice enables it.
