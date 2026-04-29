<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_price_daily_statistics', function (Blueprint $table) {
            $table->decimal('median_value', 12, 4)->nullable()->after('avg_value');
        });
    }

    public function down(): void
    {
        Schema::table('contract_price_daily_statistics', function (Blueprint $table) {
            $table->dropColumn('median_value');
        });
    }
};
