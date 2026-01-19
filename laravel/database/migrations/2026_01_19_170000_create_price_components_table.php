<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for the price_components table.
 *
 * NOTE: This migration documents the existing PostgreSQL schema.
 * The table already exists in the production database - do NOT run
 * this migration against the existing database.
 *
 * Ported from: legacy/python/shared/voltikka/database/models.py (PriceComponent model)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only create if the table doesn't exist (for fresh installs/testing)
        if (!Schema::hasTable('price_components')) {
            Schema::create('price_components', function (Blueprint $table) {
                // Composite primary key (id, price_date)
                $table->string('id');
                $table->date('price_date');
                $table->primary(['id', 'price_date']);

                // Price component type
                $table->string('price_component_type')->index();
                $table->string('fuse_size')->nullable();

                // Foreign key to electricity_contracts table
                $table->string('electricity_contract_id');
                $table->foreign('electricity_contract_id')
                    ->references('id')
                    ->on('electricity_contracts')
                    ->onDelete('cascade');

                // Discount fields
                $table->boolean('has_discount')->nullable();
                $table->float('discount_value')->nullable();
                $table->boolean('discount_is_percentage')->nullable();
                $table->string('discount_type')->nullable();
                $table->float('discount_discount_n_first_kwh')->nullable();
                $table->integer('discount_discount_n_first_months')->nullable();
                $table->dateTime('discount_discount_until_date')->nullable();

                // Pricing
                $table->float('price');
                $table->string('payment_unit')->nullable();

                // Note: No timestamps in the original schema
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_components');
    }
};
