# Permission-bound synchronization cursors — 16 September 2026

## Implemented backend boundary

Incremental and snapshot cursors now bind their signed state to the authenticated account, device and classified readable permission set. Permission ordering, duplicate permissions and session-token rotation do not change that scope. Changing the account, device, read permissions or entity-read policy does.

Push, bootstrap, pull and operation-receipt reads recheck membership and the read scope after their store work, before releasing the response. Pull and completed bootstrap do not acknowledge a cursor when that final check detects a permission change. Both cursor codecs reject malformed signed numeric claims rather than coercing them.

Existing entity allowlisting, field redaction, immutable command receipts, tenant scoping, frozen page boundaries and paging over denied records are retained. An accepted write remains committed if a subsequent response fence rejects its now-obsolete read scope; the client must recover the immutable receipt rather than manufacture a replacement operation.

## Compatibility and recovery

The public API 2.1.0 schema and canonical digest are unchanged: cursors remain opaque strings. No database migration, household-data rewrite, dependency upgrade or workflow change is required.

A legacy unscoped cursor or a cursor for another read scope produces the existing HTTP 410 `sync_resync_required` problem. Recovery must bootstrap currently authorized data without discarding pending offline intent. Client permission-aware cache removal, durable provenance/order and recovery acceptance remain separate unfinished work in the four-batch register. This backend change alone is not a declaration that all of SYNC-03 or the three-repository release is accepted.

## Executed local validation

Source baseline: `86e36e176f6ab9ed7a2d7c658daa204a25a34bb4`. The portable runtime and locked dependencies were recovered from that commit's successful GitHub artifact and its checksums verified. Actual runtime: PHP 8.5.10, Composer 2.10.2, PHPUnit 12.5.33.

| Command | Observed result |
| --- | --- |
| `php vendor/bin/phpunit --colors=never` before repair | 614 tests, 3,643 assertions passed |
| `php vendor/bin/phpunit --colors=never tests/Unit/Synchronization` | 126 tests, 568 assertions passed |
| `php vendor/bin/phpcs` | Passed |
| `php vendor/bin/phpstan analyse --debug --no-progress --memory-limit=512M` | No errors; serial analysis of the unchanged configured source set |
| `php vendor/bin/phpunit --colors=never` after repair | 630 tests, 3,677 assertions passed |
| `php tests/Architecture/verify.php` | Dependency rules passed |
| `bash tool/materialize-openapi-contract.sh` | Pinned API 2.1.0 materialized |
| `php tests/Contract/verify-openapi.php` | Foundation contract passed |

The new regressions cover scope grants/revocations, legacy cursors, reader isolation, stable permission ordering, snapshot continuation, mid-read revocation, immutable accepted receipt projection and malformed numeric cursor claims. Authorization/service tests use controlled store doubles; they are not represented as full HTTP, MySQL/MariaDB or Flutter acceptance. New remote CI results must be associated with the published source commit, not substituted with the earlier green baseline.

## Release and rollback

Keep the existing PR and earlier repairs. Coordinate release with the client recovery/cache work. Do not reset household records, pending operations or receipts. Reverting this repair restores permission-insensitive cursor behavior; application rollback must not be mistaken for revoking already disclosed or user-saved data.
