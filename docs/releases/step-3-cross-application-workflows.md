# Step 3 cross-application workflows

Status: implementation and acceptance in progress. Do not merge or deploy.

Companions: backend #23, client #19, admin #11. Existing repair branches are reused.

## Scope
CAT-02 contribution navigation and curator-only publication; AI-03 revision-bound evidence review and ordinary receipt/count handoff; DATA-01 authenticated, requester-bound export retrieval and explicit file saving.

The optional `downloadEligible` projection is additive. A client which does not understand it is not granted a new permission; a new client treats its absence as false. No database migration is introduced: durable intake IDs use the existing receipt/count IDs and resume references use the existing account-scoped local-record boundary.

## Release and rollback
Deploy the compatible backend before the new clients only after the paired acceptance gates pass and separate deployment authorization is received. Catalog reviewers may still approve sanitized contributions but only curators may approve a proposal whose approval publishes globally. A requester must retain home export permission when retrieving their own home export. No permission grants or source-data rewrites are included.

Roll back the paired application builds together where a previous client lacks evidence controls. Do not remove backend review blocking, privacy projections, or requester authorization to support an older client. User-saved export copies remain user-owned and cannot be revoked by application logout.

## Deferred boundaries
Step 1 general outbox provenance/dependencies and permission-cache lifecycle blockers remain unchanged. Step 4 general session, reporting and pagination work is not included; limited moderation queue navigation and export revision lookup are prerequisites for these journeys.

## Evidence
Exact executed commands, commits and platform results must be recorded before completion. No paid or live provider request, production database operation, merge or deployment is authorized.
