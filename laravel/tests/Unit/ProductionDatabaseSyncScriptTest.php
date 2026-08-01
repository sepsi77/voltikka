<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionDatabaseSyncScriptTest extends TestCase
{
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

        $syncPosition = strpos($script, 'railway run');
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

        $syncPosition = strpos($script, 'railway run');
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
        $railwayPosition = strpos($script, 'railway run');
        $railwayChildEnvironmentPosition = strpos($script, '-- env', $railwayPosition);
        $railwayArtisanPosition = strpos($script, 'php artisan development:sync-production-database', $railwayPosition);

        $this->assertIsInt($verificationPosition);
        $this->assertIsInt($migrationPosition);
        $this->assertIsInt($railwayChildEnvironmentPosition);
        $this->assertIsInt($railwayArtisanPosition);
        $this->assertLessThan($migrationPosition, $verificationPosition);
        $this->assertLessThan($railwayArtisanPosition, $railwayChildEnvironmentPosition);
        $this->assertStringNotContainsString('MYSQL_PUBLIC_URL=', $script);
        $this->assertStringNotContainsString('MYSQLHOST=', $script);
    }

    public function test_the_script_checks_for_users_at_the_last_possible_point_before_rename(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/scripts/sync-production-database.sh');

        $this->assertMatchesRegularExpression(
            '/remove_database_sidecars "\$LOCAL_DATABASE"\n# [^\n]+\nensure_database_unused\nmv -f -- "\$TEMP_DATABASE" "\$LOCAL_DATABASE"/',
            $script,
        );
    }

    public function test_the_script_uses_explicit_railway_context_and_telemetry(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/scripts/sync-production-database.sh');

        $this->assertStringContainsString('6d8cae01-1006-409f-8108-1d51f1abc676', $script);
        $this->assertStringContainsString('9245cef8-41d0-486e-862f-193726511dba', $script);
        $this->assertStringContainsString('beb2ba12-4a7b-416b-b4b1-596434dc3215', $script);
        $this->assertStringContainsString('RAILWAY_CALLER=skill:use-railway@1.2.2', $script);
        $this->assertStringContainsString('RAILWAY_AGENT_SESSION="$SYNC_RAILWAY_AGENT_SESSION"', $script);
        $this->assertStringNotContainsString('railway run env', $script);
        $this->assertStringNotContainsString('railway variable', $script);
        $this->assertStringContainsString('-- env', $script);
    }
}
