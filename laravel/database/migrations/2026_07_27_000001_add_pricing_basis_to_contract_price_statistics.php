<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_price_snapshots', function (Blueprint $table) {
            $table->string('pricing_basis', 40)
                ->default('observed_seller_data')
                ->after('segment_key')
                ->index();
        });

        Schema::table('contract_price_daily_statistics', function (Blueprint $table) {
            $table->string('pricing_basis', 40)
                ->default('observed_seller_data')
                ->after('metric_key')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('contract_price_daily_statistics', function (Blueprint $table) {
            $table->dropIndex(['pricing_basis']);
            $table->dropColumn('pricing_basis');
        });

        Schema::table('contract_price_snapshots', function (Blueprint $table) {
            $table->dropIndex(['pricing_basis']);
            $table->dropColumn('pricing_basis');
        });
    }
};
