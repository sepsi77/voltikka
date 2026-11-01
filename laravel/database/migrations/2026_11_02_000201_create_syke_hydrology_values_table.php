<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Syke areal hydrology values (config/syke.php) as slow spot model features.
     * Rows are upserted, because Syke corrects data. See app/Services/Syke/AGENTS.md.
     */
    public function up(): void
    {
        Schema::create('syke_hydrology_values', function (Blueprint $table) {
            $table->id();
            $table->string('series', 40); // precipitation_5d, snow_water_equivalent
            $table->unsignedInteger('place_id'); // Syke Paikka_Id
            // Syke Aika date. precipitation_5d: first day of the 5-day period (covers D..D+4,
            // the period of the 26th runs to the month end). snow_water_equivalent: state on D.
            $table->date('date');
            $table->double('value'); // mm
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['series', 'place_id', 'date'], 'syke_hydrology_series_place_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syke_hydrology_values');
    }
};
