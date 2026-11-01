<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hourly JAO Nordic day-ahead maxBorderFlow per Finnish border direction (config/jao.php)
     * for market reviews. Rows are upserted, because a rerun can bring a later JAO version.
     */
    public function up(): void
    {
        Schema::create('jao_max_border_flows', function (Blueprint $table) {
            $table->id();
            $table->string('border', 16); // from zone _ to zone, e.g. SE1_FI = import into FI
            $table->unsignedBigInteger('timestamp'); // UTC hour start, unix seconds
            $table->dateTime('utc_datetime'); // UTC text, like spot_prices_hour
            $table->double('capacity_mw'); // mean of the MTUs in the hour
            $table->double('min_capacity_mw'); // lowest MTU in the hour
            $table->unsignedTinyInteger('sample_count'); // MTUs: 1 before 2025-10-01, then 4
            $table->string('last_modified_on', 32)->nullable(); // JAO lastModifiedOn as given
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['border', 'timestamp'], 'jao_max_border_flows_border_timestamp_unique');
            $table->index('utc_datetime', 'jao_max_border_flows_utc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jao_max_border_flows');
    }
};
