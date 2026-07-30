# Phase 0 browser-device data export and cutover plan

## Document status

| Field | Value |
|---|---|
| Overall status | **SOURCE SCHEMA VERIFIED — LIVE DEVICE EXPORTS PENDING CUTOVER** |
| Browser-local keys known | 5, **SOURCE-VERIFIED** |
| Exact value schemas known | Yes; derived from `app/PantryApp.tsx` |
| Device/profile exports completed | 0 |
| Cutover authorized | No |

The handover ZIP does not contain operational changes held only in browser profiles. Retiring the PWA before collecting those changes could cause permanent data loss. No browser data may be cleared and the old PWA may not be uninstalled until every relevant profile has been exported, validated, staged, reconciled, and explicitly approved.

## Browser-local key inventory

Status: names, serialization, defaults, and mutation paths are
**SOURCE-VERIFIED**. Actual per-device values remain unavailable until the
relevant browser profiles are exported.

| Key | Verified serialized value | Export treatment | Import status |
|---|---|---|---|
| `pantry-counts` | JSON object mapping source item ID to numeric quantity | Preserve exact raw string plus parsed object; reject non-finite/negative values during staging validation | Ready for live export |
| `pantry-receipts` | JSON array of `{id, name, addedAt}`; no file bytes | Preserve exact raw value and metadata; never infer receipt media | Ready for live export |
| `pantry-stock-photos` | JSON array of `{id, name, addedAt, preview, countedItemIds}`; `preview` is a JPEG data URL | Preserve exact raw value privately; validate size/MIME; do not put previews in repository or logs | Ready for live export |
| `pantry-manual-list` | JSON array of `{id, name, done}` | Preserve order, source IDs, text, and Boolean completion state | Ready for live export |
| `pantry-list-checks` | JSON array of source suggestion/item IDs | Preserve exact IDs; reconcile only against the verified source item ID set | Ready for live export |

Important distinctions:

- An absent key is not the same as an empty string, empty array, empty object, or application default.
- A stored receipt filename is not receipt media.
- A stored photo reference is not proof that image bytes are available or durable.
- No key may be merged by array concatenation, timestamp guesswork, or name-only deduplication.
- The eight unresolved descriptions remain unresolved during device import:
  `Elbow Macaroni`, `Elbow Pasta`, `Tea`, `Candi Soda`,
  `Washing Powder - Sunlight`, `Washing Powder - Bio Classic`,
  `Insect Spray - Doom`, and `Trotters Jelly`.

## Cutover principles

1. Export from every relevant browser profile on every device that used the exact PWA origin.
2. Preserve raw local-storage strings before parsing or transforming them.
3. Record enough non-secret context to distinguish device, browser profile, origin, and export time.
4. Never collect cookies, authentication tokens, unrelated local storage, IndexedDB databases, history, or saved credentials as part of this five-key export.
5. Keep each profile export immutable and hash it.
6. Apply the verified schema, defaulting, ID generation, updates, and
   cross-key relationships documented here.
7. Transform only into a versioned staging import format with source-row/value lineage.
8. Reconcile device changes with the verified ZIP baseline under the documented source precedence.
9. Obtain explicit user approval before production import or PWA retirement.
10. Preserve rollback access to the old PWA and immutable exports until final acceptance and backup verification.

## Profile discovery register

Create one row per potential source profile before exporting:

| Field | Required value |
|---|---|
| `device_label` | User-recognizable device name; do not collect a hardware serial |
| `operating_system` | OS and relevant version |
| `browser` | Browser and major version |
| `profile_label` | User-recognizable browser profile |
| `pwa_origin` | Exact scheme, host, and port used by the PWA |
| `last_known_use` | User-supplied approximate date/time if known |
| `export_attempted_at` | Timestamp |
| `export_file_sha256` | Hash after validation |
| `key_presence` | Present/absent state for all five keys |
| `staging_import_run_id` | Assigned after staging |
| `user_approved` | Explicit yes/no with timestamp |

Private/incognito profiles may have been cleared automatically. Record them as unavailable rather than reconstructing or guessing their contents.

## Ready-to-run browser export

Status: **SOURCE-ALIGNED AND SELF-CONTAINED; LIVE PROFILE TEST STILL REQUIRED BEFORE PRODUCTION CUTOVER**.

Open the current PWA at its normal origin in each relevant browser profile, open the browser developer console, and run the entire script below. It reads only the five declared keys, preserves each raw string exactly, adds a parsed copy only when valid JSON, calculates a SHA-256 over the export payload, and downloads a JSON file. It does not modify or remove browser data.

```javascript
(async () => {
  'use strict';

  const schema = 'providentia.legacy-local-storage-export';
  const schemaVersion = 1;
  const keyNames = Object.freeze([
    'pantry-counts',
    'pantry-receipts',
    'pantry-stock-photos',
    'pantry-manual-list',
    'pantry-list-checks',
  ]);

  const keys = Object.fromEntries(
    keyNames.map((key) => {
      const raw = window.localStorage.getItem(key);
      let parsed = null;
      let jsonValid = false;

      if (raw !== null) {
        try {
          parsed = JSON.parse(raw);
          jsonValid = true;
        } catch {
          parsed = null;
        }
      }

      return [
        key,
        {
          present: raw !== null,
          raw,
          jsonValid,
          parsed,
        },
      ];
    }),
  );

  const payload = {
    schema,
    schemaVersion,
    exportedAt: new Date().toISOString(),
    origin: window.location.origin,
    userAgent: window.navigator.userAgent,
    keys,
  };

  const canonicalPayload = JSON.stringify(payload);
  const digestBytes = await window.crypto.subtle.digest(
    'SHA-256',
    new TextEncoder().encode(canonicalPayload),
  );
  const payloadSha256 = Array.from(new Uint8Array(digestBytes))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');

  const envelope = {
    ...payload,
    payloadSha256,
  };

  const safeTimestamp = payload.exportedAt.replace(/[:.]/g, '-');
  const filename = `providentia-device-export-${safeTimestamp}.json`;
  const blob = new Blob(
    [`${JSON.stringify(envelope, null, 2)}\n`],
    { type: 'application/json;charset=utf-8' },
  );
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');

  try {
    anchor.href = url;
    anchor.download = filename;
    anchor.style.display = 'none';
    document.body.appendChild(anchor);
    anchor.click();
  } finally {
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1_000);
  }

  const presence = Object.fromEntries(
    keyNames.map((key) => [key, keys[key].present]),
  );
  console.info('Providentia legacy export created', {
    filename,
    payloadSha256,
    presence,
  });
})();
```

Security note: the downloaded JSON contains private household data. Store it as sensitive source evidence, do not attach it to issues or CI, do not commit it, and do not paste its content into chat.

## Export validation

Run this locally in the directory containing one newly downloaded export. The procedure requires exactly one export file in that directory, validates the schema, all five exact keys, raw/parsed consistency, and the embedded payload digest, then creates a separate whole-file SHA-256 record.

```bash
set -euo pipefail

mapfile -d '' PROVIDENTIA_DEVICE_EXPORTS < <(
  find . -maxdepth 1 -type f \
    -name 'providentia-device-export-*.json' -print0
)

if (( ${#PROVIDENTIA_DEVICE_EXPORTS[@]} != 1 )); then
  printf 'Expected exactly one device export in this directory; found %d\n' \
    "${#PROVIDENTIA_DEVICE_EXPORTS[@]}" >&2
  exit 1
fi

readonly PROVIDENTIA_DEVICE_EXPORT="${PROVIDENTIA_DEVICE_EXPORTS[0]}"
export PROVIDENTIA_DEVICE_EXPORT

node <<'NODE'
const fs = require('node:fs');
const crypto = require('node:crypto');

const file = process.env.PROVIDENTIA_DEVICE_EXPORT;
const envelope = JSON.parse(fs.readFileSync(file, 'utf8'));
const expectedKeys = [
  'pantry-counts',
  'pantry-receipts',
  'pantry-stock-photos',
  'pantry-manual-list',
  'pantry-list-checks',
];

if (envelope.schema !== 'providentia.legacy-local-storage-export') {
  throw new Error(`Unexpected schema: ${String(envelope.schema)}`);
}
if (envelope.schemaVersion !== 1) {
  throw new Error(`Unexpected schemaVersion: ${String(envelope.schemaVersion)}`);
}
if (typeof envelope.origin !== 'string' || envelope.origin.length === 0) {
  throw new Error('Missing origin');
}
if (Number.isNaN(Date.parse(envelope.exportedAt))) {
  throw new Error('Invalid exportedAt');
}
if (!envelope.keys || typeof envelope.keys !== 'object') {
  throw new Error('Missing keys object');
}

const actualKeys = Object.keys(envelope.keys).sort();
if (JSON.stringify(actualKeys) !== JSON.stringify([...expectedKeys].sort())) {
  throw new Error(`Unexpected key set: ${actualKeys.join(', ')}`);
}

for (const key of expectedKeys) {
  const entry = envelope.keys[key];
  if (!entry || typeof entry.present !== 'boolean') {
    throw new Error(`${key}: invalid entry`);
  }
  if (!entry.present && entry.raw !== null) {
    throw new Error(`${key}: absent entry must have raw=null`);
  }
  if (entry.present && typeof entry.raw !== 'string') {
    throw new Error(`${key}: present entry must preserve raw string`);
  }
  if (entry.present && entry.jsonValid) {
    const reparsed = JSON.parse(entry.raw);
    if (JSON.stringify(reparsed) !== JSON.stringify(entry.parsed)) {
      throw new Error(`${key}: parsed copy differs from raw JSON`);
    }
  }
}

const { payloadSha256, ...payload } = envelope;
const actualDigest = crypto
  .createHash('sha256')
  .update(JSON.stringify(payload), 'utf8')
  .digest('hex');

if (actualDigest !== payloadSha256) {
  throw new Error(
    `Payload digest mismatch: expected ${payloadSha256}, got ${actualDigest}`,
  );
}

console.log(JSON.stringify({
  status: 'valid',
  schema: envelope.schema,
  schemaVersion: envelope.schemaVersion,
  exportedAt: envelope.exportedAt,
  origin: envelope.origin,
  payloadSha256,
  keyPresence: Object.fromEntries(
    expectedKeys.map((key) => [key, envelope.keys[key].present]),
  ),
}, null, 2));
NODE

sha256sum -- "${PROVIDENTIA_DEVICE_EXPORT}" \
  > "${PROVIDENTIA_DEVICE_EXPORT}.sha256"
sha256sum --check "${PROVIDENTIA_DEVICE_EXPORT}.sha256"
```

The validator intentionally reports presence and hashes, not private values.

## Schema discovery after ZIP recovery

Status: **BLOCKED**.

After package checksum verification, locate every read, write, default, and deletion path:

```bash
set -euo pipefail
: "${PROVIDENTIA_PACKAGE_ROOT:?Set the verified handover package root}"

readonly PROVIDENTIA_APP_ROOT="${PROVIDENTIA_PACKAGE_ROOT}/01_app_source/vdm-pantry-stock"

rg -n --hidden \
  'pantry-(counts|receipts|stock-photos|manual-list|list-checks)|localStorage\.(getItem|setItem|removeItem)|localStorage\[' \
  "${PROVIDENTIA_APP_ROOT}" \
  -g '!node_modules' -g '!dist' -g '!build'
```

For each key, document:

- exact value schema and schema drift across commits;
- default value and absent-key behavior;
- identifier format and cross-key references;
- date/time and timezone encoding;
- numeric, currency, unit, and pack semantics;
- update and delete behavior;
- whether data is a full snapshot, partial override, append-only list, or cache;
- whether filename/path values correspond to any recoverable bytes;
- whether the service worker or another storage system affects durability.

These semantics were verified from the source and are reflected in the exact
five-key schema table above. A device importer still requires representative
live exports, malformed-value fixtures, and staging reconciliation before
production use.

## Staging import specification

Status: **PROPOSED; IMPLEMENTATION DEFERRED**.

Each export is imported as an immutable source object with:

- whole-file and embedded payload SHA-256;
- source origin;
- device/profile register reference;
- export timestamp;
- exact raw string per key;
- parser/schema version;
- source item index or stable legacy ID;
- transformation decision;
- target entity and ID;
- conflict/quarantine reason.

Required order:

1. Register and validate the immutable export.
2. Parse without discarding the raw value.
3. Validate every record against the source-derived key schema.
4. Dry-run into a dedicated staging home.
5. Import product references through the canonical identity rules.
6. Quarantine the eight declared unresolved descriptions.
7. Reconcile counts and list state without direct balance mutation.
8. Treat receipt filenames as metadata only unless separately verified media bytes exist.
9. Detect duplicate events across profiles using source IDs and domain fingerprints, never name alone.
10. Emit JSON and human-readable reconciliation reports.

The importer must be idempotent, resumable, transaction-safe, conflict-explicit, and capable of mapping every source value/record to its destination or quarantine entry.

## Reconciliation procedure

The staging report must compare:

- verified handover baseline counts;
- each device/profile export independently;
- duplicate and conflicting records across exports;
- the combined proposed target state;
- source record/value to target ID;
- stock movements and resulting balances;
- manual-list lines and checks;
- receipt metadata and whether bytes are unavailable;
- stock-photo references and whether bytes are unavailable;
- every unresolved or invalid value.

The V1 baseline values below are **DIRECTLY VERIFIED** from the structured
handover exports:

| Measure | V1 baseline |
|---|---:|
| Product-and-pack entries | 292 |
| Current stock lines | 60 |
| Current units or packs | 159 |
| Recent receipt-derived lines | 16 |
| Historical shopping lines | 452 |
| Monthly summary rows | 261 |
| Alias groups | 13 |
| Individual aliases | 19 |
| Identity rules | 19 |
| Unresolved descriptions | 8 |

Device exports may legitimately add, update, or remove operational state relative to the ZIP baseline. Every difference must identify the source profile and transformation rule; none is automatically an error, and none may be silently accepted.

## Cutover runbook and gates

### 1. Discovery gate

- Record every device/browser/profile/origin that may hold data.
- Confirm users have finished making changes or establish a short, communicated write freeze.
- Keep the old PWA available.

### 2. Export gate

- Run the five-key export in every relevant profile.
- Validate embedded and whole-file digests.
- Store immutable exports securely.
- Confirm absent keys explicitly.

### 3. Source-understanding gate

- Handover ZIP checksums verified.
- Schemas and semantics derived from current source.
- V1 baseline counts reproduced.
- Resolve no ambiguous identity without evidence/decision.

### 4. Staging gate

- Dry-run every export.
- Import into a staging home.
- Run idempotency by importing the same inputs again and proving no duplicate domain effects.
- Review conflicts, quarantines, unavailable media references, and unresolved descriptions.

### 5. User-acceptance gate

- Present a human-readable before/after reconciliation.
- Let the user inspect stock, purchases, manual list, check state, and photo/receipt metadata.
- Obtain explicit approval for all differences.

### 6. Production cutover gate

- Back up the target database and staging reports.
- Run the exact approved import inputs and digests.
- Validate counts, balances, memberships, and tenant isolation.
- Keep old PWA data unchanged during the rollback window.

### 7. Retirement gate

Only after explicit final acceptance, verified target backup/restore, and expiry of the agreed rollback window may the old PWA be uninstalled or browser data cleared. Record what was removed, from which profile, when, by whom, and whether a recoverable encrypted export remains.

## Rollback

Before acceptance, rollback means discarding or reversing the staging/target import through the import run's controlled records while leaving original browser data and immutable exports untouched. Stock effects must be reversed through auditable movements or the entire unaccepted staging home may be discarded; history must not be manually edited into a superficially matching balance.

## Known limitations

- The five source schemas are verified; actual per-profile values are not yet exported.
- No live browser profile was accessed.
- The console exporter is aligned with the verified app source but still needs a live-profile dry run.
- Receipt filenames have no bytes; stock-photo data URLs may still be absent or
  damaged in individual profiles and must be validated during export.
- Multi-profile conflicts require actual export timestamps/IDs even though the
  source mutation semantics are now known.
- Production cutover remains gated on device exports and approval of their
  reconciliation; this does not block Phase 1 foundations.
