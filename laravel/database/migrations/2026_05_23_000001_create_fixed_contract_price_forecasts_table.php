<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_contract_price_forecasts', function (Blueprint $table) {
            $table->id();
            $table->date('forecast_date');
            $table->date('target_date');
            $table->unsignedSmallInteger('horizon_days');
            $table->unsignedSmallInteger('duration_months');
            $table->string('target_quantile', 16);
            $table->decimal('current_price_cents_per_kwh', 8, 4);
            $table->decimal('forecast_price_cents_per_kwh', 8, 4);
            $table->decimal('expected_change_cents_per_kwh', 8, 4);
            $table->decimal('interval_low_cents_per_kwh', 8, 4)->nullable();
            $table->decimal('interval_high_cents_per_kwh', 8, 4)->nullable();
            $table->decimal('hedge_cost_cents_per_kwh', 8, 4);
            $table->decimal('retail_premium_cents_per_kwh', 8, 4);
            $table->decimal('normal_retail_premium_cents_per_kwh', 8, 4);
            $table->decimal('fair_price_cents_per_kwh', 8, 4);
            $table->decimal('gap_cents_per_kwh', 8, 4);
            $table->date('futures_trade_date');
            $table->string('coverage_quality', 64);
            $table->string('confidence', 16);
            $table->string('direction', 32);
            $table->string('consumer_signal', 32);
            $table->unsignedSmallInteger('contract_count')->nullable();
            $table->string('model_version', 64);
            $table->json('source_metadata')->nullable();
            $table->decimal('actual_price_cents_per_kwh', 8, 4)->nullable();
            $table->decimal('actual_change_cents_per_kwh', 8, 4)->nullable();
            $table->decimal('forecast_error_cents_per_kwh', 8, 4)->nullable();
            $table->decimal('absolute_error_cents_per_kwh', 8, 4)->nullable();
            $table->string('actual_direction', 32)->nullable();
            $table->boolean('direction_correct')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['forecast_date', 'horizon_days', 'duration_months', 'target_quantile', 'model_version'],
                'fixed_contract_forecasts_unique'
            );
            $table->index(['target_date', 'actual_price_cents_per_kwh'], 'fixed_contract_forecasts_evaluation_idx');
            $table->index(['forecast_date', 'duration_months'], 'fixed_contract_forecasts_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_contract_price_forecasts');
    }
};
