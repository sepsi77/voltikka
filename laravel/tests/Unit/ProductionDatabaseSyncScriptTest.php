<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionDatabaseSyncScriptTest extends TestCase
{
    private const COPY_COMMAND = 'php artisan development:sync-production-database --target="$TEMP_DATABASE"';

    public function test_the_script_replaces_only_after_sync_and_backup_steps(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/scripts/sync-production-database.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('set -Eeuo pipefail', $script);
        $this->assertStringContainsString('trap cleanup EXIT', $script);
        $this->assertStringContainsString('mktemp "$DATABASE_DIR/.production-sync-XXXXXX"', $script);
        $this->assertStringContainsString('ensure_database_unused', $script);
        $this->assertStringContainsString('require_command sqlite3', $script);
        $this->assertStringContainsString('require_php_extension pdo_sqlite', $script);
        $this->assertStringContainsString('require_php_extension pdo_mysql', $script);
        $this->assertStringContainsString('sqlite3 -batch "$LOCAL_DATABASE" ".backup \'$BACKUP_PATH\'"', $script);
        $this->assertStringNotContainsString('cp -p -- "$LOCAL_DATABASE" "$BACKUP_PATH"', $script);
        $this->assertStringContainsString('validate_sqlite_database "$BACKUP_PATH"', $script);
        $this->assertStringContainsString('mv -f -- "$TEMP_DATABASE" "$LOCAL_DATABASE"', $script);

        $syncPosition = strpos($script, self::COPY_COMMAND);
        $backupPosition = strpos($script, 'sqlite3 -batch "$LOCAL_DATABASE" ".backup \'$BACKUP_PATH\'"');
        $replacePosition = strpos($script, 'mv -f -- "$TEMP_DATABASE" "$LOCAL_DATABASE"');

        $this->assertIsInt($syncPosition);
        $this->assertIsInt($backupPosition);
        $this->assertIsInt($replacePosition);
        $this->assertLessThan($backupPosition, $syncPosition);
        $this->assertLessThan($replacePosition, $backupPosition);
    }

    public function test_the_script_checkpoints_and_removes_sidecars_before_replacement(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/scripts/sync-production-database.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('"$LOCAL_DATABASE-wal"', $script);
        $this->assertStringContainsString('"$LOCAL_DATABASE-shm"', $script);
        $this->assertStringContainsString('"$LOCAL_DATABASE-journal"', $script);
        $this->assertStringContainsString("'PRAGMA wal_checkpoint(TRUNCATE);'", $script);

        $syncPosition = strpos($script, self::COPY_COMMAND);
        $secondUseCheckPosition = strpos($script, "ensure_database_unused\n\nBACKUP_PATH", $syncPosition);
        $localCheckpointPosition = strpos($script, 'checkpoint_database "$LOCAL_DATABASE"', $syncPosition);
        $sidecarRemovalPosition = strpos($script, 'remove_database_sidecars "$LOCAL_DATABASE"', $syncPosition);
        $replacePosition = strpos($script, 'mv -f -- "$TEMP_DATABASE" "$LOCAL_DATABASE"', $syncPosition);

        $this->assertIsInt($secondUseCheckPosition);
        $this->assertIsInt($localCheckpointPosition);
        $this->assertIsInt($sidecarRemovalPosition);
        $this->assertIsInt($replacePosition);
        $this->assertLessThan($localCheckpointPosition, $secondUseCheckPosition);
        $this->assertLessThan($sidecarRemovalPosition, $localCheckpointPosition);
        $this->assertLessThan($replacePosition, $sidecarRemovalPosition);
    }

    public function test_the_script_isolates_all_artisan_commands_from_cached_and_url_configuration(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/scripts/sync-production-database.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('CONFIG_CACHE="$TEMP_DATABASE.config.php"', $script);
        $this->assertStringContainsString('[[ ! -e "$CONFIG_CACHE" ]]', $script);
        $this->assertSame(3, substr_count($script, 'APP_CONFIG_CACHE="$CONFIG_CACHE"'));
        $this->assertSame(3, substr_count($script, 'APP_ENV=local'));
        $this->assertSame(3, substr_count($script, 'DB_URL='));
        $this->assertSame(3, substr_count($script, 'DB_CONNECTION=sqlite'));
        $this->assertSame(3, substr_count($script, 'DB_DATABASE="$TEMP_DATABASE"'));

        $verificationPosition = strpos($script, '--verify-target');
        $migrationPosition = strpos($script, 'php artisan migrate');
        $copyPosition = strpos($script, self::COPY_COMMAND);

        $this->assertIsInt($verificationPosition);
        $this->assertIsInt($migrationPosition);
        $this->assertIsInt($copyPosition);
        $this->assertLessThan($migrationPosition, $verificationPosition);
        $this->assertLessThan($copyPosition, $migrationPosition);
    }

    public function test_the_source_credentials_reach_only_the_copy_child_environment(): void
    {
        $script = $this->script();

        $this->assertSame(1, substr_count($script, 'PRODUCTION_MYSQL_URL='));
        $this->assertStringContainsString(
            "    PRODUCTION_MYSQL_URL=\"mysql://\$DB_USER:\$ENCODED_PASSWORD@127.0.0.1:\$LOCAL_TUNNEL_PORT/\$DB_NAME\" \\\n    exec env \\\n",
            $script,
        );
        $this->assertMatchesRegularExpression('/PRODUCTION_MYSQL_URL=[^\n]+\n    exec env \\\\\n(        [A-Z_]+=[^\n]*\n)+        php artisan development:sync-production-database --target=/', $script);
        $this->assertDoesNotMatchRegularExpression('/\bexport\b/', $script);
        $this->assertStringContainsString('SYNC_PASSWORD="$DB_PASSWORD_VALUE" php -r', $script);
        $this->assertStringContainsString("DB_PASSWORD_VALUE=''", $script);
        $this->assertStringContainsString("ENCODED_PASSWORD=''", $script);
        $this->assertDoesNotMatchRegularExpression('/(echo|printf)[^\n]*\$(DB_PASSWORD_VALUE|ENCODED_PASSWORD)/', $script);
        $this->assertStringNotContainsString('MYSQL_PUBLIC_URL', $script);
        $this->assertStringNotContainsString('MYSQLHOST', $script);
    }

    public function test_the_script_tunnels_to_the_vps_database_without_railway(): void
    {
        $script = $this->script();

        $this->assertStringNotContainsStringIgnoringCase('railway', $script);
        $this->assertStringContainsString('require_command ssh', $script);
        $this->assertStringContainsString('readonly SSH_HOST="breezily-vps"', $script);
        $this->assertStringContainsString('readonly DB_CONTAINER="voltikka-db"', $script);
        $this->assertStringContainsString('{{(index .NetworkSettings.Networks \\"kamal\\").IPAddress}}', $script);
        $this->assertStringContainsString("readonly REMOTE_ENV_FILE='~/.kamal/apps/voltikka/env/roles/web.env'", $script);
        $this->assertStringContainsString("sed -n 's/^DB_PASSWORD=//p' \$REMOTE_ENV_FILE", $script);
        $this->assertStringContainsString('ssh -N \\', $script);
        $this->assertStringContainsString('-o BatchMode=yes', $script);
        $this->assertStringContainsString('-o ExitOnForwardFailure=yes', $script);
        $this->assertStringContainsString('-o ControlPath=none', $script);
        $this->assertStringContainsString('-L "127.0.0.1:$local_port:$container_ip:3306"', $script);
    }

    public function test_the_database_names_match_the_kamal_configuration(): void
    {
        $script = $this->script();
        $deploy = file_get_contents(dirname(__DIR__, 3).'/config/deploy.yml');

        $this->assertIsString($deploy);
        $this->assertMatchesRegularExpression('/^readonly DB_NAME="([^"]+)"$/m', $script);
        preg_match('/^readonly DB_NAME="([^"]+)"$/m', $script, $name);
        preg_match('/^readonly DB_USER="([^"]+)"$/m', $script, $user);
        $this->assertStringContainsString("    DB_DATABASE: {$name[1]}\n", $deploy);
        $this->assertStringContainsString("    DB_USERNAME: {$user[1]}\n", $deploy);
        $this->assertStringContainsString('    DB_HOST: voltikka-db', $deploy);
        $this->assertStringContainsString('    DB_PORT: "3306"', $deploy);
    }

    public function test_the_script_closes_the_tunnel_on_every_exit_path(): void
    {
        $script = $this->script();

        $this->assertMatchesRegularExpression(
            '/close_tunnel\(\) \{\n    if \[\[ -n "\$TUNNEL_PID" \]\]; then\n        kill "\$TUNNEL_PID" 2>\/dev\/null \|\| true\n        wait "\$TUNNEL_PID" 2>\/dev\/null \|\| true\n        TUNNEL_PID=\'\'\n/',
            $script,
        );
        $this->assertMatchesRegularExpression('/cleanup\(\) \{\n    stop_copy\n    close_tunnel\n/', $script);
        // A signal must stop a running copy: bash defers a trap during a foreground command, so the
        // copy is a tracked background child (exec makes its PID the PHP process) that stop_copy kills.
        $this->assertStringContainsString("stop_copy() {\n    if [[ -n \"\$COPY_PID\" ]]; then\n        kill \"\$COPY_PID\" 2>/dev/null || true\n", $script);
        $this->assertStringContainsString("--target=\"\$TEMP_DATABASE\"\n) &\nCOPY_PID=\$!\ncopy_status=0\nwait \"\$COPY_PID\" || copy_status=\$?\n", $script);
        $this->assertStringContainsString('((copy_status == 0)) || fail', $script);
        $this->assertStringContainsString("trap 'exit 1' HUP INT TERM", $script);

        $trapPosition = strpos($script, 'trap cleanup EXIT');
        $tunnelPosition = strpos($script, 'open_tunnel "$DB_CONTAINER_IP" "$LOCAL_TUNNEL_PORT"');
        $copyPosition = strpos($script, self::COPY_COMMAND);
        $closePosition = strpos($script, "\nclose_tunnel\n", (int) $copyPosition);
        $backupPosition = strpos($script, 'sqlite3 -batch "$LOCAL_DATABASE" ".backup');

        $this->assertIsInt($trapPosition);
        $this->assertIsInt($tunnelPosition);
        $this->assertIsInt($closePosition);
        $this->assertLessThan($tunnelPosition, $trapPosition);
        $this->assertLessThan($copyPosition, $tunnelPosition);
        $this->assertLessThan($backupPosition, $closePosition);
    }

    public function test_the_script_checks_for_users_at_the_last_possible_point_before_rename(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/scripts/sync-production-database.sh');

        $this->assertMatchesRegularExpression(
            '/remove_database_sidecars "\$LOCAL_DATABASE"\n# [^\n]+\nensure_database_unused\nmv -f -- "\$TEMP_DATABASE" "\$LOCAL_DATABASE"/',
            $script,
        );
    }

    private function script(): string
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/scripts/sync-production-database.sh');
        $this->assertIsString($script);

        return $script;
    }
}
