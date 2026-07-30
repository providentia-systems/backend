# Phase 5 acceptance checklist

## Automated gates

- [ ] Composer install succeeds on the supported PHP version.
- [ ] Coding-standard, static-analysis, architecture, unit, integration, and
      contract jobs pass.
- [ ] Migrations apply on a clean MySQL database.
- [ ] OpenAPI and runtime route parity passes.
- [ ] Baseline dry-run reproduces every reconciliation gate.
- [ ] Baseline commit reproduces the 23/37 and 456/12 match splits.
- [ ] Baseline replay performs no duplicate writes.
- [ ] Receipt commit replay creates no duplicate movement.
- [ ] Balance rebuild equals the sum of movements.
- [ ] Cross-home IDs are indistinguishable from unavailable resources.
- [ ] Viewer writes are rejected; member reads remain allowed.

## Human review gates

- [ ] A PHP engineer can follow the count and receipt diagrams without reading
      infrastructure code.
- [ ] Unresolved imported rows are visible for review and are not guessed.
- [ ] Imported history is clearly distinguished from scanned receipt evidence.
- [ ] Suggestions are labelled provisional with their data limitations.
- [ ] Local setup works from a clean checkout and the verified handover.
- [ ] Staging smoke steps work behind TLS on the target server profile.
- [ ] API changes and required revisions are consumable by the client team.

The phase is stable only after the automated boxes are satisfied by CI and the
human boxes are recorded in the pull-request review.
