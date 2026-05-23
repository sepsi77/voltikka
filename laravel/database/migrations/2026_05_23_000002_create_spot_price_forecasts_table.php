<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('spot_price_forecasts', function (Blueprint $table) {
            $table->id();
            $table->string('source', 80);
            $table->string('region', 10)->default('FI');
            $table->unsignedBigInteger('timestamp');
            $table->dateTime('utc_datetime');
            $table->decimal('price_with_tax', 10, 4);
            $table->decimal('vat_rate', 6, 4)->default(0.2550);
            $table->string('source_url')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source', 'region', 'timestamp'], 'spot_forecasts_source_region_timestamp_unique');
            $table->index(['region', 'utc_datetime'], 'spot_forecasts_region_utc_datetime_index');
            $table->index(['source', 'fetched_at'], 'spot_forecasts_source_fetched_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spot_price_forecasts');
    }
};
