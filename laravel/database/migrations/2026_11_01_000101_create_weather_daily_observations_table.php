<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Observed daily weather per point from the Open-Meteo archive (ERA5) for market reviews.
     * Column names are the Open-Meteo daily variable names. Rows are upserted, because ERA5
     * preliminary data (ERA5T) can be revised. See app/Services/OpenMeteo/AGENTS.md.
     */
    public function up(): void
    {
        Schema::create('weather_daily_observations', function (Blueprint $table) {
            $table->id();
            $table->string('point', 40); // key in config/open_meteo.php points
            $table->string('model', 30); // e.g. era5
            $table->date('date'); // Europe/Helsinki local day
            $table->double('temperature_2m_mean')->nullable(); // °C
            $table->double('temperature_2m_min')->nullable();
            $table->double('temperature_2m_max')->nullable();
            $table->double('wind_speed_10m_mean')->nullable(); // m/s
            $table->double('wind_speed_10m_max')->nullable();
            $table->double('wind_speed_100m_mean')->nullable();
            $table->double('wind_speed_100m_max')->nullable();
            $table->double('precipitation_sum')->nullable(); // mm
            $table->double('snowfall_sum')->nullable(); // cm
            $table->double('snowfall_water_equivalent_sum')->nullable(); // mm
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['point', 'model', 'date'], 'weather_daily_point_model_date_unique');
            $table->index(['model', 'date'], 'weather_daily_model_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_daily_observations');
    }
};
