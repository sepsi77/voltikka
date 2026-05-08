<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('price_components', function (Blueprint $table) {
            $table->index(
                ['electricity_contract_id', 'price_component_type', 'price_date'],
                'price_components_latest_calc_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('price_components', function (Blueprint $table) {
            $table->dropIndex('price_components_latest_calc_idx');
        });
    }
};
