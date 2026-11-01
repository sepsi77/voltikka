<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily gas storage levels from GIE AGSI+ (config/gas_storage.php) for market reviews.
     * Rows are upserted, because GIE corrects estimated days after publication.
     */
    public function up(): void
    {
        Schema::create('gas_storage_daily_values', function (Blueprint $table) {
            $table->id();
            $table->string('area_code', 8); // config key: eu, de
            $table->date('gas_day'); // AGSI gasDayStart text (gas day starts 06:00 CET)
            $table->double('gas_in_storage_twh')->nullable();
            $table->double('working_gas_volume_twh')->nullable();
            $table->double('full_pct')->nullable();
            $table->double('trend_pct')->nullable();
            $table->double('injection_gwh')->nullable(); // GWh/d
            $table->double('withdrawal_gwh')->nullable(); // GWh/d
            $table->double('net_withdrawal_gwh')->nullable(); // GWh/d
            $table->string('status', 1)->nullable(); // E estimated, C confirmed, N no data
            $table->string('source_updated_at', 32)->nullable(); // AGSI updatedAt text
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['area_code', 'gas_day'], 'gas_storage_daily_area_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gas_storage_daily_values');
    }
};
