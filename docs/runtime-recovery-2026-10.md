# Runtime recovery: synchronization notifications and database contention

This document describes the behavior introduced by the October 2026 runtime
repair branch. API 2.2.0 and the client-operation protocol remain unchanged.

## Receipt, shopping and access response contracts

Receipt and shopping creation now acknowledge full resources, including their
required identifiers and revisions. Receipt commit acknowledges the persisted
committed receipt; repeated commit preserves the existing identity and creates
no additional stock effect. Stock movement and receipt-history responses include
their home identifiers. Database decimal quantities and amounts are projected
as plain strings, and shopping lines expose an actual JSON `checked` boolean.

These projections also apply to receipt approval/commit synchronization output.
Previously retained numeric synchronization payloads are normalized when read,
without rewriting historical change-log rows. Sync timestamps use UTC RFC3339.
Empty access features, limits and role-permission maps serialize as objects;
permission lists remain arrays. Internal authorization maps remain unchanged.

`tests/Acceptance/runtime-response-contracts.sh` starts a real local HTTP server
against a guarded disposable fixture and validates all 15 originally reported
endpoint-method pairs plus adjustment creation and receipt history against the
unchanged OpenAPI schemas. It also checks synchronization, nulls, tiny decimals,
maximum quantities, tenant isolation, commit replay and server restart. Install
`jsonschema[format]==4.26.0`; missing format checkers fail rather than silently
skipping validation. The CI lane runs on SQLite, MySQL and MariaDB.

For the retained real Client/Drift receipt and shopping acceptance, prepare a
separate Client checkout at `21567ada32dcf343e4e777daf10d1a67a7de6128`, install its
pinned Flutter runtime, run `flutter pub get --enforce-lockfile` and
`dart run build_runner build --delete-conflicting-outputs`, then run from the
backend root:

```sh
bash tests/Acceptance/runtime-response-contracts.sh /absolute/path/to/client
```

The wrapper owns a fresh synthetic database, local PHP server and a temporary
Client test file. It refuses to overwrite an existing test file or use another
listener on its port, and cleans up its own fixture/server/file afterward. The
five cases cover lost and malformed commit responses, unavailable status lookup,
real revision conflict, process/database reopen, checked shopping readback and
exactly-once stock effects. They use real generated adapters and Drift; they do
not establish mobile hardware or graphical accessibility acceptance.

## Exact decimal storage on SQLite

SQLite NUMERIC affinity cannot represent every decimal accepted by API 2.2.0.
For example, `999999999.99999999` previously rounded to `1000000000` before a
serializer could read it. Migration `Version20261005000100` stores the affected
SQLite decimal columns as text, preserving new exact strings. MySQL and MariaDB
keep their native DECIMAL columns. SQLite arithmetic, aggregates and numeric
comparisons use exact decimal functions; they must not use floating-point `+`,
`SUM` or lexical text comparisons on these columns.

Take and verify a backup before applying the migration, stop writers, and use a
dedicated migration connection. The migration refuses to run with SQLite
foreign-key enforcement enabled inside the migration transaction because table
rebuilds could cascade-delete related data. It preserves foreign-key definitions,
indexes and triggers and checks foreign-key integrity before and after copying.
Normal application connection settings are not changed by this migration.

Existing values are preserved at their stored precision, including legacy
nonnumeric confidence labels. No migration can recover precision already lost
by historical NUMERIC storage; reconcile suspicious historical values with
their original source records. Downgrade refuses values that would lose
precision on conversion back to NUMERIC. Keep the exact-text schema or restore
the verified pre-migration backup rather than forcing a lossy downgrade.

## Notification consumption

`DbalChangeFeedWriter` emits `synchronization.record-changed.v2`. The consumer
accepts that type as well as retained v1 notifications and foundation proof
messages. V2 payloads must contain nonempty `homeId`, `entityType`, and `entityId`
strings, with positive integer `revision` and `cursor` values.

The current synchronization-notification handler records durable consumption in
`async_processed_messages`. The committed change feed remains the authoritative
source for device pull/bootstrap. This worker does not repeat household commands
or implement a new external push-notification transport.

A duplicate is acknowledged only after verifying that the same message identity
has already been processed by the same handler. Successful processing and its
failure-resolution update are transactional. Failure rows remain present for
audit; they receive `resolved_at` rather than being deleted.

## Recover notifications rejected by the old consumer

The old consumer acknowledged broker messages after persisting the unsupported
version failure. Updating the worker therefore does not automatically redeliver
those messages. Recovery requires their retained original outbox envelopes.

1. Deploy the repaired backend and restart its queue workers through the existing
   process/container supervisor. Do not run recovery against the old consumer.
2. From the deployed backend root, run the read-only inventory:

   ```sh
   php tool/replay-failed-synchronization.php --limit=100
   ```

3. Inspect the returned IDs and statuses. After review, explicitly publish the
   recoverable notifications:

   ```sh
   php tool/replay-failed-synchronization.php --limit=100 --apply
   ```

4. Allow the normal queue worker to process them, then repeat the dry run. A
   `queued` result means publication succeeded, not that consumption completed.
   Consumer success resolves the historical failure records.

The limit is 1–1000, defaults to 100, and limits distinct original message IDs.
The dry run needs the configured database but does not instantiate a broker.
The tool selects only unresolved failures with the exact old-v2-handler error.
It does not select unrelated failures or already-resolved records.

| Status | Meaning |
| --- | --- |
| `ready` | A valid original envelope is retained; nothing has been published. |
| `queued` | The original envelope was submitted using its original message ID. |
| `missing-source` | No retained outbox envelope exists; investigate retention/backups. |
| `invalid-source` | The retained envelope is incomplete or invalid; manual review is required. |
| `publish-failed` | Publication failed; the unresolved audit record is preserved. |

Re-running an interrupted apply may redeliver a notification. Its original ID,
payload, queue and occurrence time are preserved so the consumer can deduplicate
it. Do not replace the IDs, reconstruct missing household payloads, reset stock,
recommit receipts, truncate failure tables, or mark failures resolved by hand.
The script never performs those actions. Persistent blocked IDs can occupy the
bounded selection; investigate them instead of interpreting a repeated batch as
completed recovery.

Exit codes: `0` means the selected batch had no blocked entries; `1` means an
entry was blocked or configuration/transport failed; `2` means invalid arguments.
`--help` is side-effect-free. Output contains IDs and fixed statuses, not private
household representations, SQL or transport credentials.

## SQLite lock contention

Each factory-created PDO SQLite connection has a five-second busy timeout by
default, including reconnects. This bounds waiting for a lock; it is not a
promise that the write will succeed or a total HTTP-request deadline. SQLite
can reject a conflicting lock acquisition before the timeout expires.

The optional `database.sqlite_busy_timeout_seconds` configuration accepts an
integer from 0 through 30. For example, merge this database key into the existing
`config/autoload/local.php` return array:

```php
return [
    'database' => [
        'sqlite_busy_timeout_seconds' => 5,
    ],
];
```

Zero is useful for deterministic contention tests and means no lock waiting.
This option does not alter MySQL/MariaDB connection settings or SQLite journal
mode, and no database/schema migration is required.

A DBAL retryable lock/deadlock exception reaching the HTTP boundary returns
`503 application/problem+json`, a request ID and `Retry-After: 1`. The response
uses a safe description even in debug mode. Ordinary revision conflicts remain
409 and are not reclassified as transient failures.

The backend does not automatically rerun arbitrary application transactions:
those may have external effects, and a failed ORM transaction closes its entity
manager. Synchronization recovery must retain the original operation identity.
For an uncertain network outcome, query operation status before treating it as
rejected or creating any replacement operation. Retry-After is a delay hint, not
permission to duplicate a business operation.

SQLite reader locks can also reject the outer `COMMIT` after writes have
succeeded inside a transaction. The SQLite connection adapter rolls back before
propagating the original retryable error, discarding the connection only if
cleanup fails. This
keeps the native connection and DBAL transaction state aligned and avoids
mistaking uncommitted queue processing or failure-review rows for durable work.
If neither processing nor failure recording can commit, the consumer does not
acknowledge the broker message. A later delivery retains its original identity.

## Regression evidence

The standard PHP suite includes:

- `CurrentSynchronizationQueueTest`: actual writer/bus envelope construction,
  duplicate consumption, rollback, historical resolution, malformed v2 rejection
  and v1 compatibility. Its broker boundary is mocked.
- `FailedSynchronizationReplayTest`: read-only default, bounded selection,
  preserved envelope identity, missing/invalid source handling, broker failure,
  audit preservation and argument validation.
- `SqliteContentionResponseTest`: two independent connections to a real SQLite
  file, timeout/reconnect configuration, a held write lock, safe 503, rollback,
  identity-preserving retry of an illustrative transactional effect and unchanged
  revision-conflict handling. This is not a replacement for the domain receipt
  and stock regression suites.
- `SqliteCommitSafetyTest`: a real reader lock at commit time, native rollback
  and reconnection, safe retryable HTTP output, no broker acknowledgment without
  a durable record, and nested savepoint success/rollback.

Run `composer check` for formatting, static analysis, the PHP suite, architecture
and canonical contract checks. Retain the existing production-database, broker,
container and cross-client acceptance jobs; a passing isolated regression is not
by itself a production release acceptance certificate.
