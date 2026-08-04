# Phase 10 — hardening and production cutover

Phase 10 turns the completed backend into a verifiable production candidate.
It does not declare a deployment accepted merely because unit tests pass.

## Automated gates

- formatting, static analysis, architectural boundaries, and locked OpenAPI
  route parity;
- unit and integration suites on supported PHP and database profiles;
- coverage and risk-focused mutation thresholds;
- dependency, licence, secret, SAST, filesystem, and container scans;
- immutable production image and Compose profile validation;
- live HTTP/readiness checks, protected operational endpoints, load smoke, and
  durable outbox recovery proof; and
- SBOM, provenance, image digest, and release evidence retention.

## Operator acceptance

- restore an encrypted production-equivalent database and retained-media set;
- verify credential/media encryption keys from separately controlled escrow;
- rehearse migration, worker drain, cutover, rollback, and outbox recovery;
- run cross-home authorization, multi-device offline convergence, AI/media,
  SMTP, payment sandbox, and data export/erasure scenarios;
- record latency, error, queue-lag, backup recovery, and retention evidence; and
- approve the immutable image digest and acceptance report before promotion.

See [deployment and recovery](deployment-and-recovery.md) and the
[Phase 10 hardening runbook](../../../operations/phase-10-hardening.md). Record
the immutable results in the [acceptance report](acceptance-report.md).

Unchecked live/operator evidence keeps the pull request a release candidate.
It is never converted into a false production claim.

## Existing-deployment synchronization backfill

Before enabling protocol-v2 clients against an installation that already has
pantry data, drain API and worker writes after the database migration and run:

```console
php bin/providentia sync:backfill --limit=250
```

The command reads authoritative Inventory, Purchasing, and Shopping tables in
deterministic bounded pages. It appends only resources that have no matching
`change_log` identity and never changes pantry-domain tables. It is safe to
restart: completed resources are skipped. Use `--home=<uuid>` for a staged
household and `--once` for exactly one bounded batch. A result with
`"complete":true` is required before client traffic is restored. Run the
command again after completion and retain evidence that `appended` is zero,
then relay the generated outbox messages before reopening writes.

Keep writes drained for the entire backfill. The missing-row recheck prevents
ordinary retries from duplicating feed entries, but the cutover drain is what
guarantees that an older authoritative snapshot cannot be appended after a
concurrent live update.
