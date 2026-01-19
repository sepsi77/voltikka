<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for the electricity_sources table.
 *
 * NOTE: This migration documents the existing PostgreSQL schema.
 * The table already exists in the production database - do NOT run
 * this migration against the existing database.
 *
 * Ported from: legacy/python/shared/voltikka/database/models.py (ElectricitySource model)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only create if the table doesn't exist (for fresh installs/testing)
        if (!Schema::hasTable('electricity_sources')) {
            Schema::create('electricity_sources', function (Blueprint $table) {
                // Primary key is the contract_id (one-to-one with ElectricityContract)
                $table->string('contract_id')->primary();
                $table->foreign('contract_id')
                    ->references('id')
                    ->on('electricity_contracts')
                    ->onDelete('cascade');

                // Renewable energy percentages
                $table->float('renewable_total')->nullable();
                $table->float('renewable_biomass')->nullable();
                $table->float('renewable_solar')->nullable();
                $table->float('renewable_wind')->nullable();
                $table->float('renewable_general')->nullable();
                $table->float('renewable_hydro')->nullable();

                // Fossil energy percentages
                $table->float('fossil_total')->nullable();
                $table->float('fossil_oil')->nullable();
                $table->float('fossil_coal')->nullable();
                $table->float('fossil_natural_gas')->nullable();
                $table->float('fossil_peat')->nullable();

                // Nuclear energy percentages
                $table->float('nuclear_total')->nullable();
                $table->float('nuclear_general')->nullable();

                // Note: No timestamps in the original schema
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('electricity_sources');
    }
};
