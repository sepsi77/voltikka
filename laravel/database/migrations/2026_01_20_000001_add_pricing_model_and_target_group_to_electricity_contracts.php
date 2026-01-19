<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add pricing_model and target_group columns to electricity_contracts table.
 *
 * These fields come from the Azure Consumer API:
 * - Details.PricingModel: "Spot", "Fixed", "Hybrid", "Other"
 * - Details.TargetGroup: "Consumer", "Company"
 *
 * The pricing_model field is CRITICAL for properly filtering spot/pörssisähkö
 * contracts instead of relying on unreliable name-matching.
 *
 * The target_group field allows filtering consumer vs business contracts.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('electricity_contracts', function (Blueprint $table) {
            // Primary pricing model type from Azure API
            // Values: "Spot", "Fixed", "Hybrid", "Other"
            $table->string('pricing_model')->nullable()->index()->after('metering');

            // Target customer segment from Azure API
            // Values: "Consumer", "Company"
            $table->string('target_group')->nullable()->index()->after('pricing_model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('electricity_contracts', function (Blueprint $table) {
            $table->dropColumn(['pricing_model', 'target_group']);
        });
    }
};
