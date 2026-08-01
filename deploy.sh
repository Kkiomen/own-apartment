#!/usr/bin/env bash
#
# One command to stand the app up on a fresh VPS, and the same command to
# update it later. Safe to re-run: it never overwrites an existing .env.docker
# and never regenerates an APP_KEY that already exists.
#
#   ./deploy.sh
#
set -euo pipefail

cd "$(dirname "$0")"

ENV_FILE=.env.docker
COMPOSE="docker compose --env-file ${ENV_FILE}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose is not available. Install Docker Engine with the compose plugin." >&2
    exit 1
fi

FIRST_RUN=0

if [ ! -f "${ENV_FILE}" ]; then
    say "Creating ${ENV_FILE} from the template"
    cp .env.docker.example "${ENV_FILE}"
    FIRST_RUN=1
fi

# Only ever on the very first run. Postgres bakes the password into its data
# directory when the volume is initialised, so rotating it here on a later
# deploy would lock the app out of its own database.
if [ "${FIRST_RUN}" = "1" ]; then
    say "Generating a database password"
    PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 24)
    sed "s|^DB_PASSWORD=.*|DB_PASSWORD=${PASSWORD}|" "${ENV_FILE}" > "${ENV_FILE}.tmp"
    mv "${ENV_FILE}.tmp" "${ENV_FILE}"
fi

# APP_KEY has to exist before anything boots — the entrypoint refuses to start
# without one, deliberately, so sessions do not silently reset on every deploy.
if ! grep -q '^APP_KEY=base64:' "${ENV_FILE}"; then
    say "Generating APP_KEY"
    KEY=$(${COMPOSE} run --rm --no-deps -e CONTAINER_ROLE=cli app php artisan key:generate --show)
    # BSD/GNU sed differ over -i, so write through a temp file instead.
    sed "s|^APP_KEY=.*|APP_KEY=${KEY}|" "${ENV_FILE}" > "${ENV_FILE}.tmp"
    mv "${ENV_FILE}.tmp" "${ENV_FILE}"
fi

say "Building images"
${COMPOSE} build

say "Starting the stack"
# Migrations run as a one-shot service; the rest waits for them. The `seed`
# service fills the app on a first deployment and skips itself afterwards.
${COMPOSE} up -d

say "Status"
${COMPOSE} ps

PORT=$(grep '^APP_PORT=' "${ENV_FILE}" | cut -d= -f2)

cat <<EOF

Done. The app is on http://<this-server>:${PORT:-8090}

  Watch the first scan:   ${COMPOSE} logs -f seed
  Watch the scheduler:    ${COMPOSE} logs -f scheduler
  See what would notify:  ${COMPOSE} exec app tail -f storage/logs/laravel.log

The first scan walks five portals and takes roughly fifteen minutes. Until it
finishes the list will be partly filled; the hourly scheduler keeps it current
from then on.
EOF
