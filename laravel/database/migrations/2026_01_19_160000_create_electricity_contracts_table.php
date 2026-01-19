<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for the electricity_contracts table and contract_postcode pivot table.
 *
 * NOTE: This migration documents the existing PostgreSQL schema.
 * The tables already exist in the production database - do NOT run
 * this migration against the existing database.
 *
 * Ported from: legacy/python/shared/voltikka/database/models.py (ElectricityContract model)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only create if the table doesn't exist (for fresh installs/testing)
        if (!Schema::hasTable('electricity_contracts')) {
            Schema::create('electricity_contracts', function (Blueprint $table) {
                // Primary key is a string ID (not auto-incrementing)
                $table->string('id')->primary();

                // Foreign key to companies table
                $table->string('company_name');
                $table->foreign('company_name')
                    ->references('name')
                    ->on('companies')
                    ->onDelete('cascade');

                // Basic contract information
                $table->string('name')->index();
                $table->string('name_slug')->index();
                $table->string('contract_type')->index();
                $table->string('spot_price_selection')->nullable();
                $table->string('fixed_time_range')->nullable()->index();
                $table->string('metering')->index();

                // Descriptions
                $table->text('short_description')->nullable();
                $table->text('long_description')->nullable();

                // Pricing information
                $table->string('pricing_name')->nullable();
                $table->boolean('pricing_has_discounts')->nullable()->index();

                // Consumption control
                $table->boolean('consumption_control')->nullable();
                $table->float('consumption_limitation_min_x_kwh_per_y')->nullable();
                $table->float('consumption_limitation_max_x_kwh_per_y')->nullable();

                // Contract features
                $table->boolean('pre_billing')->nullable();
                $table->boolean('available_for_existing_users')->nullable();
                $table->boolean('delivery_responsibility_product')->nullable();
                $table->string('order_link')->nullable();
                $table->string('product_link')->nullable();

                // JSON fields for complex data (jsonb in PostgreSQL, json in other DBs)
                $table->json('billing_frequency')->nullable();
                $table->json('time_period_definitions')->nullable();
                $table->json('transparency_index')->nullable();

                // Extra information in multiple languages
                $table->text('extra_information_default')->nullable();
                $table->text('extra_information_fi')->nullable();
                $table->text('extra_information_en')->nullable();
                $table->text('extra_information_sv')->nullable();

                // Availability
                $table->boolean('availability_is_national')->index();

                // Microproduction fields
                $table->boolean('microproduction_buys')->nullable()->index();
                $table->text('microproduction_default')->nullable();
                $table->text('microproduction_fi')->nullable();
                $table->text('microproduction_sv')->nullable();
                $table->text('microproduction_en')->nullable();

                // Note: No timestamps in the original schema
            });
        }

        // Create the contract_postcode pivot table for many-to-many relationship
        if (!Schema::hasTable('contract_postcode')) {
            Schema::create('contract_postcode', function (Blueprint $table) {
                $table->string('contract_id');
                $table->string('postcode')->index();

                // Composite primary key
                $table->primary(['contract_id', 'postcode']);

                // Foreign keys
                $table->foreign('contract_id')
                    ->references('id')
                    ->on('electricity_contracts')
                    ->onDelete('cascade');

                // Note: postcode foreign key will reference postcodes table
                // when that table is created
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_postcode');
        Schema::dropIfExists('electricity_contracts');
    }
};
