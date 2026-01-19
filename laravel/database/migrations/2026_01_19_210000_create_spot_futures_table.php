<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for the spot_futures table.
 *
 * Stores spot futures price data from the Azure Consumer API.
 * These are predicted future spot prices used for estimation.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('spot_futures')) {
            Schema::create('spot_futures', function (Blueprint $table) {
                $table->date('date');
                $table->float('price');

                // Composite primary key
                $table->primary(['date', 'price']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spot_futures');
    }
};
