<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_ANNUAL_METHOD = 'annual_cost_legacy_v1';

    private const UNIT_STATISTICS_METHOD = 'unit_statistics_v1';

    private const LEGACY_UNIQUE = 'contract_price_daily_stats_unique';

    private const METHOD_UNIQUE = 'contract_price_daily_stats_method_unique';

    public function up(): void
    {
        if (! Schema::hasTable('contract_price_annual_costs')) {
            Schema::create('contract_price_annual_costs', function (Blueprint $table) {
                $table->id();
                $table->date('snapshot_date');
                $table->string('contract_id');
                $table->string('segment_key', 80);
                $table->string('pricing_basis', 40);
                $table->unsignedInteger('consumption_kwh');
                $table->decimal('annual_cost', 12, 4);
                $table->string('method_version', 80);
                $table->string('calculation_basis', 80);
                $table->string('estimate_method', 80)->nullable();
                $table->string('estimate_basis', 80)->nullable();
                $table->string('compatibility_key', 120);
                $table->unsignedBigInteger('source_observation_id')->nullable()->index();
                $table->unsignedBigInteger('source_snapshot_id')->nullable()->index();
                $table->unsignedBigInteger('source_interpretation_id')->nullable()->index();
                $table->dateTime('price_episode_started_at')->nullable();
                $table->json('provenance')->nullable();
                $table->timestamps();

                $table->unique(
                    ['snapshot_date', 'contract_id', 'consumption_kwh', 'method_version'],
                    'contract_annual_costs_date_contract_consumption_method_unique',
                );
                $table->index(
                    ['method_version', 'snapshot_date', 'segment_key', 'consumption_kwh'],
                    'contract_annual_costs_method_date_segment_consumption_idx',
                );
                $table->index(
                    ['contract_id', 'snapshot_date'],
                    'contract_annual_costs_contract_date_idx',
                );
                $table->foreign('contract_id')
                    ->references('id')
                    ->on('electricity_contracts')
                    ->onDelete('cascade');
            });
        }

        $this->addDailyStatisticColumns();

        // This backfill runs on every retry. A partial MySQL DDL attempt must not
        // leave old rows without an application-compatible method identity.
        DB::table('contract_price_daily_statistics')
            ->whereNull('method_version')
            ->where('metric_key', 'annual_cost')
            ->update(['method_version' => self::LEGACY_ANNUAL_METHOD]);

        DB::table('contract_price_daily_statistics')
            ->whereNull('method_version')
            ->where('metric_key', '!=', 'annual_cost')
            ->update(['method_version' => self::UNIT_STATISTICS_METHOD]);

        if (DB::table('contract_price_daily_statistics')->whereNull('method_version')->exists()) {
            throw new \RuntimeException(
                'Could not backfill method_version for every contract price daily statistic.'
            );
        }

        $this->assertNoDuplicateLegacyIdentities();

        if (Schema::hasIndex('contract_price_daily_statistics', self::LEGACY_UNIQUE)) {
            Schema::table('contract_price_daily_statistics', function (Blueprint $table) {
                $table->dropUnique(self::LEGACY_UNIQUE);
            });
        }

        // Keep this column nullable in storage. This permits an application rollback
        // while the model and all new writers still supply a method version.
        if (! Schema::hasIndex('contract_price_daily_statistics', self::METHOD_UNIQUE)) {
            Schema::table('contract_price_daily_statistics', function (Blueprint $table) {
                $table->unique(
                    ['stat_date', 'segment_key', 'metric_key', 'consumption_kwh', 'method_version'],
                    self::METHOD_UNIQUE,
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contract_price_daily_statistics', 'method_version')) {
            $this->assertNoDuplicateLegacyIdentities(
                'Cannot restore the legacy contract price statistics unique key while multiple method versions coexist.'
            );
        }

        if (Schema::hasIndex('contract_price_daily_statistics', self::METHOD_UNIQUE)) {
            Schema::table('contract_price_daily_statistics', function (Blueprint $table) {
                $table->dropUnique(self::METHOD_UNIQUE);
            });
        }

        $columns = collect([
            'method_version',
            'calculation_basis',
            'estimate_basis',
            'compatibility_key',
            'basis_counts',
        ])->filter(fn (string $column): bool => Schema::hasColumn('contract_price_daily_statistics', $column))->all();

        if ($columns !== []) {
            Schema::table('contract_price_daily_statistics', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }

        if (! Schema::hasIndex('contract_price_daily_statistics', self::LEGACY_UNIQUE)) {
            Schema::table('contract_price_daily_statistics', function (Blueprint $table) {
                $table->unique(
                    ['stat_date', 'segment_key', 'metric_key', 'consumption_kwh'],
                    self::LEGACY_UNIQUE,
                );
            });
        }

        Schema::dropIfExists('contract_price_annual_costs');
    }

    private function addDailyStatisticColumns(): void
    {
        $definitions = [
            'method_version' => fn (Blueprint $table) => $table->string('method_version', 80)->nullable()->after('pricing_basis'),
            'calculation_basis' => fn (Blueprint $table) => $table->string('calculation_basis', 80)->nullable()->after('method_version'),
            'estimate_basis' => fn (Blueprint $table) => $table->string('estimate_basis', 80)->nullable()->after('calculation_basis'),
            'compatibility_key' => fn (Blueprint $table) => $table->string('compatibility_key', 120)->nullable()->after('estimate_basis'),
            'basis_counts' => fn (Blueprint $table) => $table->json('basis_counts')->nullable()->after('compatibility_key'),
        ];

        foreach ($definitions as $column => $definition) {
            if (Schema::hasColumn('contract_price_daily_statistics', $column)) {
                continue;
            }

            Schema::table('contract_price_daily_statistics', function (Blueprint $table) use ($definition) {
                $definition($table);
            });
        }
    }

    private function assertNoDuplicateLegacyIdentities(?string $message = null): void
    {
        $duplicates = DB::table('contract_price_daily_statistics')
            ->select([
                'stat_date',
                'segment_key',
                'metric_key',
                'consumption_kwh',
                DB::raw('COUNT(*) as duplicate_count'),
            ])
            ->groupBy(['stat_date', 'segment_key', 'metric_key', 'consumption_kwh'])
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('stat_date')
            ->orderBy('segment_key')
            ->orderBy('metric_key')
            ->limit(10)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $examples = $duplicates->map(fn (object $row): array => [
            'stat_date' => (string) $row->stat_date,
            'segment_key' => (string) $row->segment_key,
            'metric_key' => (string) $row->metric_key,
            'consumption_kwh' => $row->consumption_kwh !== null ? (int) $row->consumption_kwh : null,
            'count' => (int) $row->duplicate_count,
        ])->all();

        throw new \RuntimeException(($message ??
            'Duplicate legacy contract price daily statistic identities must be repaired before method versioning.')
            .' Examples: '.json_encode($examples, JSON_THROW_ON_ERROR));
    }
};
