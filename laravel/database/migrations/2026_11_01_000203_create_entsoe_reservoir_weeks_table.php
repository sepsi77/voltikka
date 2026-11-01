<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTSO-E weekly reservoir filling (A72): stored energy per country for market reviews.
     * Upserted, because ENTSO-E revises data.
     */
    public function up(): void
    {
        Schema::create('entsoe_reservoir_weeks', function (Blueprint $table) {
            $table->id();
            $table->string('zone', 4); // NO, SE, FI
            $table->unsignedBigInteger('timestamp'); // week start (Monday 00:00 CET/CEST), unix seconds
            $table->dateTime('week_start_utc'); // UTC text
            $table->unsignedSmallInteger('iso_year');
            $table->unsignedTinyInteger('iso_week');
            $table->double('stored_energy_mwh');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['zone', 'timestamp'], 'entsoe_reservoir_weeks_zone_timestamp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entsoe_reservoir_weeks');
    }
};
