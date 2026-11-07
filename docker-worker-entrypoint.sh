#!/bin/bash
# Start command of the Railway service voltikka-worker (tasks/temporal-orchestration/).
# Same image as the web service, but no migrations, no volume, no web server, no scheduler:
# the web service owns migrations, so deploy the web service first when a release migrates.
set -e

cd /app
php artisan config:cache

exec /opt/orchestration/.venv/bin/python -m voltikka_orchestration.worker
