<?php

namespace App\Console\Commands;

use App\Services\DevelopmentDatabase\DatabaseSyncException;
use App\Services\DevelopmentDatabase\DevelopmentDatabaseSynchronizer;
use App\Services\DevelopmentDatabase\ProductionMySqlConnection;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

class SyncProductionDatabase extends Command
{
    protected $signature = 'development:sync-production-database
                            {--target= : An existing temporary SQLite file}
                            {--verify-target : Verify the effective Laravel database target without copying data}';

    protected $description = 'Copy public application data from production into a temporary development SQLite file';

    public function handle(
        ProductionMySqlConnection $production,
        DevelopmentDatabaseSynchronizer $synchronizer,
        DatabaseManager $databases,
    ): int {
        if (getenv('VOLTIKKA_LOCAL_DATABASE_SYNC') !== '1') {
            $this->error('Set VOLTIKKA_LOCAL_DATABASE_SYNC=1 to run this command.');

            return self::FAILURE;
        }

        $targetOption = $this->option('target');

        if (! is_string($targetOption) || trim($targetOption) === '') {
            $this->error('The --target option is required.');

            return self::FAILURE;
        }

        $targetPath = realpath($targetOption);

        if ($targetPath === false || ! is_file($targetPath) || ! is_writable($targetPath)) {
            $this->error('The --target file must exist and must be writable.');

            return self::FAILURE;
        }

        $localDatabase = realpath(database_path('database.sqlite'));

        if ($localDatabase !== false && $this->isSameFile($targetPath, $localDatabase)) {
            $this->error('The --target file must not be the active local database.');

            return self::FAILURE;
        }

        if (
            dirname($targetPath) !== realpath(database_path())
            || ! str_starts_with(basename($targetPath), '.production-sync-')
        ) {
            $this->error('The --target file must be a .production-sync-* temporary file in the database directory.');

            return self::FAILURE;
        }

        if (! $this->verifyEffectiveTarget($databases, $targetPath)) {
            return self::FAILURE;
        }

        if ($this->option('verify-target')) {
            $this->info('The effective Laravel database target is the explicit temporary SQLite file.');

            return self::SUCCESS;
        }

        try {
            $target = new PDO('sqlite:'.$targetPath, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $source = $production->connect();
            $production->beginReadOnlyConsistentTransaction($source);

            try {
                $counts = $synchronizer->copy($source, $target);
            } finally {
                $production->rollBackReadOnlyTransaction($source);
            }
        } catch (DatabaseSyncException $exception) {
            $this->error($exception->getMessage());
            $this->line('The active local database was not changed.');

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Database sync failed. The active local database was not changed.');

            return self::FAILURE;
        }

        foreach ($counts as $table => $count) {
            $this->line("Copied {$table}: {$count} rows.");
        }

        $this->info('The temporary SQLite database passed all checks.');

        return self::SUCCESS;
    }

    private function isSameFile(string $first, string $second): bool
    {
        $firstStat = @stat($first);
        $secondStat = @stat($second);

        return is_array($firstStat)
            && is_array($secondStat)
            && $firstStat['dev'] === $secondStat['dev']
            && $firstStat['ino'] === $secondStat['ino'];
    }

    private function verifyEffectiveTarget(DatabaseManager $databases, string $targetPath): bool
    {
        try {
            $databases->purge();
            $connection = $databases->connection();

            if ($connection->getDriverName() !== 'sqlite') {
                $this->error('The effective Laravel database driver is not sqlite.');

                return false;
            }

            $mainDatabase = collect($connection->select('PRAGMA database_list'))
                ->first(fn (object $database): bool => ($database->name ?? null) === 'main');
            $effectivePath = is_object($mainDatabase) && is_string($mainDatabase->file ?? null)
                ? realpath($mainDatabase->file)
                : false;

            if ($effectivePath === false || $effectivePath !== $targetPath) {
                $this->error('The effective Laravel database path is not the explicit --target file.');

                return false;
            }
        } catch (Throwable) {
            $this->error('The effective Laravel database target could not be verified.');

            return false;
        } finally {
            $databases->purge();
        }

        return true;
    }
}
