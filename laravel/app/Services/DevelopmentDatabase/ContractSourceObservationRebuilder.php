<?php

namespace App\Services\DevelopmentDatabase;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Throwable;

class ContractSourceObservationRebuilder
{
    public function rebuild(): int
    {
        try {
            $migration = require database_path(
                'migrations/2026_07_30_000002_backfill_contract_source_observations.php'
            );

            if (! $migration instanceof Migration) {
                throw new DatabaseSyncException('The source observation backfill migration could not be loaded.');
            }

            $migration->up();

            return DB::table('contract_source_observations')->count();
        } catch (Throwable $exception) {
            if ($exception instanceof DatabaseSyncException) {
                throw $exception;
            }

            throw new DatabaseSyncException(
                'The local contract source observations could not be reconstructed.',
                previous: $exception,
            );
        }
    }
}
