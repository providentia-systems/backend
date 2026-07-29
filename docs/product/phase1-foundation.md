# Phase 1 backend foundation

## Outcome

The repository is a modular Mezzio application with a server-rendered anonymous
site, operational API, portable persistence proof, transactional outbox,
Redis-protocol queue adapter, long-running CLI workers, Prometheus metrics,
versioned contracts, and repeatable infrastructure profiles.

## Implemented scope

- All twelve bounded modules have ConfigProviders and explicit layer
  boundaries.
- SharedKernel contains the operational foundation; all other business modules
  remain empty shells pending their scheduled phase.
- Doctrine metadata stays under Infrastructure. The proof domain aggregate has
  no Doctrine attribute or base class.
- One migration uses Doctrine Schema APIs and is tested on SQLite, MySQL, and
  MariaDB.
- The public site consumes the versioned Fresh Market foundation values.
- The authoritative OpenAPI contract contains only the four Phase 1 operations.
- Enqueue is behind `AsyncMessageBus`; domain and application code do not import
  Redis or Enqueue.
- Failed asynchronous messages persist for explicit operator review.

## Deferred by design

Authentication, users, homes, membership, catalog records, inventory ledger,
purchases, synchronization business protocol, AI extraction, and reporting
behavior start in later phases. No endpoint returns fake success for any of
those features.

## Phase acceptance evidence

The structural suite runs without PHP dependencies. GitHub Actions becomes the
authoritative runtime matrix because the initial implementation environment
does not provide PHP, Composer, or Docker. A Phase 1 acceptance must include a
green lock bootstrap, PHP quality job, three-database matrix, two-broker matrix,
and generated Dart client proof.

