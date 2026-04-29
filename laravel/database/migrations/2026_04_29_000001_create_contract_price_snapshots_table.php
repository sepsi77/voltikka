<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_price_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->index();
            $table->string('contract_id');
            $table->string('company_name')->index();
            $table->string('contract_name');
            $table->string('pricing_model')->nullable()->index();
            $table->string('contract_type')->nullable()->index();
            $table->string('fixed_time_range')->nullable()->index();
            $table->string('metering')->nullable();
            $table->string('segment_key', 80)->index();
            $table->decimal('energy_price_cents_per_kwh', 10, 4)->nullable();
            $table->decimal('spot_margin_cents_per_kwh', 10, 4)->nullable();
            $table->decimal('spot_total_energy_price_cents_per_kwh', 10, 4)->nullable();
            $table->decimal('monthly_fee_eur', 10, 4)->nullable();
            $table->decimal('annual_cost_2000_kwh', 12, 4)->nullable();
            $table->decimal('annual_cost_5000_kwh', 12, 4)->nullable();
            $table->decimal('annual_cost_18000_kwh', 12, 4)->nullable();
            $table->boolean('has_discount')->default(false);
            $table->boolean('includes_spot_price')->default(false);
            $table->timestamps();

            $table->unique(['snapshot_date', 'contract_id'], 'contract_price_snapshots_date_contract_unique');
            $table->foreign('contract_id')
                ->references('id')
                ->on('electricity_contracts')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_price_snapshots');
    }
};
