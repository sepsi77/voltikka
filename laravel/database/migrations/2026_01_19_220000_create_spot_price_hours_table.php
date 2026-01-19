<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for the spot_price_hours table.
 *
 * Stores hourly Nord Pool spot prices for Finland.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('spot_price_hours')) {
            Schema::create('spot_price_hours', function (Blueprint $table) {
                // Primary key is the timestamp
                $table->timestampTz('time')->primary();

                // Price in c/kWh (before VAT)
                $table->float('price');

                // VAT rate (computed column in PostgreSQL, stored in SQLite)
                $table->float('vat')->default(0.24);

                // Price with tax (computed column in PostgreSQL, stored in SQLite)
                $table->float('price_with_tax')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spot_price_hours');
    }
};
