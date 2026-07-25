<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retail_premium_observations', function (Blueprint $table) {
            $table->id();
            $table->char('observation_key', 64);
            $table->char('price_signature', 64);
            $table->char('lineage_key', 64);
            $table->string('lineage_contract_id');
            $table->string('contract_id');
            $table->unsignedBigInteger('published_interpretation_id')->nullable();
            $table->unsignedBigInteger('source_snapshot_id')->nullable();
            $table->string('company_name');
            $table->string('pricing_model', 32);
            $table->string('cadence', 32)->nullable();
            $table->string('contract_type', 32);
            $table->string('target_group', 32)->nullable();
            $table->string('metering', 32);
            $table->unsignedSmallInteger('phase_index')->default(0);
            $table->string('phase_kind', 32)->nullable();
            $table->string('phase_label')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('first_observed_date');
            $table->date('last_observed_date');
            $table->json('energy_components')->nullable();
            $table->string('energy_component_type', 48)->nullable();
            $table->decimal('retail_energy_price_cents_per_kwh', 9, 4)->nullable();
            $table->decimal('monthly_fee_eur', 9, 4)->nullable();
            $table->string('vat_basis', 16);
            $table->unsignedInteger('reference_consumption_kwh');
            $table->string('reference_kind', 32);
            $table->date('reference_trade_date')->nullable();
            $table->decimal('reference_price_cents_per_kwh', 9, 4)->nullable();
            $table->decimal('retail_premium_cents_per_kwh', 9, 4)->nullable();
            $table->decimal('retail_premium_with_fee_cents_per_kwh', 9, 4)->nullable();
            $table->string('method_version', 32);
            $table->string('quality', 32);
            $table->json('quality_flags')->nullable();
            $table->json('source_metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['observation_key', 'reference_kind', 'method_version'],
                'retail_premium_observations_unique'
            );
            $table->index(['company_name', 'first_observed_date'], 'retail_premium_company_date_idx');
            $table->index(['lineage_key', 'first_observed_date'], 'retail_premium_lineage_date_idx');
            $table->index(['pricing_model', 'quality', 'reference_kind'], 'retail_premium_analysis_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retail_premium_observations');
    }
};
