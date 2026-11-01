<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hourly means of Fingrid open-data series (config/fingrid.php) for market reviews.
     * Rows are upserted, because Fingrid corrects data after publication.
     */
    public function up(): void
    {
        Schema::create('fingrid_hourly_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('dataset_id');
            // '' for a plain series; "UserGroup=BE02" style for Datahub breakdowns.
            $table->string('series_key', 100)->default('');
            $table->unsignedBigInteger('timestamp'); // UTC hour start, unix seconds
            $table->dateTime('utc_datetime'); // UTC text, like spot_prices_hour
            $table->double('value');
            $table->unsignedSmallInteger('sample_count');
            $table->unsignedInteger('point_count')->nullable(); // Datahub accounting points
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['dataset_id', 'series_key', 'timestamp'], 'fingrid_hourly_dataset_series_timestamp_unique');
            $table->index(['dataset_id', 'utc_datetime'], 'fingrid_hourly_dataset_utc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingrid_hourly_values');
    }
};
