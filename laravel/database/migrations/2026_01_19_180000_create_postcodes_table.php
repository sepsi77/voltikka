<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for the postcodes table.
 *
 * NOTE: This migration documents the existing PostgreSQL schema.
 * The table already exists in the production database - do NOT run
 * this migration against the existing database.
 *
 * Ported from: legacy/python/shared/voltikka/database/models.py (Postcode model)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only create if the table doesn't exist (for fresh installs/testing)
        if (!Schema::hasTable('postcodes')) {
            Schema::create('postcodes', function (Blueprint $table) {
                // Primary key is the postcode string
                $table->string('postcode')->primary();

                // Finnish/Swedish names
                $table->string('postcode_name')->nullable()->index();
                $table->string('postcode_name_sv')->nullable()->index();

                // Municipality data
                $table->string('municipality_code')->nullable()->index();
                $table->string('municipality_name')->nullable()->index();
                $table->string('municipality_name_sv')->nullable()->index();

                // Note: No timestamps in the original schema
            });

            // Add the foreign key to contract_postcode table after postcodes table is created
            Schema::table('contract_postcode', function (Blueprint $table) {
                // Check if the foreign key doesn't already exist
                if (!Schema::hasColumn('contract_postcode', 'postcode')) {
                    return;
                }
                $table->foreign('postcode')
                    ->references('postcode')
                    ->on('postcodes')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove foreign key from contract_postcode before dropping postcodes
        if (Schema::hasTable('contract_postcode')) {
            Schema::table('contract_postcode', function (Blueprint $table) {
                $table->dropForeign(['postcode']);
            });
        }

        Schema::dropIfExists('postcodes');
    }
};
