<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EPISODES = 'contract_historical_interpretation_episodes';

    private const INTERPRETATIONS = 'contract_historical_interpretations';

    private const ANNUAL_COSTS = 'contract_price_annual_costs';

    public function up(): void
    {
        if (! Schema::hasTable(self::EPISODES)) {
            Schema::create(self::EPISODES, function (Blueprint $table) {
                $table->id();
                $table->string('contract_id');
                $table->date('episode_start');
                $table->date('episode_end');
                $table->string('builder_version', 64);
                $table->char('episode_fingerprint', 64);
                $table->char('evidence_fingerprint', 64);
                $table->char('manifest_fingerprint', 64);
                $table->string('evidence_grade', 80);
                $table->json('analysis_input');
                $table->json('evidence_manifest');
                $table->timestamps();

                $table->foreign('contract_id')
                    ->references('id')
                    ->on('electricity_contracts')
                    ->onDelete('cascade');
            });
        }

        if (! Schema::hasColumn(self::EPISODES, 'manifest_fingerprint')) {
            Schema::table(self::EPISODES, function (Blueprint $table): void {
                $table->char('manifest_fingerprint', 64)->nullable();
            });
        }

        $this->ensureIndex(
            self::EPISODES,
            'historical_episodes_episode_fingerprint_unique',
            ['episode_fingerprint'],
            unique: true,
        );
        $this->ensureIndex(
            self::EPISODES,
            'historical_episodes_evidence_grade_idx',
            ['evidence_grade'],
        );
        $this->ensureIndex(
            self::EPISODES,
            'historical_episodes_contract_dates_idx',
            ['contract_id', 'episode_start', 'episode_end'],
        );

        if (! Schema::hasTable(self::INTERPRETATIONS)) {
            Schema::create(self::INTERPRETATIONS, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('episode_id');
                $table->string('contract_id');
                $table->char('analysis_fingerprint', 64);
                $table->string('status', 24)->default('pending');
                $table->string('schema_version', 64);
                $table->string('prompt_version', 64);
                $table->string('historical_addendum_version', 64);
                $table->string('validator_version', 64);
                $table->string('parser_version', 64);
                $table->string('provider', 64);
                $table->string('model');
                $table->string('reasoning_effort', 32);
                $table->json('output')->nullable();
                $table->json('validation_errors')->nullable();
                $table->json('llm_attempts')->nullable();
                $table->json('usage')->nullable();
                $table->string('provider_response_id')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->foreign('episode_id')
                    ->references('id')
                    ->on(self::EPISODES)
                    ->onDelete('cascade');
                $table->foreign('contract_id')
                    ->references('id')
                    ->on('electricity_contracts')
                    ->onDelete('cascade');
            });
        }

        $this->ensureIndex(
            self::INTERPRETATIONS,
            'historical_interpretations_analysis_fingerprint_unique',
            ['analysis_fingerprint'],
            unique: true,
        );
        $this->ensureIndex(
            self::INTERPRETATIONS,
            'historical_interpretations_status_idx',
            ['status'],
        );
        $this->ensureIndex(
            self::INTERPRETATIONS,
            'historical_interpretations_contract_status_idx',
            ['contract_id', 'status'],
        );

        $columns = [
            'historical_episode_id' => fn (Blueprint $table) => $table->unsignedBigInteger('historical_episode_id')->nullable(),
            'historical_interpretation_id' => fn (Blueprint $table) => $table->unsignedBigInteger('historical_interpretation_id')->nullable(),
            'historical_evidence_grade' => fn (Blueprint $table) => $table->string('historical_evidence_grade', 80)->nullable(),
        ];
        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn(self::ANNUAL_COSTS, $column)) {
                continue;
            }

            Schema::table(self::ANNUAL_COSTS, function (Blueprint $table) use ($definition) {
                $definition($table);
            });
        }

        $this->ensureIndex(self::ANNUAL_COSTS, 'contract_annual_costs_historical_episode_idx', ['historical_episode_id']);
        $this->ensureIndex(self::ANNUAL_COSTS, 'contract_annual_costs_historical_interpretation_idx', ['historical_interpretation_id']);
        $this->ensureIndex(self::ANNUAL_COSTS, 'contract_annual_costs_historical_grade_idx', ['historical_evidence_grade']);
    }

    public function down(): void
    {
        foreach ([self::INTERPRETATIONS, self::EPISODES] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: historical audit table {$table} contains rows.");
            }
        }

        if (Schema::hasTable(self::ANNUAL_COSTS)) {
            foreach ([
                'contract_annual_costs_historical_episode_idx',
                'contract_annual_costs_historical_interpretation_idx',
                'contract_annual_costs_historical_grade_idx',
            ] as $index) {
                if (Schema::hasIndex(self::ANNUAL_COSTS, $index)) {
                    Schema::table(self::ANNUAL_COSTS, fn (Blueprint $table) => $table->dropIndex($index));
                }
            }

            foreach (['historical_episode_id', 'historical_interpretation_id', 'historical_evidence_grade'] as $column) {
                if (Schema::hasColumn(self::ANNUAL_COSTS, $column)) {
                    Schema::table(self::ANNUAL_COSTS, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }

        Schema::dropIfExists(self::INTERPRETATIONS);
        Schema::dropIfExists(self::EPISODES);
    }

    /** @param list<string> $columns */
    private function ensureIndex(string $tableName, string $indexName, array $columns, bool $unique = false): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasIndex($tableName, $indexName)) {
            return;
        }
        foreach (Schema::getIndexes($tableName) as $index) {
            if (array_values($index['columns'] ?? []) === $columns
                && (! $unique || (bool) ($index['unique'] ?? false))) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName, $unique) {
            if ($unique) {
                $table->unique($columns, $indexName);

                return;
            }

            $table->index($columns, $indexName);
        });
    }
};
