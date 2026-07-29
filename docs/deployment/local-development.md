# Local development and deployment profiles

## Server-side SQLite

SQLite is the zero-configuration server demonstration and automated-test
profile. It is not the production high-volume database.

```bash
docker compose --profile sqlite --profile valkey up --build --wait
```

The SQLite API starts even when the optional queue is unavailable because
`QUEUE_REQUIRED=0`. A worker requires an active broker.

## MySQL plus Redis

```bash
docker compose --profile mysql --profile redis up --build --wait
docker compose run --rm api-mysql php bin/providentia outbox:relay
docker compose run --rm api-mysql php bin/providentia queue:consume
```

## MariaDB plus Valkey

```bash
docker compose --profile mariadb --profile valkey up --build --wait
docker compose run --rm api-mariadb php bin/providentia outbox:relay
docker compose run --rm api-mariadb php bin/providentia queue:consume
```

The database and queue services have no host port publication. For remote
production databases, use TLS and private networking, supply a backend-only
`DATABASE_URL`, and do not include it in Flutter configuration.

The bundled database passwords are known local-development defaults. Override
all password variables before using a shared host. The production deployment
profile remains a user decision; the code supports external and bundled
database paths without selecting one.

