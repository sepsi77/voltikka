<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only vintages of hourly forecasts (FMI, Open-Meteo, Fingrid) for own spot price
     * models. One row per source, product, point and distinct payload: a model must be trained
     * and scored on the forecast that existed at decision time, never on a later revision.
     */
    public function up(): void
    {
        Schema::create('hourly_forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20); // fmi, open_meteo, fingrid
            $table->string('product', 40); // source-specific product key
            $table->string('point', 40); // point key, or '' for a whole-area series
            $table->char('payload_hash', 64); // SHA-256 of first_timestamp + values
            $table->dateTime('issued_at')->nullable(); // UTC text, source issue/model time if known
            $table->timestamp('fetched_at');
            $table->unsignedBigInteger('first_timestamp'); // UTC hour start of index 0, unix seconds
            $table->dateTime('first_utc_datetime'); // UTC text of index 0
            $table->unsignedSmallInteger('hour_count');
            $table->double('grid_latitude')->nullable();
            $table->double('grid_longitude')->nullable();
            // {"variable": [value per UTC hour from first_timestamp]}; null = no value.
            $table->json('values');
            $table->timestamps();

            $table->unique(['source', 'product', 'point', 'payload_hash'], 'hourly_forecast_runs_identity_unique');
            $table->index(['source', 'product', 'point', 'fetched_at'], 'hourly_forecast_runs_fetched_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hourly_forecast_runs');
    }
};
