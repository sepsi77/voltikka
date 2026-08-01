#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
readonly LARAVEL_DIR="$ROOT_DIR/laravel"
readonly DATABASE_DIR="$LARAVEL_DIR/database"
readonly LOCAL_DATABASE="$DATABASE_DIR/database.sqlite"

readonly RAILWAY_PROJECT_ID="6d8cae01-1006-409f-8108-1d51f1abc676"
readonly RAILWAY_ENVIRONMENT_ID="9245cef8-41d0-486e-862f-193726511dba"
readonly RAILWAY_MYSQL_SERVICE_ID="beb2ba12-4a7b-416b-b4b1-596434dc3215"
readonly SYNC_RAILWAY_AGENT_SESSION="${RAILWAY_AGENT_SESSION:-voltikka-local-db-sync-$(date +%s)-$$}"

ASSUME_YES=0
TEMP_DATABASE=''
CONFIG_CACHE=''
BACKUP_PATH=''

usage() {
    cat <<'EOF'
Usage: scripts/sync-production-database.sh [--yes]

Build a fresh local SQLite database from public production application data.
The script reads production in a read-only transaction. It does not write to production.

  --yes   Do not ask for confirmation.
  --help  Show this help.
EOF
}

fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

cleanup() {
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
require_command railway
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

printf 'Copy production application data in a read-only transaction.\n'
(
    cd "$LARAVEL_DIR"
    RAILWAY_CALLER=skill:use-railway@1.2.2 \
    RAILWAY_AGENT_SESSION="$SYNC_RAILWAY_AGENT_SESSION" \
        railway run \
            --project "$RAILWAY_PROJECT_ID" \
            --environment "$RAILWAY_ENVIRONMENT_ID" \
            --service "$RAILWAY_MYSQL_SERVICE_ID" \
            --no-local \
            -- env \
                APP_CONFIG_CACHE="$CONFIG_CACHE" \
                APP_ENV=local \
                DB_URL= \
                DB_CONNECTION=sqlite \
                DB_DATABASE="$TEMP_DATABASE" \
                VOLTIKKA_LOCAL_DATABASE_SYNC=1 \
                php artisan development:sync-production-database --target="$TEMP_DATABASE"
)

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
