# Phase 7 local and remote setup

## Apply and create operator roles

After the normal development setup:

```bash
docker compose --env-file .env.development.local exec -T api-mysql \
  composer migrate

docker compose --env-file .env.development.local exec -T api-mysql \
  php bin/providentia catalog:role \
  --email=curator@example.test \
  --role=catalog_curator

docker compose --env-file .env.development.local exec -T api-mysql \
  php bin/providentia catalog:role \
  --email=reviewer@example.test \
  --role=catalog_reviewer
```

The account must already be active and email-verified. Use
`--revoke` with the same email and role to remove authority. Every grant and
revoke writes a global audit event. Role changes take effect when the server
next resolves the user's authenticated session.

## Local smoke path

Use synthetic catalog names and a disposable account:

1. submit a clean product proposal and approve it as a reviewer;
2. retrieve the new global product;
3. submit the same normalized identity and confirm it enters `conflict`;
4. keep the existing identity with the current conflict revision;
5. submit and approve a pack, alias, and barcode proposal;
6. register previously scanned content-addressed icon metadata as a curator;
7. create two disposable products, add a pack/home reference to the duplicate,
   and inspect a merge preview;
8. apply the merge and resolve the old product ID through the public read;
9. reverse the merge and verify every reference returns;
10. prove reviewer and curator sessions cannot access a home without an
    independent active membership.

Run the full quality suite:

```bash
# Run these from a host checkout with Composer development dependencies.
composer check
bash tests/structural/verify.sh
```

## Remote deployment

- Run all migrations through `Version20260824002000` before enabling the
  contribution-image routes.
- Build PHP with GD JPEG/PNG/WebP support. The source, production, and agent
  images verify `imagewebp` support; do not accept uploads on an image that
  lacks it.
- Generate a dedicated catalog-image key independently from AI media and other
  deployment keys:

  ```bash
  openssl rand -base64 32
  ```

  Store it as `CATALOG_IMAGE_KEK`, set
  `CATALOG_IMAGE_KEY_VERSION=1`, and start with
  `CATALOG_IMAGE_PREVIOUS_KEYS_JSON=[]`. Never commit or bake these values into
  an image. Uploads are capped at five MiB, 4096 pixels on either axis, and
  16,777,216 decoded pixels; the HTTP proxy and PHP request limits must remain
  at least as strict as the checked-in configuration.
- Grant roles from a protected operator shell, never through direct browser
  requests or client-supplied claims.
- Place catalog-admin routes behind normal TLS, authentication, request limits,
  rate limits, and privileged-access monitoring.
- Serve only the attribution-free, sanitized public-asset table through its
  digest-addressed endpoint. Raw uploads and encrypted quarantine rows must
  never be served as active catalog icons.
- Alert on conflict backlog, stale pending proposals, merge/reversal failures,
  role changes, and unusually high moderation volume.
- Back up merge events, relink ledgers, redirects, revisions, and audit events
  with the catalog tables, encrypted image BLOBs, and key-version inventory.
- Restore into staging and test an old redirected product ID plus one merge
  reversal before declaring the backup usable.

Do not seed production roles with shared accounts. Use named verified users,
least privilege, session revocation on departure, and periodic review of
active `user_platform_roles`.

## Catalog image key rotation

`CATALOG_IMAGE_KEK` is always the write key. Previous read keys use a closed
JSON list, for example:

```text
CATALOG_IMAGE_KEY_VERSION=2
CATALOG_IMAGE_KEK=<new-version-2-base64-key>
CATALOG_IMAGE_PREVIOUS_KEYS_JSON=[{"version":1,"kek":"<old-version-1-base64-key>"}]
```

Versions must be unique positive integers and every key must decode to exactly
32 bytes. Rotation order is deliberate:

1. add the old write key to the previous-key list and deploy the new write key
   and incremented version together;
2. verify moderator preview and public reads for synthetic assets written under
   every retained version, then verify a new upload is stored at the new
   version;
3. re-encrypt all remaining quarantine and public-asset rows under the current
   key in a separately reviewed maintenance operation;
4. prove no row references the old version before removing that read key from
   the secret manager and configuration.

This release provides the bounded read-key ring but no automatic bulk
re-encryption command. Removing an old key early intentionally fails closed
for media still using that version. Keep key escrow separate from database
backups and record rotation and restore rehearsals in the release evidence.
