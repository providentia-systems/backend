# Household product workflows

This guide describes backend source behavior and the coordinated frontend workflows; it does not establish which features an installed Client or Admin provides. Verify the installed revisions and canonical contract using the [synchronization diagnostics and recovery guidance](deployment/synchronization-recovery.md). Matching backend, Client and Admin builds are required.

## Household editing and identity

The Client product editor maintains private names, pack descriptions, stock units and categories. Linked catalog products accept household-only name/pack overrides; null resets to the canonical value. A purely private product must retain a nonempty name. Product IDs, catalog/pack links, stock balances, count and receipt associations, movement history, permissions and revision checks are preserved. Edits use the existing optimistic local projection and durable protocol-v2 outbox; they are not direct UI-only API writes.

Effective item-master and operator-stock responses expose household values. Item-master responses also carry independent catalogName, catalogPackText, catalogCategoryId and catalogCategoryName values. The Client caches those canonical bases separately, including family-linked products with no selected pack. A household override must never contaminate the public pack cache.

## Admin household product columns

Homes → selected home → Products now resolves household names and pack descriptions before falling back to the linked catalog product and pack. The operator records endpoint adds read-only name, brand, pack_text, category_name, category_scope and catalog_reference columns within its existing PlatformRecordPage envelope. Original private_name, original_pack_text, IDs, status and revision are retained unchanged for editing and diagnostics. No contract regeneration, additional migration or data rewrite is needed for this read/display correction.

Admin places Product, Brand, Pack and Category first. Empty private overrides are labelled No private override instead of being mistaken for missing effective names. Private products, uncounted products, family-only catalog links and archived catalog identities remain visible. Missing or mismatched catalog references are labelled explicitly rather than guessed or repaired. Local category joins remain bound to the selected home; another home's category name cannot be returned. Existing operator authorization, audit recording and stable 100-row pagination remain in place.

## Categories and stock units

globalCategoryId and homeCategoryId represent distinct namespaces. Only one can be selected. Selecting a nonnull category clears the other scope. A new global selection must be published; an existing retired selection may be retained while editing unrelated metadata. The Client loads the complete paged published category list independently of household products and local categories. Both product forms display Global and Local labels. Local categories stay household-private; selecting a global category does not publish a private product or create a local copy. Global publication and moderation remain separate existing workflows.

Supported stock-unit labels are `units`, `g`, `kg`, `ml` and `l`. A different
unit is accepted only while the product has no nonzero balance or dependent
evidence. The backend rejects a change with HTTP 409, `Stock unit has history`,
when there are stock movements, nonremoved count lines, saved shopping-list
quantities, or current/historical minimum or always-keep preferences. A zero
balance alone does not establish that the unit is unused: previous movements
still block relabeling. Sending the existing unit while editing other metadata
does not trigger this unit-change rejection. These rules apply to household
and operator writes; a frontend warning cannot override them.

| Example | Backend result |
| --- | --- |
| New unused product, no balance/evidence, change `units` to `kg` | Accepted with the current revision |
| Recorded zero balance with no other dependent evidence, change `g` to `kg` | Accepted; no quantities need reinterpretation |
| Balance `2.5`, change `kg` to `g` | Rejected; the API does not multiply the balance by 1000 |
| Balance returned to zero after purchases or counts, change `kg` to `units` | Rejected; historical movements/counts retain their captured unit |
| No stock but a saved shopping line or minimum preference, change `l` to `ml` | Rejected; dependent quantities keep their existing meaning |

For a product whose unit is already in use, keep its existing identity and
history and create a separate correctly measured product for future use. There
is no generic in-place unit conversion endpoint. Review outstanding purchases,
counts, shopping lines and preferences before changing how future stock is
tracked; do not delete history, clear preferences or fabricate a zero count to
bypass the guard. Correct an erroneous count or purchase through its existing
audited workflow, retaining the original stock unit and captured pack factor.
Catalog pack measurements and their integrity rules remain unchanged. The
paired Client guard and explanatory UI are tracked in
[Client #35](https://github.com/providentia-systems/client/issues/35); verify
their availability in the installed build.

## Counting and layout

The Client quantity dialog contains only the observed quantity, Cancel and Save. It has no reason dropdown, reason text field or Other explanation. Recording a new quantity and changing an existing quantity require no reason input. The existing audited adjustment command automatically records Stock count correction; its required backend reason field and historical records are not removed. Active manual counts remain reason-free. Invalid, nonfinite and negative quantities produce visible validation without writing stock; zero and fractional quantities remain supported. Cancelling does not write a movement.

Product and quantity forms retain explicit field spacing and scrolling with narrow screens, larger text and an open keyboard. Admin's privileged audit-reason controls are unchanged; removing the household quantity prompt does not remove operator accountability.

## Synchronization and deployment

Complete category and item-master snapshots are cached atomically. Malformed, duplicate or failed pages do not replace the previous complete cache. Public category endpoint failures do not prove loss of home membership. Existing operation IDs, queue barriers and originating-account safeguards remain unchanged. This feature does not repair or erase historical queued operations.

Both clients regenerate bindings from the exact backend contract artifact,
never manual generated-code edits. Compare each installed frontend's contract
lock with the actual loaded backend contract digest reported by authenticated
diagnostics; an older guide's version label or digest is not deployment proof.

Deploy the backend first and run the normal migration command, `php bin/doctrine-migrations migrations:migrate --no-interaction`. Migration Version20260922000100 adds nullable global_category_id and unit defaulting to units. It neither rewrites stock quantities nor deletes history. Preserve recoverable database/application backups; do not reset existing data. Then rebuild/install the matching Client and Admin against the intended API origin. A merged source branch alone does not update an installed executable.

Existing quality, coverage, mutation, database/broker, contract, security and native build/package checks remain required. Final PR-head checks are build evidence; production acceptance separately requires matching installed versions, readable Admin product columns, category selection with zero local categories, metadata edits that survive sync/restart and another device, and quantity-only entry/layout checks. No production migration, deployment or merge is performed by these PRs.

## Purchases and receipts

See [Purchase entry and corrections](purchase-entry-and-corrections.md) for immediate confirmed manual additions, exact pack quantities, offline stale-measure checks, reviewed imports and reversal-based corrections.
