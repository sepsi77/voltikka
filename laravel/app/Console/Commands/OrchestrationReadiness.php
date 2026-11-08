<?php

namespace App\Console\Commands;

use App\Services\Orchestration\MorningReadiness;
use App\Services\Orchestration\SForecastReadiness;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Read-only readiness check for Temporal workflows (tasks/temporal-orchestration/). Prints one JSON
 * line. Exit 0 when the check ran (ready or not ready), exit 1 for invalid arguments or an error,
 * so the workflow can tell "not ready yet" from "the check failed".
 */
class OrchestrationReadiness extends Command
{
    protected $signature = 'orchestration:readiness
                            {pipeline : s-forecast or morning}
                            {--issue-date= : Issue date D (YYYY-MM-DD, Europe/Helsinki)}
                            {--view= : morning or afternoon}
                            {--date= : morning: Helsinki date (YYYY-MM-DD)}';

    protected $description = 'Read-only input readiness facts for Temporal workflows (JSON)';

    public function handle(SForecastReadiness $sForecast, MorningReadiness $morning): int
    {
        $pipeline = $this->argument('pipeline');
        if (! in_array($pipeline, ['s-forecast', 'morning'], true)) {
            $this->line(json_encode(['error' => 'unknown_pipeline']));

            return Command::FAILURE;
        }

        try {
            $result = $pipeline === 'morning'
                ? $morning->check((string) $this->option('date'))
                : $sForecast->check((string) $this->option('issue-date'), (string) $this->option('view'));
        } catch (InvalidArgumentException $e) {
            $this->line(json_encode(['error' => 'invalid_arguments', 'message' => $e->getMessage()]));

            return Command::FAILURE;
        }

        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
