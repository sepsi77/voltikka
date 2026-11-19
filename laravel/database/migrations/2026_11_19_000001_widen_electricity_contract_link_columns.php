<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seller links come verbatim from the Azure API and have no length limit. On 2026-11-19 Helen
     * sent three 455-character product links; MySQL strict mode rejected the varchar(255) row and
     * the whole contract import transaction rolled back. Both seller URL columns become text.
     */
    public function up(): void
    {
        Schema::table('electricity_contracts', function (Blueprint $table) {
            $table->text('order_link')->nullable()->change();
            $table->text('product_link')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('electricity_contracts', function (Blueprint $table) {
            $table->string('order_link')->nullable()->change();
            $table->string('product_link')->nullable()->change();
        });
    }
};
