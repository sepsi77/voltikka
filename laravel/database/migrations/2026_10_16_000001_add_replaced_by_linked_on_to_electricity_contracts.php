<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Europe/Helsinki calendar date on which a replacement link became effective.
 *
 * A date, not a timestamp: historical statistics trust a link per Helsinki target date, and the
 * backfill can only derive a calendar date. Null means "date unknown"; history then does not
 * trust the link (the current writer still does).
 *
 * Backfill (user statement 2026-10-16: a link is usually created on the date when the old contract
 * disappears from the source API and the new one becomes available):
 *   date = max(successor first seen, predecessor last seen) + 1 day
 * "Seen" uses both immutable source observations and price-component dates, because source
 * observations start on 2026-07-23 for every contract and would hide earlier evidence.
 * The import links only an inactive predecessor (first import without it: last seen + 1) to an
 * ACTIVE successor. A new contract stays inactive until its first validation, which runs after
 * its first import, so the earliest import that can link it is the next day (first seen + 1).
 * Production evidence: the current writer used no lineage on a successor's first day
 * (2026-10-10 Vihreä Vakaa valinta, 2026-10-13 Seinäjoki SE Oikea) and used it from the next day.
 * Existing non-null dates are never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('electricity_contracts', 'replaced_by_linked_on')) {
            Schema::table('electricity_contracts', function (Blueprint $table): void {
                $table->date('replaced_by_linked_on')->nullable()->after('replaced_by_contract_id');
            });
        }

        DB::table('electricity_contracts')
            ->whereNotNull('replaced_by_contract_id')
            ->whereNull('replaced_by_linked_on')
            ->select(['id', 'replaced_by_contract_id'])
            // chunkById: the update below changes the filtered column, so offset paging would skip rows.
            ->chunkById(500, function ($links): void {
                $ids = $links->pluck('id')->merge($links->pluck('replaced_by_contract_id'))->unique()->values()->all();
                $first = [];
                $last = [];
                $observations = DB::table('contract_source_observations')->whereIn('contract_id', $ids)
                    ->groupBy('contract_id')
                    ->selectRaw('contract_id, MIN(first_observed_at) AS first_seen, MAX(COALESCE(last_observed_at, first_observed_at)) AS last_seen')
                    ->get();
                foreach ($observations as $row) {
                    $first[$row->contract_id] = self::helsinkiDate($row->first_seen);
                    $last[$row->contract_id] = self::helsinkiDate($row->last_seen);
                }
                $components = DB::table('price_components')->whereIn('electricity_contract_id', $ids)
                    ->groupBy('electricity_contract_id')
                    ->selectRaw('electricity_contract_id AS contract_id, MIN(DATE(price_date)) AS first_seen, MAX(DATE(price_date)) AS last_seen')
                    ->get();
                foreach ($components as $row) {
                    $first[$row->contract_id] = min($first[$row->contract_id] ?? $row->first_seen, $row->first_seen);
                    $last[$row->contract_id] = max($last[$row->contract_id] ?? $row->last_seen, $row->last_seen);
                }
                foreach ($links as $link) {
                    $successorFirst = $first[$link->replaced_by_contract_id] ?? null;
                    if ($successorFirst === null) {
                        continue;
                    }
                    $linkedOn = CarbonImmutable::parse(max($successorFirst, $last[$link->id] ?? $successorFirst))->addDay()->toDateString();
                    DB::table('electricity_contracts')->where('id', $link->id)->whereNull('replaced_by_linked_on')
                        ->update(['replaced_by_linked_on' => $linkedOn]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('electricity_contracts', 'replaced_by_linked_on')) {
            Schema::table('electricity_contracts', function (Blueprint $table): void {
                $table->dropColumn('replaced_by_linked_on');
            });
        }
    }

    private static function helsinkiDate(string $utc): string
    {
        return CarbonImmutable::parse($utc, 'UTC')->setTimezone('Europe/Helsinki')->toDateString();
    }
};
