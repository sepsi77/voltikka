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

                // Finnish name fields
                $table->string('postcode_fi_name')->nullable()->index();
                $table->string('postcode_fi_name_slug')->nullable();
                $table->string('postcode_abbr_fi')->nullable();

                // Swedish name fields
                $table->string('postcode_sv_name')->nullable()->index();
                $table->string('postcode_sv_name_slug')->nullable();
                $table->string('postcode_abbr_sv')->nullable();

                // Area fields
                $table->string('type_code')->nullable();
                $table->string('ad_area_code')->nullable();
                $table->string('ad_area_fi')->nullable();
                $table->string('ad_area_fi_slug')->nullable();
                $table->string('ad_area_sv')->nullable();
                $table->string('ad_area_sv_slug')->nullable();

                // Municipality fields
                $table->string('municipal_code')->nullable()->index();
                $table->string('municipal_name_fi')->nullable()->index();
                $table->string('municipal_name_fi_slug')->nullable();
                $table->string('municipal_name_sv')->nullable()->index();
                $table->string('municipal_name_sv_slug')->nullable();
                $table->string('municipal_language_ratio_code')->nullable();

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
