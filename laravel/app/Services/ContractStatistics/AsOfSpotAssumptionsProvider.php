<?php

namespace App\Services\ContractStatistics;

use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\ContractStatistics\DTO\AsOfSpotAssumptionsResult;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AsOfSpotAssumptionsProvider
{
    public const MINIMUM_STORED_COVERAGE_RATIO = 0.98;

    private const TIMEZONE = 'Europe/Helsinki';

    /** @var array<string, AsOfSpotAssumptionsResult> */
    private array $memo = [];

    public function resolve(CarbonInterface $targetDate, string $region = 'FI'): AsOfSpotAssumptionsResult
    {
        $target = CarbonImmutable::instance($targetDate)
            ->setTimezone(self::TIMEZONE)
            ->startOfDay();
        $key = $region.'|'.$target->toDateString();

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $storedRow = SpotPriceAverage::query()
            ->forRegion($region)
            ->ofType(SpotPriceAverage::PERIOD_ROLLING_365D)
            ->whereDate('period_end', $target->toDateString())
            ->orderByDesc('id')
            ->first();

        if ($storedRow !== null) {
            $result = $this->fromStoredRow($storedRow, $target, $region);
            if ($result !== null) {
                return $this->memo[$key] = $result;
            }
        }

        return $this->memo[$key] = $this->fromHourlyRows($target, $region);
    }

    private function fromStoredRow(
        SpotPriceAverage $row,
        CarbonImmutable $target,
        string $region,
    ): ?AsOfSpotAssumptionsResult {
        $end = CarbonImmutable::instance($row->period_end)
            ->setTimezone(self::TIMEZONE)
            ->startOfDay();

        if (! $end->isSameDay($target) || $row->period_start?->toDateString() !== $end->toDateString()) {
            return null;
        }

        $start = $end->subDays(364)->startOfDay();
        $expectedHours = $this->expectedHours($start, $end);
        $values = [
            $row->avg_price_with_tax,
            $row->day_avg_with_tax,
            $row->night_avg_with_tax,
        ];

        $actualHours = $row->hours_count;
        if ($actualHours <= 0 || $actualHours > $expectedHours || ! $this->areFinite($values)) {
            return null;
        }

        $coverageRatio = $actualHours / $expectedHours;
        if ($coverageRatio < self::MINIMUM_STORED_COVERAGE_RATIO) {
            return null;
        }

        $coverageFlag = $actualHours === $expectedHours
            ? AsOfSpotAssumptionsResult::EVIDENCE_COMPLETE
            : AsOfSpotAssumptionsResult::EVIDENCE_PARTIAL_ABOVE_THRESHOLD;

        return new AsOfSpotAssumptionsResult(
            assumptions: new SpotAssumptions(
                dayAvgWithTax: (float) $row->day_avg_with_tax,
                nightAvgWithTax: (float) $row->night_avg_with_tax,
                overallAvgWithTax: (float) $row->avg_price_with_tax,
                periodStart: $start,
                periodEnd: $end,
            ),
            source: AsOfSpotAssumptionsResult::SOURCE_STORED_ROLLING_365D,
            region: $region,
            targetDate: $target,
            coverageRatio: $coverageRatio,
            expectedHours: $expectedHours,
            actualHours: $actualHours,
            hoursCount: $actualHours,
            provenanceFlags: [$coverageFlag],
            sourceRecordId: (int) $row->getKey(),
        );
    }

    private function fromHourlyRows(
        CarbonImmutable $target,
        string $region,
    ): AsOfSpotAssumptionsResult {
        $start = $target->subDays(364)->startOfDay();
        $endExclusive = $target->addDay()->startOfDay();
        $startUtc = $start->utc();
        $endExclusiveUtc = $endExclusive->utc();
        $expectedHours = (int) $startUtc->diffInHours($endExclusiveUtc);

        $rows = DB::table('spot_prices_hour')
            ->where('region', $region)
            ->where('utc_datetime', '>=', $startUtc->format('Y-m-d H:i:s'))
            ->where('utc_datetime', '<', $endExclusiveUtc->format('Y-m-d H:i:s'))
            ->get(['utc_datetime', 'price_without_tax', 'vat_rate']);

        if ($rows->count() !== $expectedHours) {
            return $this->unavailableHourlyResult(
                $region,
                $target,
                'incomplete_hourly_coverage',
                $expectedHours,
                $rows->count(),
            );
        }

        $pricesByTimestamp = [];
        foreach ($rows as $row) {
            $instant = CarbonImmutable::parse((string) $row->utc_datetime, 'UTC');
            $priceWithoutTax = (float) $row->price_without_tax;
            $vatRate = (float) $row->vat_rate;
            $priceWithTax = $priceWithoutTax * (1 + $vatRate);

            if ($instant->minute !== 0 || $instant->second !== 0 || ! $this->areFinite([
                $priceWithoutTax,
                $vatRate,
                $priceWithTax,
            ])) {
                return $this->unavailableHourlyResult(
                    $region,
                    $target,
                    'invalid_hourly_evidence',
                    $expectedHours,
                    $rows->count(),
                );
            }

            $timestamp = $instant->timestamp;
            if (array_key_exists($timestamp, $pricesByTimestamp)) {
                return $this->unavailableHourlyResult(
                    $region,
                    $target,
                    'duplicate_hourly_evidence',
                    $expectedHours,
                    $rows->count(),
                );
            }

            $pricesByTimestamp[$timestamp] = $priceWithTax;
        }

        $overall = [];
        $day = [];
        $night = [];
        for ($instant = $startUtc; $instant->lessThan($endExclusiveUtc); $instant = $instant->addHour()) {
            $price = $pricesByTimestamp[$instant->timestamp] ?? null;
            if ($price === null) {
                return $this->unavailableHourlyResult(
                    $region,
                    $target,
                    'incomplete_hourly_coverage',
                    $expectedHours,
                    $rows->count(),
                );
            }

            $overall[] = $price;
            $localHour = $instant->setTimezone(self::TIMEZONE)->hour;
            if ($localHour >= 7 && $localHour < 22) {
                $day[] = $price;
            } else {
                $night[] = $price;
            }
        }

        if ($overall === [] || $day === [] || $night === []) {
            return $this->unavailableHourlyResult(
                $region,
                $target,
                'incomplete_hourly_coverage',
                $expectedHours,
                $rows->count(),
            );
        }

        return new AsOfSpotAssumptionsResult(
            assumptions: new SpotAssumptions(
                dayAvgWithTax: array_sum($day) / count($day),
                nightAvgWithTax: array_sum($night) / count($night),
                overallAvgWithTax: array_sum($overall) / count($overall),
                periodStart: $start,
                periodEnd: $target,
            ),
            source: AsOfSpotAssumptionsResult::SOURCE_HOURLY_RECONSTRUCTION,
            region: $region,
            targetDate: $target,
            coverageRatio: 1.0,
            expectedHours: $expectedHours,
            actualHours: $expectedHours,
            hoursCount: $expectedHours,
            provenanceFlags: [
                AsOfSpotAssumptionsResult::EVIDENCE_COMPLETE,
                'raw_hourly_reconstruction_strict',
            ],
        );
    }

    private function unavailableHourlyResult(
        string $region,
        CarbonImmutable $target,
        string $reason,
        int $expectedHours,
        int $actualHours,
    ): AsOfSpotAssumptionsResult {
        return AsOfSpotAssumptionsResult::unavailable(
            $region,
            $target,
            $reason,
            $expectedHours,
            $actualHours,
            ['raw_hourly_reconstruction_strict'],
        );
    }

    private function expectedHours(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) $start->utc()->diffInHours($end->addDay()->startOfDay()->utc());
    }

    /** @param list<mixed> $values */
    private function areFinite(array $values): bool
    {
        foreach ($values as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return false;
            }
        }

        return true;
    }
}
