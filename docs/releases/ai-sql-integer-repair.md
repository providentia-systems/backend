# AI SQL integer repair: implementation and executed evidence

## Implemented source

Production wiring is committed in `ed9da2642bd9b087bfeb0e923b3ae3a7789f909a` on the existing Backend repair branch. `DbalAiStore::one()` and `providerProfiles()` now invoke `AiSqlIntegerProjection::normalize()`. The helper and its tests were introduced in `be3c6358dc0aca1dab66b3b2c96337f98f95be3f`.

Only the documented integer columns are normalized: revision, keyVersion, estimatedCostMicros, maxAttempts, maxTotalTokens and maxEstimatedCostMicros. Native integers and canonical decimal SQL strings are accepted within the native PHP integer range and field minimums. Malformed values, booleans, floats, negatives and overflow fail closed. NULL credential keyVersion remains NULL. Identifiers, arbitrary JSON, household visibility and missing-configuration behavior are unchanged. No database migration, data rewrite, dependency upgrade, feature grant or change to authentication was made.

## Why the intermediate branch failed

The first commit contained the new regression tests and a staging patch before the store wiring. The repair workflow incorrectly ran the expected failing test under GitHub's inherited Bash `-e`; `set -uo pipefail` did not disable that inherited setting. It therefore stopped before applying the patch. Normal Quality correctly reported three failing integration tests. This was an incomplete publication sequence, not evidence that an older green run certified the complete four-batch scope.

Commit `913c401f8a5c4e47c8687c912fa778948dad03c6` explicitly captured the expected nonzero result, required exit code 1 and checked the exact assertion. It did not suppress application test failures. Run `35025245142` applied the reviewed source patch, ran the complete unchanged Composer checks, and pushed the tested store wiring. The temporary repair workflow and staging patch are removed after successful publication; the actual application source and all tests remain.

## Executed proof

Source preparation: `913c401f8a5c4e47c8687c912fa778948dad03c6`; resulting tested/published application source: `ed9da2642bd9b087bfeb0e923b3ae3a7789f909a`.

- Workflow: https://github.com/providentia-systems/backend/actions/runs/35025245142
- Job: https://github.com/providentia-systems/backend/actions/runs/35025245142/job/104570585786
- Before: real PDO SQLite with `PDO::ATTR_STRINGIFY_FETCHES=true` reproduced `Failed asserting that '3' is identical to 3` (1 test, 4 assertions, 1 expected failure).
- After: `composer check` passed, including PHPCS, PHPStan, **614 tests / 3643 assertions**, architecture checks and OpenAPI materialization/verification.
- Focused real PDO/DBAL to production JsonResponse test suite: **4 tests / 30 assertions**, passed. This also checks driver-mode parity, malformed stored revision rejection, missing configuration and unchanged private-profile visibility.
- Runtime: PHP **8.5.10**, Composer **2.10.2**, PHPUnit **12.5.33**, Ubuntu 24.04.5. The setup request was 8.5.9; the runner resolved 8.5.10. The actual version is recorded rather than silently claiming the requested patch release.
- The push was fast-forward and the runner compared its final commit to `git ls-remote`.
- Retained evidence: https://github.com/providentia-systems/backend/actions/runs/35025245142/artifacts/10419371711
- Evidence ZIP SHA-256 reported by Actions: `bdc70934f952f977be30c3e48f30ebb578855b5c90607f31863587843809c643` (90-day retention).

The normal PR Quality, database, mutation, coverage, security and HTTP conformance workflows must pass on the final cleanup head. The existing HTTP conformance lanes now pin the actual newer Client source `99dd662ad6bc461d257e71c522a5e22e7c8d540d`, not the older Step-2/3 consumers. Their results are separate from the focused SQLite driver-regression proof above. In particular, running this SQLite-specific test inside a MySQL CI job does not turn it into a stringifying-MySQL test.

## Compatibility, release order and rollback

The API 2.1.0 schema is unchanged: fields already specified as integers are consistently encoded as JSON numbers. Client and Admin parsers are not relaxed. Null/unconfigured AI remains a legitimate management state. No regenerated contract is needed for this implementation-only correction.

After full release acceptance, deploy the compatible Backend before Client/Admin releases. Do not remove production source overrides or alter household data as part of this repair. Preserve the predeployment database backup and exact build/image identifiers. If rollback is needed, redeploy the last verified compatible build without database down-migrations or deletion of outbox, audit, consent or household records. Rolling back the normalizer reintroduces the driver-dependent typing risk; rolling back the reporting server can reintroduce timestamp ambiguity for older clients. Existing privacy and authorization guards must remain intact.

This is completed source/regression work for the conditional RISK-01 slice. It is not a claim that all 16 repair items or every required cross-platform acceptance path is complete. The consolidated register remains authoritative about outstanding acceptance.
