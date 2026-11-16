#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
readonly LARAVEL_DIR="$ROOT_DIR/laravel"
readonly DATABASE_DIR="$LARAVEL_DIR/database"
readonly LOCAL_DATABASE="$DATABASE_DIR/database.sqlite"

# Production MySQL is the Kamal accessory voltikka-db on the VPS. It is not published on the
# host, so the script tunnels to its address on the Docker network kamal. The database and user
# names are the clear DB_DATABASE and DB_USERNAME values in config/deploy.yml; the password is the
# DB_PASSWORD secret in the Kamal web role env file on the server.
readonly SSH_HOST="breezily-vps"
readonly DB_CONTAINER="voltikka-db"
readonly DB_NAME="voltikka"
readonly DB_USER="voltikka"
readonly REMOTE_ENV_FILE='~/.kamal/apps/voltikka/env/roles/web.env'
readonly TUNNEL_WAIT_SECONDS=20

ASSUME_YES=0
TEMP_DATABASE=''
CONFIG_CACHE=''
BACKUP_PATH=''
TUNNEL_PID=''

usage() {
    cat <<'EOF'
Usage: scripts/sync-production-database.sh [--yes]

Build a fresh local SQLite database from public production application data.
The script reads production MySQL on the VPS through an SSH tunnel, in a read-only
transaction. It does not write to production.

  --yes   Do not ask for confirmation.
  --help  Show this help.
EOF
}

fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

close_tunnel() {
    if [[ -n "$TUNNEL_PID" ]]; then
        kill "$TUNNEL_PID" 2>/dev/null || true
        wait "$TUNNEL_PID" 2>/dev/null || true
        TUNNEL_PID=''
    fi
}

cleanup() {
    close_tunnel

    if [[ -n "$TEMP_DATABASE" ]]; then
        rm -f -- "$TEMP_DATABASE" "$TEMP_DATABASE-wal" "$TEMP_DATABASE-shm" "$TEMP_DATABASE-journal"
    fi

    if [[ -n "$CONFIG_CACHE" ]]; then
        rm -f -- "$CONFIG_CACHE"
    fi
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "The required command [$1] is not installed."
}

require_php_extension() {
    php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$1" \
        || fail "The required PHP extension [$1] is not enabled."
}

ensure_database_unused() {
    local database_file

    for database_file in \
        "$LOCAL_DATABASE" \
        "$LOCAL_DATABASE-wal" \
        "$LOCAL_DATABASE-shm" \
        "$LOCAL_DATABASE-journal"; do
        if [[ -e "$database_file" ]] && lsof "$database_file" >/dev/null 2>&1; then
            fail 'The local SQLite database is in use. Stop Laravel, queue workers, and database tools first.'
        fi
    done
}

checkpoint_database() {
    local database="$1"
    local checkpoint_result

    checkpoint_result="$(sqlite3 -batch "$database" 'PRAGMA wal_checkpoint(TRUNCATE);')" \
        || fail "SQLite checkpoint failed for [$database]."

    if [[ "${checkpoint_result%%|*}" != '0' ]]; then
        fail "SQLite checkpoint could not get an exclusive lock for [$database]."
    fi
}

validate_sqlite_database() {
    local database="$1"
    local integrity_result

    integrity_result="$(sqlite3 -batch "$database" 'PRAGMA integrity_check;')" \
        || fail "SQLite integrity validation failed for [$database]."

    [[ "$integrity_result" == 'ok' ]] \
        || fail "SQLite integrity validation failed for [$database]."
}

free_local_port() {
    php -r '$server = stream_socket_server("tcp://127.0.0.1:0"); if ($server === false) { exit(1); } echo substr(strrchr(stream_socket_get_name($server, false), ":"), 1);'
}

open_tunnel() {
    local container_ip="$1"
    local local_port="$2"
    local deadline=$((SECONDS + TUNNEL_WAIT_SECONDS))

    # ControlPath=none keeps the forward in this ssh process, so the kill in cleanup closes it.
    ssh -N \
        -o BatchMode=yes \
        -o ExitOnForwardFailure=yes \
        -o ControlPath=none \
        -L "127.0.0.1:$local_port:$container_ip:3306" \
        "$SSH_HOST" </dev/null &
    TUNNEL_PID=$!

    until lsof -nP -a -p "$TUNNEL_PID" -iTCP:"$local_port" -sTCP:LISTEN >/dev/null 2>&1; do
        kill -0 "$TUNNEL_PID" 2>/dev/null || fail 'The SSH tunnel to the production database did not start.'
        ((SECONDS < deadline)) || fail 'The SSH tunnel to the production database did not open in time.'
        sleep 0.2
    done
}

remove_database_sidecars() {
    local database="$1"

    rm -f -- "$database-wal" "$database-shm" "$database-journal"
}

for argument in "$@"; do
    case "$argument" in
        --yes)
            ASSUME_YES=1
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            usage >&2
            fail "Unknown option [$argument]."
            ;;
    esac
done

require_command php
require_command ssh
require_command lsof
require_command sqlite3
require_php_extension pdo_sqlite
require_php_extension pdo_mysql

[[ -f "$LOCAL_DATABASE" ]] || fail "The local database does not exist at [$LOCAL_DATABASE]."
[[ -w "$DATABASE_DIR" ]] || fail "The database directory is not writable."

ensure_database_unused

if [[ "$ASSUME_YES" -ne 1 ]]; then
    printf 'Replace the local SQLite database with a fresh read-only production snapshot? [y/N] '
    read -r answer

    case "$answer" in
        y|Y|yes|YES)
            ;;
        *)
            printf 'No change was made.\n'
            exit 0
            ;;
    esac
fi

umask 077
TEMP_DATABASE="$(mktemp "$DATABASE_DIR/.production-sync-XXXXXX")"
CONFIG_CACHE="$TEMP_DATABASE.config.php"
[[ ! -e "$CONFIG_CACHE" ]] || fail 'The isolated Laravel config-cache path already exists.'
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

printf 'Verify the fresh SQLite target through Laravel.\n'
(
    cd "$LARAVEL_DIR"
    env \
        APP_CONFIG_CACHE="$CONFIG_CACHE" \
        APP_ENV=local \
        DB_URL= \
        DB_CONNECTION=sqlite \
        DB_DATABASE="$TEMP_DATABASE" \
        VOLTIKKA_LOCAL_DATABASE_SYNC=1 \
        php artisan development:sync-production-database \
            --target="$TEMP_DATABASE" \
            --verify-target
)

printf 'Build the fresh SQLite schema.\n'
(
    cd "$LARAVEL_DIR"
    env \
        APP_CONFIG_CACHE="$CONFIG_CACHE" \
        APP_ENV=local \
        DB_URL= \
        DB_CONNECTION=sqlite \
        DB_DATABASE="$TEMP_DATABASE" \
        php artisan migrate --force --no-interaction
)

printf 'Open the SSH tunnel to the production database.\n'
DB_CONTAINER_IP="$(ssh -o BatchMode=yes "$SSH_HOST" \
    "docker inspect -f '{{(index .NetworkSettings.Networks \"kamal\").IPAddress}}' $DB_CONTAINER")" \
    || fail "The address of [$DB_CONTAINER] could not be read on [$SSH_HOST]."
[[ "$DB_CONTAINER_IP" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] \
    || fail "The address of [$DB_CONTAINER] on the Docker network kamal is not valid."

# Read the password only into this variable. Never print it.
DB_PASSWORD_VALUE="$(ssh -o BatchMode=yes "$SSH_HOST" "sed -n 's/^DB_PASSWORD=//p' $REMOTE_ENV_FILE")" \
    || fail 'The production database password could not be read on the server.'
[[ -n "$DB_PASSWORD_VALUE" && "$DB_PASSWORD_VALUE" != *$'\n'* ]] \
    || fail 'The server env file does not contain exactly one DB_PASSWORD value.'

LOCAL_TUNNEL_PORT="$(free_local_port)" || fail 'No free local port was found for the SSH tunnel.'
open_tunnel "$DB_CONTAINER_IP" "$LOCAL_TUNNEL_PORT"

# Give the password to PHP in the environment, not as an argument, so ps does not show it.
ENCODED_PASSWORD="$(SYNC_PASSWORD="$DB_PASSWORD_VALUE" php -r 'echo rawurlencode((string) getenv("SYNC_PASSWORD"));')" \
    || fail 'The production database password could not be encoded.'
DB_PASSWORD_VALUE=''

printf 'Copy production application data in a read-only transaction.\n'
(
    cd "$LARAVEL_DIR"
    # A prefix assignment puts the source URL in the child environment only, not in its arguments.
    PRODUCTION_MYSQL_URL="mysql://$DB_USER:$ENCODED_PASSWORD@127.0.0.1:$LOCAL_TUNNEL_PORT/$DB_NAME" \
    env \
        APP_CONFIG_CACHE="$CONFIG_CACHE" \
        APP_ENV=local \
        DB_URL= \
        DB_CONNECTION=sqlite \
        DB_DATABASE="$TEMP_DATABASE" \
        VOLTIKKA_LOCAL_DATABASE_SYNC=1 \
        php artisan development:sync-production-database --target="$TEMP_DATABASE"
)
ENCODED_PASSWORD=''
close_tunnel

checkpoint_database "$TEMP_DATABASE"
validate_sqlite_database "$TEMP_DATABASE"
remove_database_sidecars "$TEMP_DATABASE"

ensure_database_unused

BACKUP_PATH="/tmp/voltikka-database-$(date +%Y%m%d-%H%M%S)-$$.sqlite"
sqlite3 -batch "$LOCAL_DATABASE" ".backup '$BACKUP_PATH'" \
    || fail 'The local SQLite backup failed.'
validate_sqlite_database "$BACKUP_PATH"

checkpoint_database "$LOCAL_DATABASE"
ensure_database_unused
remove_database_sidecars "$LOCAL_DATABASE"
# Stop all local processes before this point. lsof is advisory, so check at the last possible moment too.
ensure_database_unused
mv -f -- "$TEMP_DATABASE" "$LOCAL_DATABASE"
TEMP_DATABASE=''

printf 'The local database is ready.\n'
printf 'Backup: %s\n' "$BACKUP_PATH"
printf 'Restart Laravel, queue workers, and database tools before you continue.\n'
