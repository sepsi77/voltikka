<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for the spot_prices_hour table.
 *
 * Stores hourly Nord Pool spot prices for Finland.
 * This matches the existing PostgreSQL table structure from the Python models.
 *
 * Note: This migration is designed to work with an existing database
 * and will NOT modify the table if it already exists.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('spot_prices_hour')) {
            Schema::create('spot_prices_hour', function (Blueprint $table) {
                // Composite primary key: region + timestamp
                $table->string('region');
                $table->integer('timestamp');

                // UTC datetime for easier querying
                $table->timestampTz('utc_datetime')->index();

                // Price in c/kWh (before VAT)
                $table->float('price_without_tax');

                // VAT rate (0.24 standard, 0.10 temporary Dec 2022 - Apr 2023)
                $table->float('vat_rate');

                // Composite primary key
                $table->primary(['region', 'timestamp']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spot_prices_hour');
    }
};
