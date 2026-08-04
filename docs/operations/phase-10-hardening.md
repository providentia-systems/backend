# Phase 10 hardening and release evidence

This runbook defines repeatable evidence commands. It does not turn a green
build into production acceptance. Run destructive recovery exercises only in
an isolated environment created from synthetic Providentia data.

## Quality evidence

The ordinary quality workflow runs the test suite without a coverage driver.
Two independent jobs now produce evidence:

```bash
composer test:coverage
composer coverage:check
composer test:mutation
```

The coverage ratchet starts at 60% executable lines. The release objective is
80% overall and at least 90% for authorization, synchronization, inventory
ledger, credential handling, and payment/webhook policy as those slices enter
the suite. The mutation gate deliberately starts with deterministic inventory
and shopping arithmetic: MSI must be at least 60% and covered-code MSI at least
70%. Thresholds may only increase after a measured baseline; lowering one
requires a documented security review.

## Security evidence

`.github/workflows/security.yml` runs repository-owned Semgrep rules, full-Git
history secret detection, Composer advisory checks, filesystem
misconfiguration/secret scanning, and fixed/high-severity production-image
scanning. Findings are blocking. A suppression must identify its owner,
rationale, expiry, and compensating control; blanket ignore files are not
release evidence.

Every GitHub Action introduced by this hardening slice is pinned to an
immutable commit SHA. Scanner containers are pinned to explicit tool versions
so their rule engines do not move during a release. Record their resolved image
digests in the final acceptance report and promote only the scanned
application-image digests.

## HTTP and bounded load smoke

For an isolated candidate:

```bash
PROVIDENTIA_BASE_URL=https://candidate.example.test \
  bash tests/Acceptance/production-http-smoke.sh

docker run --rm \
  -e BASE_URL=https://candidate.example.test \
  -e VUS=10 \
  -e DURATION=30s \
  -v "$PWD/tests/Acceptance:/scripts:ro" \
  grafana/k6:0.57.0 run /scripts/load-smoke.js
```

The manual `Phase 10 acceptance` workflow preserves both summaries. Its smoke
load is deliberately read-only and bounded. Capacity claims require a separate
soak using an approved workload model and explicit latency/error objectives.

## Queue redelivery and broker recovery

On a fresh synthetic database with a running Redis/Valkey broker:

```bash
bash tests/Acceptance/outbox-recovery-smoke.sh
```

The script proves that redelivering a committed outbox message leaves one
published row and one processed-message receipt. For a real broker outage:

1. Persist a unique `foundation:prove` record.
2. Stop the isolated broker before invoking `outbox:relay --once`.
3. Prove the database transaction and pending outbox row remain committed.
4. Restart the broker, relay, consume, and then run the redelivery sequence.
5. Restart the worker between the two deliveries and verify the same invariant.
6. Record queue depth, oldest-pending age, attempts, recovery duration, and the
   worker's graceful shutdown result.

Do not delete or edit a failed-message row to make the exercise pass.

## Restic restore rehearsal

The application does not invoke Restic. The operator must:

1. Freeze the candidate application and migration digests.
2. Produce a transactionally consistent MySQL/MariaDB backup into the declared
   backup staging directory.
3. Snapshot private object/media data, deployment configuration, release
   metadata, and separately escrowed encryption-key versions.
4. Run the opt-in Restic backup profile and `restic check`.
5. Restore into an empty, isolated database and object store.
6. Supply the matching key versions, migrate once, and start the candidate.
7. Run the HTTP smoke and queue redelivery proof.
8. Verify cross-home isolation, catalog redirect/reversal, a stored suggestion
   explanation, retained-media access/deletion, and notification replay using
   synthetic records created before the backup.
9. Record the recovered point and elapsed restoration time. These measurements
   establish RPO/RTO; they are not invented in application configuration.

The final Phase 10 report must contain immutable workflow/run URLs, image and
scanner digests, backup snapshot ID, checksums, measured recovery results,
known exceptions with expiry, and the named reviewer who accepted each manual
gate.
