<?php

namespace App\Console\Commands;

use App\Models\ContractPriceSnapshot;
use App\Services\ContractStatistics\SellerSetEnergyPriceIndexService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class BackfillSellerSetEnergyPriceIndex extends Command
{
    protected $signature = 'contracts:backfill-seller-set-energy-price-index
                            {--date= : One historical evidence date}
                            {--from= : First historical evidence date, inclusive}
                            {--to= : Last historical evidence date, inclusive}
                            {--apply : Replace seller-set index rows; default is dry run}
                            {--stop-on-error : Stop after the first failed date}';

    protected $description = 'Preview or backfill the seller-set energy-price index from validated historical evidence';

    public function handle(SellerSetEnergyPriceIndexService $service): int
    {
        $selection = $this->dateSelection();
        if ($selection === null) {
            return self::FAILURE;
        }

        [$from, $to] = $selection;
        $dates = $this->evidenceDates($from, $to);
        if ($dates->isEmpty()) {
            $this->warn('No contract-price snapshot evidence was found for the selected range.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $this->info(sprintf(
            '%s seller-set energy-price index for %d evidence date(s).',
            $apply ? 'Applying' : 'Dry run:',
            $dates->count(),
        ));

        $failed = 0;
        $completed = 0;
        $rows = 0;
        foreach ($dates as $date) {
            try {
                $summary = $apply
                    ? $service->writeHistoricalForDate($date)
                    : $service->previewHistoricalForDate($date);
                $completed++;
                $rows += $summary->rowCount;
                $this->line(sprintf(
                    '%s evidence=%d annual_proof=%d eligible=%d direct_rates=%d rows=%d',
                    $date,
                    $summary->evidenceCount,
                    $summary->annualProofCount,
                    $summary->eligibleContractCount,
                    $summary->directRateCount,
                    $summary->rowCount,
                ));
                $this->line('  families: '.$this->boundedCounts($summary->familyOfferCounts));
                $this->line('  provenance: '.$this->boundedCounts($summary->provenanceCounts));
                $this->line('  exclusions: '.$this->boundedCounts($summary->exclusionCounts));
            } catch (Throwable $exception) {
                $failed++;
                $message = preg_replace('/\s+/', ' ', $exception->getMessage()) ?: $exception::class;
                $this->error(sprintf('%s failed: %s: %s', $date, $exception::class, mb_substr($message, 0, 240)));
                if ((bool) $this->option('stop-on-error')) {
                    break;
                }
            }
        }

        $this->info(sprintf('Totals: dates=%d failed=%d rows=%d', $completed, $failed, $rows));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{CarbonImmutable, CarbonImmutable}|null */
    private function dateSelection(): ?array
    {
        $dateOption = $this->option('date');
        $fromOption = $this->option('from');
        $toOption = $this->option('to');
        if ($dateOption !== null && ($fromOption !== null || $toOption !== null)) {
            $this->error('--date cannot be combined with --from or --to.');

            return null;
        }
        if ($dateOption === null && ($fromOption === null || $toOption === null)) {
            $this->error('Use --date or both --from and --to.');

            return null;
        }

        $date = $dateOption !== null ? $this->parseDate((string) $dateOption) : null;
        $from = $fromOption !== null ? $this->parseDate((string) $fromOption) : null;
        $to = $toOption !== null ? $this->parseDate((string) $toOption) : null;
        if (($dateOption !== null && $date === null)
            || ($fromOption !== null && $from === null)
            || ($toOption !== null && $to === null)) {
            $this->error('Date options must use valid YYYY-MM-DD dates.');

            return null;
        }
        if ($date !== null) {
            $from = $date;
            $to = $date;
        }
        if ($from === null || $to === null || $from->isAfter($to)) {
            $this->error('--from must not be after --to.');

            return null;
        }
        if ($from->toDateString() < SellerSetEnergyPriceIndexService::SERIES_START_DATE
            || $to->toDateString() > SellerSetEnergyPriceIndexService::BASKET_DATE) {
            $this->error(sprintf(
                'Dates must be from %s through %s.',
                SellerSetEnergyPriceIndexService::SERIES_START_DATE,
                SellerSetEnergyPriceIndexService::BASKET_DATE,
            ));

            return null;
        }

        return [$from, $to];
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/Helsinki');
        } catch (Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @return Collection<int, string> */
    private function evidenceDates(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return ContractPriceSnapshot::query()
            ->selectRaw('DATE(snapshot_date) as evidence_date')
            ->whereDate('snapshot_date', '>=', $from->toDateString())
            ->whereDate('snapshot_date', '<=', $to->toDateString())
            ->distinct()
            ->orderBy('evidence_date')
            ->pluck('evidence_date')
            ->map(fn ($date): string => (string) $date)
            ->values();
    }

    /** @param array<string, int> $counts */
    private function boundedCounts(array $counts): string
    {
        if ($counts === []) {
            return 'none';
        }

        ksort($counts);
        $shown = array_slice($counts, 0, 8, true);
        $text = collect($shown)->map(fn (int $count, string $key): string => $key.'='.$count)->implode(', ');

        return count($counts) > 8 ? $text.', …' : $text;
    }
}
