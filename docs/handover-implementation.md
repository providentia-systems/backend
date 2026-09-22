# Historical handover subset: synchronization and catalog search

This record describes backend PR #24 and its contemporaneous contract/checks. It is retained as historical evidence, not the current feature-completion list. For the subsequent household metadata implementation and API 2.2.0 deployment requirements, read [Household workflows](household-workflows.md). Historical queue recovery and separate publication/moderation work remain distinct.

# Handover implementation record — 22 September 2026

## Scope

This change implements the synchronization failure-classification and catalog
maintenance-search portions of the supplied full handover. It is not completion
of every handover workstream or a production recovery. The initial backend main
commit was 544b789a44f64c50ed5274e46c8050fdee881180.

## Synchronization safety

Machine-readable problem types distinguish device mismatch, verified home-access
denial and command permission denial. Operation results retain bounded stable
codes. A missing referenced resource is not treated as revoked home membership.
Authentication remains request-level 401, transient service failures remain
retryable and sanitized, and immutable operation receipt bindings are unchanged.
Only the actual synchronization membership guard emits the verified home-access
type. Both supported command protocols have regression coverage.

The paired Client change must preserve these distinctions. Generic 403/404
responses from old servers or wrong endpoints do not prove permission revocation
and must not cause pending intent to be deleted or reassigned.

## Catalog maintenance search

The existing catalog entity list accepts optional q, limited to 191 characters.
Literal, case-insensitive matching occurs before the 100-row offset pagination.
Bound parameters and escaped wildcard characters prevent query text from
becoming SQL. Existing authorization, product scoping and private/global alias
boundaries are retained. Regression fixtures include 205 identities, multiple
pages, a private-scope control, malformed columns and overlong searches.

The backend-owned OpenAPI document is mirrored by Admin and Client. JSON SHA-256:
13ccdc2d37e73955394a7b7c52da6d9ff7aeefdfd763ac809876737867d15c44.
Deploy this backend before relying on the new Admin server-side search.

## Verification and delivery

Branch validation run 35718554343 passed composer check and the unchanged
coverage gate for source b76f4befa4ed9ac29bc1b00545b8a17fa881a287.
Temporary review/edit workflows and scripts are removed from the final tree.
Original PR database, integration, packaging and security checks must pass on
the final head. Prior branch results are not evidence for untested later code.

## Outstanding work and owner data

Historical queue recovery, proof of the originating account, ambiguous receipt
reconciliation, the full diagnostics export, additional relationship repair,
effective household product projections, household override editing, unified
category scope and publication, household name/measurement customization, and
the remaining contextual reason surfaces are not claimed complete here.

No production database, server deployment or installed native executable was
modified. No queue was cleared, silently rebound or replayed. A missing receipt
is not proof of nonexecution. Preserve a backup of the real native database and
require verified provenance or explicit owner review for historical work.
