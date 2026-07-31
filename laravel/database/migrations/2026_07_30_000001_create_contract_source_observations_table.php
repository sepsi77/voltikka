<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_source_observations', function (Blueprint $table) {
            $table->id();
            $table->string('contract_id');
            $table->unsignedBigInteger('source_snapshot_id');
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');

            $table->index(
                ['contract_id', 'first_observed_at', 'last_observed_at'],
                'contract_source_observations_contract_coverage_idx'
            );
            $table->index(
                ['source_snapshot_id', 'first_observed_at', 'last_observed_at'],
                'contract_source_observations_snapshot_coverage_idx'
            );
            $table->foreign('contract_id')
                ->references('id')
                ->on('electricity_contracts')
                ->onDelete('cascade');
            $table->foreign('source_snapshot_id')
                ->references('id')
                ->on('contract_source_snapshots')
                ->onDelete('cascade');
        });

        Schema::table('contract_interpretations', function (Blueprint $table) {
            // No foreign key: a foreign key cannot enforce the exact current episode.
            // The dispatcher and queued job validate this binding in application code.
            $table->unsignedBigInteger('analysis_source_observation_id')
                ->nullable()
                ->index()
                ->after('source_snapshot_id');
        });

        Schema::table('electricity_contracts', function (Blueprint $table) {
            // No foreign key: observations already cascade from contracts, so a pointer
            // foreign key would create a circular delete path.
            $table->unsignedBigInteger('current_source_observation_id')
                ->nullable()
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('contract_interpretations', function (Blueprint $table) {
            $table->dropColumn('analysis_source_observation_id');
        });

        Schema::table('electricity_contracts', function (Blueprint $table) {
            $table->dropColumn('current_source_observation_id');
        });

        Schema::dropIfExists('contract_source_observations');
    }
};
