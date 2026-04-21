<?php

namespace App\Console\Commands;

use App\Models\ElectricityContract;
use App\Services\ContractReplacementMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class DetectReplacementContracts extends Command
{
    protected $signature = 'contracts:detect-replacements
                            {--min-score=80 : Minimum score to include in the report}
                            {--confidence= : Filter by confidence (high, medium, low)}
                            {--limit=100 : Maximum number of rows to print}
                            {--json= : Optional path to write full JSON report}';

    protected $description = 'Detect likely replacement contracts for inactive contracts';

    public function handle(ContractReplacementMatcher $matcher): int
    {
        $minScore = (int) $this->option('min-score');
        $confidenceFilter = $this->option('confidence');
        $limit = (int) $this->option('limit');
        $jsonPath = $this->option('json');

        $results = $matcher->findMatchesForInactiveContracts()
            ->filter(function (array $row) use ($minScore, $confidenceFilter) {
                $match = $row['match'];

                if (! $match) {
                    return false;
                }

                if (($match['score'] ?? 0) < $minScore) {
                    return false;
                }

                if ($confidenceFilter && ($match['confidence'] ?? null) !== $confidenceFilter) {
                    return false;
                }

                return true;
            })
            ->sortByDesc(fn (array $row) => $row['match']['score'])
            ->values();

        $this->info('Inactive contracts: ' . ElectricityContract::whereDoesntHave('activeContract')->count());
        $this->info('Matches above threshold: ' . $results->count());

        $summary = $results->groupBy(fn (array $row) => $row['match']['confidence'])
            ->map->count()
            ->toArray();

        if (! empty($summary)) {
            $this->table(['Confidence', 'Count'], collect($summary)->map(fn ($count, $confidence) => [
                'confidence' => $confidence,
                'count' => $count,
            ])->values()->all());
        }

        $rows = $results->take($limit)->map(function (array $row) {
            /** @var ElectricityContract $inactive */
            $inactive = $row['inactive'];
            /** @var ElectricityContract $candidate */
            $candidate = $row['match']['candidate'];

            return [
                'score' => $row['match']['score'],
                'confidence' => $row['match']['confidence'],
                'company' => $inactive->company_name,
                'inactive' => $inactive->name,
                'replacement' => $candidate->name,
                'inactive_id' => $inactive->id,
                'replacement_id' => $candidate->id,
                'signals' => implode(', ', $row['match']['signals']),
                'metrics' => json_encode($row['match']['metrics'], JSON_UNESCAPED_UNICODE),
            ];
        });

        if ($rows->isNotEmpty()) {
            $this->table(
                ['Score', 'Conf', 'Company', 'Inactive contract', 'Replacement', 'Inactive ID', 'Replacement ID', 'Signals', 'Metrics'],
                $rows->all()
            );
        }

        if ($jsonPath) {
            $payload = $this->toSerializableResults($results);
            file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Wrote JSON report to {$jsonPath}");
        }

        return self::SUCCESS;
    }

    protected function toSerializableResults(Collection $results): array
    {
        return $results->map(function (array $row) {
            /** @var ElectricityContract $inactive */
            $inactive = $row['inactive'];
            /** @var ElectricityContract $candidate */
            $candidate = $row['match']['candidate'];

            return [
                'inactive' => [
                    'id' => $inactive->id,
                    'api_id' => $inactive->api_id,
                    'company_name' => $inactive->company_name,
                    'name' => $inactive->name,
                    'contract_type' => $inactive->contract_type,
                    'fixed_time_range' => $inactive->fixed_time_range,
                    'metering' => $inactive->metering,
                    'pricing_model' => $inactive->pricing_model,
                    'target_group' => $inactive->target_group,
                ],
                'replacement' => [
                    'id' => $candidate->id,
                    'api_id' => $candidate->api_id,
                    'company_name' => $candidate->company_name,
                    'name' => $candidate->name,
                    'contract_type' => $candidate->contract_type,
                    'fixed_time_range' => $candidate->fixed_time_range,
                    'metering' => $candidate->metering,
                    'pricing_model' => $candidate->pricing_model,
                    'target_group' => $candidate->target_group,
                ],
                'score' => $row['match']['score'],
                'runner_up_score' => $row['match']['runner_up_score'],
                'confidence' => $row['match']['confidence'],
                'signals' => $row['match']['signals'],
                'metrics' => $row['match']['metrics'],
            ];
        })->all();
    }
}
