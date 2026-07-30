# Phase 5 local and server setup

## Local verified setup

Prerequisites are Docker with Compose, `unzip`, `sha256sum`, `curl`, `jq`, and
`openssl`. Keep the handover ZIP outside version control.

```bash
./scripts/setup-development.sh \
  --handover /absolute/path/Pantry_Stock_Project_Handover_2026-07-29.zip
```

The script:

1. extracts only the authoritative JSON exports;
2. verifies their exact SHA-256 values;
3. generates mode-0600 local secrets;
4. builds and starts MySQL, Redis, Mailpit, and the API;
5. runs migrations and catalog seed dry-run/commit/replay checks;
6. creates or reuses the development account and home;
7. runs baseline dry-run/commit/replay checks;
8. writes the loopback-only `.providentia-development.json` client handoff.

Use `./scripts/reset-development.sh` to reset the development stack. Treat that
as destructive to local containers and volumes.

Useful checks:

```bash
docker compose --env-file .env.development.local ps
curl --fail http://127.0.0.1:8080/health/ready
docker compose --env-file .env.development.local exec -T api-mysql \
  composer quality
```

## Remote server deployment

Use the MySQL production profile described in
[`docs/deployment/local-development.md`](../../../deployment/local-development.md)
as the baseline, then add a TLS reverse proxy and an external secret manager.

Required production controls:

- `APP_ENV=production` and `APP_DEBUG=0`
- strong, independently generated database, token-pepper, and cursor secrets
- secrets injected at runtime, never committed or baked into an image
- TLS termination with trusted forwarded-header configuration
- no published MySQL, Redis, or metrics port
- authenticated or network-restricted metrics collection
- persistent MySQL backups with a tested restore procedure
- queue worker supervision and failed-message review
- readiness checks during rolling deployment
- migrations executed once before new application replicas receive traffic

Before deployment, run the complete quality and migration jobs in CI. On a
staging database, execute migration up, smoke the Phase 5 flows, execute
rollback only when the release policy permits it, then re-apply and compare
ledger/balance reconciliation. Do not run the household baseline importer
against a production home unless the exact Phase 0 files, target home, and
owner/manager actor have been independently confirmed.

## Post-deploy smoke path

1. `GET /health/live` and `GET /health/ready`
2. authenticate and create a disposable staging home
3. create a private home product
4. create and close a count session
5. create, review, and commit one receipt
6. confirm one inbound movement and the new balance
7. rebuild the disposable home's balances and compare the result
8. create a shopping list, add a line with its current revision, and check it

Delete or archive only disposable staging data according to the environment's
retention policy; never use production household data for smoke testing.
