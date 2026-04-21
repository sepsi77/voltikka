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
        Schema::table('electricity_contracts', function (Blueprint $table) {
            $table->string('replaced_by_contract_id')->nullable()->after('api_id')->index();
            $table->foreign('replaced_by_contract_id')
                ->references('id')
                ->on('electricity_contracts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('electricity_contracts', function (Blueprint $table) {
            $table->dropForeign(['replaced_by_contract_id']);
            $table->dropColumn('replaced_by_contract_id');
        });
    }
};
