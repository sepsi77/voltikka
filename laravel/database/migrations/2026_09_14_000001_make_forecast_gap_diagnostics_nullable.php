<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_contract_price_forecasts', function (Blueprint $table) {
            foreach (['hedge_cost', 'retail_premium', 'normal_retail_premium', 'fair_price', 'gap'] as $field) {
                $table->decimal($field.'_cents_per_kwh', 8, 4)->nullable()->change();
            }
            $table->date('futures_trade_date')->nullable()->change();
            $table->string('coverage_quality', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Irreversible loosening: retain all old values and new null diagnostics.
        // Restoring NOT NULL would require deleting or inventing historical data.
    }
};
