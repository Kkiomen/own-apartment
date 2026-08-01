#!/bin/sh
set -e

: "${CONTAINER_ROLE:=app}"
: "${DB_HOST:=postgres}"
: "${DB_PORT:=5432}"

# Escape hatch for one-off commands that must run before the stack is usable
# (generating APP_KEY, inspecting the image): no key check, no waiting on the DB.
if [ "${CONTAINER_ROLE}" = "cli" ]; then
    exec "$@"
fi

if [ -z "${APP_KEY}" ]; then
    echo "APP_KEY is empty. Generate one with:" >&2
    echo "  docker compose run --rm --no-deps -e CONTAINER_ROLE=cli app php artisan key:generate --show" >&2
    echo "and put it in .env.docker before starting the stack." >&2
    exit 1
fi

echo "[entrypoint] role=${CONTAINER_ROLE}, waiting for ${DB_HOST}:${DB_PORT}"
until pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" >/dev/null 2>&1; do
    sleep 2
done

# Migrations are NOT run here — the one-shot `migrate` service owns them, so
# app/queue/scheduler can never race each other on the schema.
if [ "${CONTAINER_ROLE}" != "migrate" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
