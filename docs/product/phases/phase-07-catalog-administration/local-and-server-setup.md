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

- Run `Version20260730000700` before enabling catalog-admin routes.
- Grant roles from a protected operator shell, never through direct browser
  requests or client-supplied claims.
- Place catalog-admin routes behind normal TLS, authentication, request limits,
  rate limits, and privileged-access monitoring.
- Restrict the external public-asset bucket to scanned content-addressed
  objects; raw uploads must not be served as active catalog icons.
- Alert on conflict backlog, stale pending proposals, merge/reversal failures,
  role changes, and unusually high moderation volume.
- Back up merge events, relink ledgers, redirects, revisions, and audit events
  with the catalog tables.
- Restore into staging and test an old redirected product ID plus one merge
  reversal before declaring the backup usable.

Do not seed production roles with shared accounts. Use named verified users,
least privilege, session revocation on departure, and periodic review of
active `user_platform_roles`.
