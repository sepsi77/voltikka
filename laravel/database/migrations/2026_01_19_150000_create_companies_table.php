<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for the companies table.
 *
 * NOTE: This migration documents the existing PostgreSQL schema.
 * The table already exists in the production database - do NOT run
 * this migration against the existing database.
 *
 * Ported from: legacy/python/shared/voltikka/database/models.py (Company model)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only create if the table doesn't exist (for fresh installs/testing)
        if (!Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table) {
                // Primary key is the company name (not auto-incrementing ID)
                $table->string('name')->primary();
                $table->string('name_slug')->index();
                $table->string('company_url')->nullable();
                $table->string('street_address')->nullable()->index();
                $table->string('postal_code')->nullable()->index();
                $table->string('postal_name')->nullable()->index();
                $table->string('logo_url')->nullable();

                // Note: No timestamps in the original schema
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
