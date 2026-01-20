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
        Schema::create('spot_price_averages', function (Blueprint $table) {
            $table->id();

            // Period identification
            $table->string('region', 10)->default('FI');
            $table->string('period_type', 20); // daily, monthly, yearly, rolling_30d, rolling_365d
            $table->date('period_start');
            $table->date('period_end')->nullable(); // Used for rolling averages

            // Average prices (c/kWh)
            $table->decimal('avg_price_without_tax', 10, 4);
            $table->decimal('avg_price_with_tax', 10, 4);

            // Day/night averages for time-based metering (07:00-22:00 / 22:00-07:00)
            $table->decimal('day_avg_without_tax', 10, 4)->nullable();
            $table->decimal('day_avg_with_tax', 10, 4)->nullable();
            $table->decimal('night_avg_without_tax', 10, 4)->nullable();
            $table->decimal('night_avg_with_tax', 10, 4)->nullable();

            // Min/max prices (useful for daily/monthly stats)
            $table->decimal('min_price_without_tax', 10, 4)->nullable();
            $table->decimal('max_price_without_tax', 10, 4)->nullable();

            // Number of hours in the average
            $table->integer('hours_count');

            $table->timestamps();

            // Unique constraint: one record per region, period_type, period_start
            $table->unique(['region', 'period_type', 'period_start'], 'spot_avg_unique');

            // Indexes for common queries
            $table->index(['period_type', 'period_start']);
            $table->index('period_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spot_price_averages');
    }
};
