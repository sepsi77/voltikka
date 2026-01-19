<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for the active_contracts table.
 *
 * This table tracks which contracts are currently active (available for purchase).
 * It is cleared and repopulated during each contract fetch.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('active_contracts')) {
            Schema::create('active_contracts', function (Blueprint $table) {
                // Primary key references electricity_contracts
                $table->string('id')->primary();

                $table->foreign('id')
                    ->references('id')
                    ->on('electricity_contracts')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('active_contracts');
    }
};
