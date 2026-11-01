<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only forecast vintages. spot_price_forecasts keeps only the latest value per hour,
     * so a forecast could not be scored against the outcome later (monthly market review).
     */
    public function up(): void
    {
        Schema::create('spot_price_forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 80);
            $table->string('region', 10)->default('FI');
            $table->char('feed_hash', 64);
            $table->timestamp('fetched_at');
            $table->unsignedInteger('point_count');
            $table->dateTime('first_utc_datetime');
            $table->dateTime('last_utc_datetime');
            // [[unix_seconds_utc, c/kWh incl. VAT], ...] exactly as normalized by the importer.
            $table->json('points');
            $table->timestamps();

            $table->unique(['source', 'feed_hash'], 'spot_forecast_runs_source_feed_hash_unique');
            $table->index(['source', 'region', 'fetched_at'], 'spot_forecast_runs_source_region_fetched_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_price_forecast_runs');
    }
};
