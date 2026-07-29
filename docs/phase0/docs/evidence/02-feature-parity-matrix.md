# Phase 0 current-feature parity matrix

## Document status

| Field | Value |
|---|---|
| Overall status | **SOURCE-VERIFIED — CURRENT FEATURE MATRIX COMPLETE** |
| Direct source inspected | Amended Providentia V1 prompt, `app/PantryApp.tsx`, `app/globals.css`, data/rule JSON, manifest/service worker, and current build/source metadata |
| Current application source inspected | Yes; verified at commit `b01b5ef14783b4ad1c1bfc0be7ba0dba32629af8` |
| Purpose | Preserve proven behavior while distinguishing prototype behavior from production requirements |

Every current-state entry below was confirmed against the handover source.
Runtime regression fixtures remain Phase 1/Phase 5 implementation work; they do
not block this Phase 0 source inventory.

Status terms:

- **PRESERVE:** accepted user-facing behavior that the replacement must retain.
- **ADAPT:** preserve intent while implementing an approved production-grade or responsive form.
- **REPLACE:** retain the workflow goal but remove a known prototype implementation.
- **DO NOT CLAIM:** prevent an inaccurate description of current capability.

## Functional parity matrix

| ID | Area | V1-declared current behavior | Disposition | Production acceptance requirement | Current evidence |
|---|---|---|---|---|---|
| DASH-01 | Dashboard | Personal greeting | **PRESERVE** | Authenticated profile/home context supplies the greeting; no hard-coded fallback identity | **SOURCE-VERIFIED** |
| DASH-02 | Dashboard | Item total | **PRESERVE** | Total has a defined scope and is derived consistently from the active home/catalog view | **SOURCE-VERIFIED** |
| DASH-03 | Dashboard | Counted-stock total | **PRESERVE** | Total is derived from home-scoped auditable inventory projections | **SOURCE-VERIFIED** |
| DASH-04 | Dashboard | Recent-purchase total | **PRESERVE** | Total uses approved, committed home purchase lines and an explicit date window | **SOURCE-VERIFIED** |
| DASH-05 | Dashboard | Quick access to receipt capture | **ADAPT** | Opens the privacy-aware, review-before-commit receipt workflow; it must not imply that the old picker scanned a receipt | **SOURCE-VERIFIED** |
| DASH-06 | Dashboard | Quick access to stock-photo counting | **PRESERVE** | Starts a home/location-scoped count session and retains the photo locally by default | **SOURCE-VERIFIED** |
| DASH-07 | Dashboard | Recent product rows with quantities | **PRESERVE** | Rows use home-scoped balances and deterministic recent-order semantics | **SOURCE-VERIFIED** |
| DASH-08 | Dashboard | Low-stock indications on recent rows | **REPLACE** | Keep the state, but calculate it with an explainable contextual recommendation model instead of the fixed threshold | **SOURCE-VERIFIED** |
| DASH-09 | Dashboard | Navigation to low-stock shopping suggestions | **PRESERVE** | Opens the active home's suggestion view with evidence and confidence | **SOURCE-VERIFIED** |
| NAV-01 | Navigation | Four primary areas: Home, Stock, Purchases, Lists | **PRESERVE** | Each area remains directly reachable and keyboard/screen-reader operable | **SOURCE-VERIFIED** |
| STOCK-01 | Stock | Currently counted-stock view | **PRESERVE** | Shows active-home inventory balances derived from movements and closed counts | **SOURCE-VERIFIED** |
| STOCK-02 | Stock | Complete product-and-pack item-master view | **PRESERVE** | Searches global catalog plus permitted private home products without leaking other homes | **SOURCE-VERIFIED** |
| STOCK-03 | Stock | Search canonical product names | **PRESERVE** | Deterministic, normalized, accessible search with exact identity retained | **SOURCE-VERIFIED** |
| STOCK-04 | Stock | Search hidden aliases | **PRESERVE** | Approved aliases contribute to results but do not replace the displayed canonical identity | **SOURCE-VERIFIED** |
| STOCK-05 | Stock | Search brands | **PRESERVE** | Brand is used only where identity or matching requires it | **SOURCE-VERIFIED** |
| STOCK-06 | Stock | Search pack sizes | **PRESERVE** | Original pack text and normalized units are searchable; incompatible packs are not combined implicitly | **SOURCE-VERIFIED** |
| STOCK-07 | Stock | Search categories | **PRESERVE** | Search is scoped to active-home visibility and global catalog permissions | **SOURCE-VERIFIED** |
| STOCK-08 | Stock | Category filtering | **PRESERVE** | Filter state is accessible, deterministic, and compatible with search | **SOURCE-VERIFIED** |
| STOCK-09 | Stock | Manual quantity adjustment | **ADAPT** | Creates a reasoned, auditable movement or controlled reversal; direct balance mutation is prohibited | **SOURCE-VERIFIED** |
| COUNT-01 | Stock-photo count | Start and use stock-photo count sessions | **PRESERVE** | Session is scoped to home and location, supports one or more photos, and closes explicitly | **SOURCE-VERIFIED** |
| COUNT-02 | Stock-photo count | Keep the selected photo visible while counting | **PRESERVE** | Photo remains visible locally during review; privacy mode and provider are shown if AI is requested | **SOURCE-VERIFIED** |
| COUNT-03 | Stock-photo count | Show uncounted products before confirmed products | **PRESERVE** | Suggested/unconfirmed and outstanding lines sort before confirmed lines | **SOURCE-VERIFIED** |
| COUNT-04 | Stock-photo count | Mark confirmed rows as `Photo counted` | **PRESERVE** | Confirmed state is visibly and accessibly indicated without relying only on color | **SOURCE-VERIFIED** |
| COUNT-05 | Stock-photo count | Add previously uncounted item-master products to home inventory | **PRESERVE** | Explicit confirmation creates/links a home product and count line without bypassing identity rules | **SOURCE-VERIFIED** |
| COUNT-06 | Stock-photo count | Confirmed rows move below outstanding rows | **PRESERVE** | Ordering updates without losing focus or causing inaccessible motion | **SOURCE-VERIFIED** |
| PURCHASE-01 | Purchases | Recent receipt-derived purchases grouped by date and store | **PRESERVE** | Uses approved receipt data; date/store group rules are explicit and timezone-safe | **SOURCE-VERIFIED** |
| PURCHASE-02 | Purchases | Historical purchases grouped and summarized by month | **PRESERVE** | Summary derives from source purchase lines; supplied monthly rows are validation evidence only | **SOURCE-VERIFIED** |
| PURCHASE-03 | Purchases | Select receipt photo or PDF | **ADAPT** | Local preview, classification, consent, privacy/provider disclosure, structured extraction, and human review precede commitment | **SOURCE-VERIFIED** |
| PURCHASE-04 | Purchases | Recent-spend summary | **PRESERVE** | Home-private approved price data, currency, date window, and discounts/taxes have defined semantics | **SOURCE-VERIFIED** |
| PURCHASE-05 | Purchases | Receipt-group summary | **PRESERVE** | Groups committed receipts without duplicating reprocessed or synchronized data | **SOURCE-VERIFIED** |
| LIST-01 | Lists | Suggested items derived from low stock and April–June 2026 purchase history | **REPLACE** | Preserve suggestions and migration evidence; use movement-based, seasonal, confidence-aware evidence rather than permanently hard-coding this period | **SOURCE-VERIFIED** |
| LIST-02 | Lists | Manually entered list alongside suggestions | **PRESERVE** | Manual lines remain user-controlled, home-private, offline-capable, and distinguishable from suggestions | **SOURCE-VERIFIED** |
| LIST-03 | Lists | Check-off state | **PRESERVE** | State synchronizes idempotently and remains operable offline | **SOURCE-VERIFIED** |
| LIST-04 | Lists | Progress | **PRESERVE** | Progress derives from current line states and is conveyed accessibly | **SOURCE-VERIFIED** |
| LIST-05 | Lists | Suggested quantity to buy | **REPLACE** | Show editable quantity with explanation, evidence, uncertainty, and fallback behavior | **SOURCE-VERIFIED** |

## Current prototype behaviors that must be described truthfully

| ID | Current behavior or limitation | Required treatment | Status |
|---|---|---|---|
| TRUTH-01 | Receipt picker stores only a filename | **DO NOT CLAIM** upload, scanning, OCR, vision, byte retention, or durable line matching | **SOURCE-VERIFIED** |
| TRUTH-02 | Fixed low-stock rule is `quantity <= 2` | Preserve only as migration evidence; do not keep it as the final recommendation rule | **SOURCE-VERIFIED** |
| TRUTH-03 | Fixed rule marks 44 of 60 current stock lines as low | Reproduce during audit and use as evidence for replacing the rule | **SOURCE-VERIFIED: 44/60** |
| TRUTH-04 | Suggested quantity formula is `max(1, ceil(three-month purchase average - current quantity))` | Preserve in parity fixtures, then replace with explainable consumption/movement logic | **SOURCE-VERIFIED** |
| TRUTH-05 | Optional ChatGPT identity helper does not protect the app | Do not treat it as authentication | **SOURCE-VERIFIED** |
| TRUTH-06 | Private hosting access rule presently limits the live installation | Do not substitute hosting access for application authentication or authorization | **SOURCE/DOCUMENTATION-VERIFIED** |
| TRUTH-07 | Missing identity header falls back to `Roline` | Remove hard-coded identity fallback; derive identity from an authenticated session | **SOURCE-VERIFIED** |

## Visual and responsive parity

| ID | V1-declared design behavior | Disposition | Multi-platform acceptance requirement | Current evidence |
|---|---|---|---|---|
| VIS-01 | Warm cream canvas | **PRESERVE** | Design token with accessible foreground contrast | **VISUAL/SOURCE-VERIFIED** |
| VIS-02 | Dark forest green and fresh green accents | **PRESERVE** | Tokenized colors; no information conveyed only by color | **VISUAL/SOURCE-VERIFIED** |
| VIS-03 | Compact recent-item rows | **PRESERVE** | Compact without violating touch-target, text-scale, or accessibility requirements | **VISUAL/SOURCE-VERIFIED** |
| VIS-04 | Soft panel shadows | **PRESERVE** | Subtle elevation that remains legible in light/dark/high-contrast contexts | **VISUAL/SOURCE-VERIFIED** |
| VIS-05 | Rounded touch-friendly controls | **PRESERVE** | Meets platform touch-target and keyboard activation requirements | **VISUAL/SOURCE-VERIFIED** |
| VIS-06 | Clear quantity and low-stock states | **ADAPT** | Accessible text/icon explanation includes evidence and confidence where applicable | **VISUAL/SOURCE-VERIFIED** |
| VIS-07 | Bottom navigation on phone-sized layouts | **PRESERVE** | Adaptive phone navigation, including safe areas and text scaling | **VISUAL/SOURCE-VERIFIED** |
| VIS-08 | Reduced-motion support | **PRESERVE** | Honors platform/browser reduced-motion preferences | **SOURCE-VERIFIED** |
| VIS-09 | Visible keyboard focus | **PRESERVE** | Focus remains visible and logical throughout web/desktop interaction | **SOURCE-VERIFIED** |
| VIS-10 | Larger screens must not stretch the phone layout | **ADAPT** | Navigation rail/sidebar on tablet/desktop and master-detail where useful | **AMENDED V1 REQUIREMENT** |

The selected Fresh Market image was visually inspected and matches the warm
cream/green, compact-row, rounded-control, soft-shadow, low-stock, and phone
bottom-navigation requirements. Its concept counts are not the current data
baseline.

## Current limitations not to carry forward

Every row is **SOURCE-VERIFIED** from the current source, configuration, or
handover documentation.

| ID | Declared current limitation | Required production correction |
|---|---|---|
| LIM-01 | Static JSON compiled into the client | Versioned server catalog and home-scoped APIs with offline local projections |
| LIM-02 | Five browser-local storage keys for operational changes | Versioned device export, Drift local database, durable server synchronization, and cutover reconciliation |
| LIM-03 | No central operational database | Doctrine-managed MySQL/MariaDB production storage, with a clearly limited SQLite demonstration/test profile |
| LIM-04 | No server application API | Versioned, validated Mezzio PSR-15 API and published OpenAPI contract |
| LIM-05 | No real multi-user authentication in the application | Secure session/token lifecycle, password hashing, MFA readiness, revocable devices |
| LIM-06 | No home or membership model | Explicit homes, membership roles, invitations, switching, and object-level authorization |
| LIM-07 | No durable cross-device synchronization | Cursor/revision/idempotency/tombstone protocol with offline outbox and conflict rules |
| LIM-08 | No receipt OCR or vision processing | Optional provider-adapter extraction with privacy disclosure and mandatory human approval |
| LIM-09 | No central image persistence | Keep originals local by default; any later encrypted backup is opt-in, consented, and retention-controlled |
| LIM-10 | Empty database schema | Versioned Doctrine schema/migrations and a separate Drift client schema/migrations |
| LIM-11 | Null D1 and R2 bindings | Remove unused prototype assumptions; define actual database/object-storage boundaries explicitly |
| LIM-12 | Network-first service-worker shell only | Offline-first Flutter repositories, local persistence, outbox, and observable sync |
| LIM-13 | Receipt filenames without receipt bytes | Truthful legacy import; filename metadata cannot be treated as recoverable receipt media |
| LIM-14 | One shallow rendered-HTML test | Unit, integration, architecture, accessibility, platform, sync, security, and end-to-end suites |
| LIM-15 | Portrait-first PWA manifest unsuitable as the desktop contract | Adaptive Flutter contract for phone, tablet, laptop, and large desktop layouts |

## Parity verification plan

Status: **SOURCE AUDIT COMPLETE; AUTOMATED PARITY FIXTURES DEFERRED TO IMPLEMENTATION**.

Completed Phase 0 source checks:

1. Inspect `app/PantryApp.tsx` and every imported component, hook, data module, and stylesheet.
2. Locate every use of the five browser-local keys and record exact serialization, defaulting, update, and deletion behavior.
3. Run the PWA at the source commit and capture phone and desktop behavior without mutating source evidence.
4. Add any behavior missing from this prompt-derived matrix.
5. Mark differences as:
   - intended parity;
   - approved adaptation;
   - prototype behavior to replace;
   - defect not to preserve;
   - unresolved decision.
6. Create repeatable parity fixtures for the 292 item-master entries, 60 stock lines, 16 recent purchase lines, 452 historical lines, and 261 monthly rows.
7. Require acceptance tests for every **PRESERVE**, **ADAPT**, and **REPLACE** row before declaring Phase 5 parity complete.

Useful repeatable source-discovery commands:

```bash
set -euo pipefail
: "${PROVIDENTIA_PACKAGE_ROOT:?Set the verified handover package root}"

readonly PROVIDENTIA_APP_ROOT="${PROVIDENTIA_PACKAGE_ROOT}/01_app_source/vdm-pantry-stock"

rg -n --hidden \
  'pantry-(counts|receipts|stock-photos|manual-list|list-checks)|localStorage|indexedDB|quantity[[:space:]]*<=[[:space:]]*2|Roline|receipt|photo counted' \
  "${PROVIDENTIA_APP_ROOT}" \
  -g '!node_modules' -g '!dist' -g '!build'

rg -n --hidden \
  'Home|Stock|Purchases|Lists|low.stock|category|alias|pack|brand' \
  "${PROVIDENTIA_APP_ROOT}/app" \
  -g '*.tsx' -g '*.ts' -g '*.css'
```

## Parity gate

This matrix is source-certified against the handover commit. Phase 5 still
requires executable acceptance coverage for every **PRESERVE**, **ADAPT**, and
**REPLACE** row before full replacement parity may be declared.
