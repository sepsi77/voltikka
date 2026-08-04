<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_order_clicks', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->timestamp('occurred_at')->index();
            $table->string('contract_id');
            $table->string('contract_name');
            $table->string('company_name');
            $table->decimal('annual_price_eur', 12, 2)->nullable();
            $table->unsignedInteger('consumption_kwh');
            $table->unsignedInteger('price_rank')->nullable();
            $table->unsignedInteger('rank_total')->nullable();
            $table->unsignedInteger('rank_consumption_kwh')->nullable();
            $table->boolean('is_estimate');
            $table->string('pricing_basis', 64)->nullable();
            $table->string('cta_location', 16);
            $table->string('session_source', 100);
            $table->string('session_medium', 100);
            $table->string('session_campaign', 150)->nullable();
            $table->string('landing_path', 500);
            $table->string('page_path', 500);
            $table->timestamp('created_at');

            $table->index(['company_name', 'occurred_at'], 'contract_clicks_company_time_idx');
            $table->index(['contract_id', 'occurred_at'], 'contract_clicks_contract_time_idx');
            $table->index(['contract_name', 'occurred_at'], 'contract_clicks_name_time_idx');
            $table->index(['session_source', 'occurred_at'], 'contract_clicks_source_time_idx');
            $table->index(['session_medium', 'occurred_at'], 'contract_clicks_medium_time_idx');
            $table->index(['session_campaign', 'occurred_at'], 'contract_clicks_campaign_time_idx');
            $table->index(['cta_location', 'occurred_at'], 'contract_clicks_cta_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_order_clicks');
    }
};
