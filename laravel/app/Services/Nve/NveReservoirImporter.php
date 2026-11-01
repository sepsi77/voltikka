<?php

namespace App\Services\Nve;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Upsert NVE weekly reservoir rows and the weekly min/median/max reference.
 *
 * NVE does not say which twenty years its reference covers. resolveReferencePeriod() proves it
 * from the weekly history: it accepts a period only when min, median and max of every
 * configured area for ISO weeks 1-52 equal the published values. Without proof nothing is stored.
 * Filling is stored as a ratio (0..1), as NVE publishes it.
 */
class NveReservoirImporter
{
    private const UPSERT_CHUNK_SIZE = 500;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return int Number of weekly rows written
     */
    public function importWeeks(array $rows, ?int $fromYear, CarbonInterface $fetchedAt): int
    {
        $now = now();
        $fetched = $fetchedAt->copy()->utc()->format('Y-m-d H:i:s');
        $records = [];

        foreach ($this->configuredRows($rows) as $row) {
            if (! is_int($row['iso_aar'] ?? null) || ($fromYear !== null && $row['iso_aar'] < $fromYear)) {
                continue;
            }
            if (! $this->isNumber($row['fyllingsgrad'] ?? null) || ! is_string($row['dato_Id'] ?? null)) {
                continue;
            }

            $records[] = [
                'area_type' => (string) $row['omrType'],
                'area_no' => (int) $row['omrnr'],
                'iso_year' => (int) $row['iso_aar'],
                'iso_week' => (int) $row['iso_uke'],
                'week_end_date' => substr($row['dato_Id'], 0, 10),
                'filling_ratio' => (float) $row['fyllingsgrad'],
                'filling_twh' => $this->numberOrNull($row['fylling_TWh'] ?? null),
                'capacity_twh' => $this->numberOrNull($row['kapasitet_TWh'] ?? null),
                'previous_week_ratio' => $this->numberOrNull($row['fyllingsgrad_forrige_uke'] ?? null),
                'change_ratio' => $this->numberOrNull($row['endring_fyllingsgrad'] ?? null),
                'fetched_at' => $fetched,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('nve_reservoir_weeks')->upsert(
                $chunk,
                ['area_type', 'area_no', 'iso_year', 'iso_week'],
                ['week_end_date', 'filling_ratio', 'filling_twh', 'capacity_twh', 'previous_week_ratio', 'change_ratio', 'fetched_at', 'updated_at']
            );
        }

        return count($records);
    }

    /**
     * Find the inclusive ISO-year period of the published reference, or null if no candidate
     * matches. Candidates end at the current year - 1, - 2 and the current year.
     *
     * @param  list<array<string, mixed>>  $weeks  full weekly history
     * @param  list<array<string, mixed>>  $references
     * @return array{0: int, 1: int}|null
     */
    public function resolveReferencePeriod(array $weeks, array $references, int $currentYear): ?array
    {
        $years = (int) config('nve.reference_years', 20);
        $tolerance = (float) config('nve.reference_tolerance', 0.00001);

        $history = [];
        foreach ($this->configuredRows($weeks) as $row) {
            if (is_int($row['iso_aar'] ?? null) && $this->isNumber($row['fyllingsgrad'] ?? null)) {
                $history[$row['omrType'].'|'.$row['omrnr'].'|'.$row['iso_uke']][(int) $row['iso_aar']] = (float) $row['fyllingsgrad'];
            }
        }

        foreach ([$currentYear - 1, $currentYear - 2, $currentYear] as $endYear) {
            $startYear = $endYear - $years + 1;
            $checked = 0;
            $matches = true;

            foreach ($this->configuredRows($references) as $reference) {
                if ((int) $reference['iso_uke'] > 52) {
                    continue;
                }
                $values = array_filter(
                    $history[$reference['omrType'].'|'.$reference['omrnr'].'|'.$reference['iso_uke']] ?? [],
                    fn (int $year) => $year >= $startYear && $year <= $endYear,
                    ARRAY_FILTER_USE_KEY
                );
                if (count($values) !== $years) {
                    $matches = false;
                    break;
                }

                sort($values);
                $middle = intdiv($years, 2);
                $median = $years % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

                if (abs($values[0] - (float) $reference['minFyllingsgrad']) > $tolerance
                    || abs($median - (float) $reference['medianFyllingsGrad']) > $tolerance
                    || abs($values[$years - 1] - (float) $reference['maxFyllingsgrad']) > $tolerance) {
                    $matches = false;
                    break;
                }
                $checked++;
            }

            if ($matches && $checked > 0) {
                return [$startYear, $endYear];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $references
     * @return int Number of reference rows written
     */
    public function importReferences(array $references, int $fromYear, int $toYear, CarbonInterface $fetchedAt): int
    {
        $now = now();
        $fetched = $fetchedAt->copy()->utc()->format('Y-m-d H:i:s');
        $records = [];

        foreach ($this->configuredRows($references) as $row) {
            if (! $this->isNumber($row['medianFyllingsGrad'] ?? null)) {
                continue;
            }

            $records[] = [
                'area_type' => (string) $row['omrType'],
                'area_no' => (int) $row['omrnr'],
                'iso_week' => (int) $row['iso_uke'],
                'reference_from_year' => $fromYear,
                'reference_to_year' => $toYear,
                'min_ratio' => $this->numberOrNull($row['minFyllingsgrad'] ?? null),
                'min_twh' => $this->numberOrNull($row['minFyllingTWH'] ?? null),
                'median_ratio' => (float) $row['medianFyllingsGrad'],
                'median_twh' => $this->numberOrNull($row['medianFylling_TWH'] ?? null),
                'max_ratio' => $this->numberOrNull($row['maxFyllingsgrad'] ?? null),
                'max_twh' => $this->numberOrNull($row['maxFyllingTWH'] ?? null),
                'fetched_at' => $fetched,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('nve_reservoir_week_references')->upsert(
                $chunk,
                ['area_type', 'area_no', 'iso_week', 'reference_from_year', 'reference_to_year'],
                ['min_ratio', 'min_twh', 'median_ratio', 'median_twh', 'max_ratio', 'max_twh', 'fetched_at', 'updated_at']
            );
        }

        return count($records);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function configuredRows(array $rows): array
    {
        $areaTypes = (array) config('nve.area_types', ['NO', 'EL']);

        return array_values(array_filter($rows, fn ($row) => is_array($row)
            && in_array($row['omrType'] ?? null, $areaTypes, true)
            && is_int($row['omrnr'] ?? null)
            && is_int($row['iso_uke'] ?? null)
            && (! array_key_exists('iso_aar', $row) || is_int($row['iso_aar']))));
    }

    private function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private function numberOrNull(mixed $value): ?float
    {
        return $this->isNumber($value) ? (float) $value : null;
    }
}
