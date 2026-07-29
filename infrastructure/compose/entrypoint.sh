#!/usr/bin/env sh
set -eu

attempt=1
until php bin/doctrine-migrations migrations:migrate --no-interaction; do
    if [ "$attempt" -ge 30 ]; then
        echo "Database migration did not become ready after 30 attempts." >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    sleep 2
done

exec "$@"

