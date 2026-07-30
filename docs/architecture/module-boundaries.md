# Module and dependency boundaries

The backend is a modular monolith. Each module may use only the layers it
actually needs, while the repository reserves all four explicit boundaries:

```text
Http -> Application -> Domain
Infrastructure -> Application and Domain ports
Composition root -> concrete adapters
```

Expected use-case failures use the transport-neutral
`SharedKernel\Application\Problem`; HTTP middleware is responsible for mapping
them to problem details. Application code must not import
`SharedKernel\Http`, allocate security tokens directly, or choose a UUID
library. Those details enter through owned application ports.

Modules are `SharedKernel`, `Identity`, `Home`, `Catalog`, `Inventory`,
`Purchasing`, `Shopping`, `Synchronization`, `AiIntegration`,
`Administration`, `Reporting`, and `PublicSite`.

Rules enforced by both Bash/Node and PHP architecture checks:

- Domain cannot import Doctrine, Laminas, Mezzio, Enqueue, Redis, PSR HTTP, or
  transport code.
- Domain, Application, and Http cannot import an Infrastructure namespace.
- Application cannot import the shared HTTP namespace.
- Application cannot call `random_bytes()` or a concrete/static UUID library.
- Only factory classes receive the PSR-11 container.
- Every module provides configuration through its own `ConfigProvider`.
- Cross-module implementation access is forbidden. Future cross-module calls
  require published application contracts or immutable events.

The first persistence object remains a historical proof aggregate, not a model
for identity, homes, catalog, or inventory. Phase 2/4 business persistence uses
module-owned application interfaces and Infrastructure DBAL adapters.

The detailed review and residual-risk ledger is in
[the 2026-07-30 SOLID and backend quality audit](solid-quality-audit-2026-07-30.md).
