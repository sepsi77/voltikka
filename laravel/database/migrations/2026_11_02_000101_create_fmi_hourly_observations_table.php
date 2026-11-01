<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hourly FMI station observations (temperature, wind, global radiation) for own spot price
     * models. Long format; rows are upserted, because FMI corrects observations.
     */
    public function up(): void
    {
        Schema::create('fmi_hourly_observations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('fmisid');
            $table->string('parameter', 20); // TA_PT1H_AVG, WS_PT1H_AVG, GLOB_PT1H_AVG
            $table->unsignedBigInteger('timestamp'); // start of the UTC hour described, unix seconds
            $table->dateTime('utc_datetime'); // UTC text, like spot_prices_hour
            $table->double('value');
            $table->unsignedSmallInteger('sample_count'); // 1 for PT1H values; minutes for radiation
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['fmisid', 'parameter', 'timestamp'], 'fmi_hourly_station_parameter_timestamp_unique');
            $table->index(['parameter', 'timestamp'], 'fmi_hourly_parameter_timestamp_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fmi_hourly_observations');
    }
};
