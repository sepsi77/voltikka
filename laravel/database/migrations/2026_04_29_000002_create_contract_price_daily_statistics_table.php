<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_price_daily_statistics', function (Blueprint $table) {
            $table->id();
            $table->date('stat_date')->index();
            $table->string('segment_key', 80)->index();
            $table->string('metric_key', 80)->index();
            $table->integer('consumption_kwh')->nullable()->index();
            $table->decimal('min_value', 12, 4)->nullable();
            $table->decimal('p20_value', 12, 4)->nullable();
            $table->decimal('avg_value', 12, 4)->nullable();
            $table->decimal('p80_value', 12, 4)->nullable();
            $table->decimal('max_value', 12, 4)->nullable();
            $table->integer('contract_count');
            $table->timestamps();

            $table->unique(['stat_date', 'segment_key', 'metric_key', 'consumption_kwh'], 'contract_price_daily_stats_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_price_daily_statistics');
    }
};
