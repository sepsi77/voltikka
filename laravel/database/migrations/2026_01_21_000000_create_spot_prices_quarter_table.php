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
        Schema::create('spot_prices_quarter', function (Blueprint $table) {
            $table->string('region', 10);
            $table->integer('timestamp');  // Unix timestamp
            $table->dateTime('utc_datetime');
            $table->decimal('price_without_tax', 10, 4);
            $table->decimal('vat_rate', 5, 4);

            $table->primary(['region', 'timestamp']);
            $table->index(['region', 'utc_datetime']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spot_prices_quarter');
    }
};
