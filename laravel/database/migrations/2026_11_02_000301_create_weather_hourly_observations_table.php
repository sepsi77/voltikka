<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Observed hourly weather per point from the Open-Meteo archive (ERA5) for own spot price
     * models: wind at the neighbour wind-power points. Column names are the Open-Meteo hourly
     * variable names. Rows are upserted, because ERA5 preliminary data (ERA5T) can be revised.
     * See app/Services/OpenMeteo/AGENTS.md.
     */
    public function up(): void
    {
        Schema::create('weather_hourly_observations', function (Blueprint $table) {
            $table->id();
            $table->string('point', 40); // key in config/open_meteo.php neighbour_wind_points
            $table->string('model', 30); // e.g. era5
            $table->unsignedBigInteger('timestamp'); // UTC hour start, unix seconds
            $table->dateTime('utc_datetime'); // UTC text, like spot_prices_hour
            // Instantaneous values at the indicated hour (m/s).
            $table->double('wind_speed_10m')->nullable();
            $table->double('wind_speed_100m')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['point', 'model', 'timestamp'], 'weather_hourly_point_model_timestamp_unique');
            $table->index(['model', 'timestamp'], 'weather_hourly_model_timestamp_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_hourly_observations');
    }
};
