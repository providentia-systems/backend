# Household product workflows — API 2.2.0

This guide describes the supported source implementation, not an inspected production deployment. Backend #25, household Client #21 and the paired Admin contract update must use matching builds.

## Household editing and identity

The Client product editor maintains private names, pack descriptions, stock units and categories. Linked catalog products accept household-only name/pack overrides; null resets to the canonical value. A purely private product must retain a nonempty name. Product IDs, catalog/pack links, stock balances, count and receipt associations, movement history, permissions and revision checks are preserved. Edits use the existing optimistic local projection and durable protocol-v2 outbox; they are not direct UI-only API writes.

Effective item-master and operator-stock responses expose household values. Item-master responses also carry independent catalogName, catalogPackText, catalogCategoryId and catalogCategoryName values. The Client caches those canonical bases separately, including family-linked products with no selected pack. A household override must never contaminate the public pack cache.

## Categories and stock units

globalCategoryId and homeCategoryId represent distinct namespaces. Only one can be selected. Selecting a nonnull category clears the other scope. A new global selection must be published; an existing retired selection may be retained while editing unrelated metadata. The Client loads the complete paged published category list independently of household products and local categories. Both product forms display Global and Local labels. Local categories stay household-private; selecting a global category does not publish a private product or create a local copy. Global publication and moderation remain separate existing workflows.

Supported stock-unit labels are units, g, kg, ml and l. A unit correction deliberately preserves existing numeric balances and history; it is not a conversion operation. The Client warns about this before saving. Catalog pack measurements and their integrity rules are unchanged.

## Counting and layout

Quantity adjustments use a reason dropdown defaulting to Stock count correction. Other requires an explanation; ordinary choices do not require typing. Active manual counts remain reason-free. Invalid/nonfinite/negative quantities produce visible validation. Product and quantity forms have explicit field spacing and scroll with narrow screens, larger text and an open keyboard.

## Synchronization and deployment

Complete category and item-master snapshots are cached atomically. Malformed, duplicate or failed pages do not replace the previous complete cache. Public category endpoint failures do not prove loss of home membership. Existing operation IDs, queue barriers and originating-account safeguards remain unchanged. This feature does not repair or erase historical queued operations.

The canonical JSON SHA-256 is `ef5714a6298326d6fb449b966117e8b61c74de67d1bfc274ad8ec431aecd802d`. Both clients regenerate bindings from the exact backend artifact, never manual generated-code edits.

Deploy the backend first and run the normal migration command, `php bin/doctrine-migrations migrations:migrate --no-interaction`. Migration Version20260922000100 adds nullable global_category_id and unit defaulting to units. It neither rewrites stock quantities nor deletes history. Preserve recoverable database/application backups; do not reset existing data. Then rebuild/install the matching Client and Admin against the intended API origin. A merged source branch alone does not update an installed executable.

Existing quality, coverage, mutation, database/broker, contract, security and native build/package checks remain required. Final PR-head checks are build evidence; production acceptance separately requires matching installed versions, category selection with zero local categories, metadata edits that survive sync/restart and another device, and quantity-reason/layout checks. No production migration, deployment or merge is performed by these PRs.
