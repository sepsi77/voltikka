<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTSO-E actual generation per production type (A75) as hourly means of MW for market
     * reviews. Upserted, because ENTSO-E revises data.
     */
    public function up(): void
    {
        Schema::create('entsoe_generation_hourly', function (Blueprint $table) {
            $table->id();
            $table->string('zone', 8); // SE1..SE4, EE, DE-LU
            $table->string('psr_type', 4); // B14 nuclear, B16 solar, B18/B19 wind...
            $table->string('direction', 12); // generation | consumption
            $table->unsignedBigInteger('timestamp'); // UTC hour start, unix seconds
            $table->dateTime('utc_datetime'); // UTC text, like spot_prices_hour
            $table->double('value_mw');
            $table->unsignedSmallInteger('sample_count');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['zone', 'psr_type', 'direction', 'timestamp'], 'entsoe_generation_hourly_unique');
            $table->index(['zone', 'utc_datetime'], 'entsoe_generation_hourly_zone_utc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entsoe_generation_hourly');
    }
};
