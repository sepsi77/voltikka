#!/bin/bash
# Start command of the Railway services voltikka-worker and voltikka-queue
# (tasks/temporal-orchestration/). Same image as the web service, but no migrations, no volume,
# no web server, no scheduler: the web service owns migrations, so deploy the web service first
# when a release migrates.
#   docker-worker-entrypoint.sh          Temporal Python worker (voltikka-worker)
#   docker-worker-entrypoint.sh queue    Laravel queue worker (voltikka-queue)
set -e

cd /app
php artisan config:cache

if [ "${1:-temporal}" = "queue" ]; then
    # Same options as the former supervisord program in the web container. --max-time lets the
    # current job finish before the process exits; Railway (restart ALWAYS) starts it again.
    exec php artisan queue:work --queue=default,historical-interpretation --timeout=1020 --tries=3 --max-time=3600
fi

exec /opt/orchestration/.venv/bin/python -m voltikka_orchestration.worker
