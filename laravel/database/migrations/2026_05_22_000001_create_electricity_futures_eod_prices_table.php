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
        Schema::create('electricity_futures_eod_prices', function (Blueprint $table) {
            $table->id();
            $table->string('exchange', 16)->default('EEX');
            $table->string('commodity', 32)->default('POWER');
            $table->string('pricing', 8)->default('F');
            $table->string('product', 32)->default('Base');
            $table->string('market_region', 64)->nullable();
            $table->string('area', 16);
            $table->string('area_name', 128)->nullable();
            $table->string('short_code', 16);
            $table->string('maturity', 16);
            $table->string('maturity_type', 16)->default('year');
            $table->unsignedSmallInteger('display_year')->nullable();
            $table->unsignedTinyInteger('display_season')->nullable();
            $table->unsignedTinyInteger('display_quarter')->nullable();
            $table->unsignedTinyInteger('display_month')->nullable();
            $table->unsignedTinyInteger('display_week')->nullable();
            $table->date('display_day')->nullable();
            $table->date('trade_date');
            $table->decimal('settlement_price', 12, 4);
            $table->decimal('volume', 18, 4)->nullable();
            $table->decimal('lot_size', 18, 4)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('unit', 16)->nullable();
            $table->string('long_name', 255)->nullable();
            $table->date('last_update')->nullable();
            $table->timestamps();

            $table->unique(
                ['exchange', 'commodity', 'pricing', 'product', 'area', 'short_code', 'maturity', 'trade_date'],
                'electricity_futures_eod_unique'
            );
            $table->index(['area', 'maturity', 'trade_date'], 'electricity_futures_eod_area_maturity_date_idx');
            $table->index('trade_date', 'electricity_futures_eod_trade_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('electricity_futures_eod_prices');
    }
};
