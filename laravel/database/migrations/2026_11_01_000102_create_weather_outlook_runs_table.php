<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only weather outlook vintages (ECMWF ensemble, EC46, SEAS5 via Open-Meteo), one row
     * per product, point and distinct payload, so that an outlook can be scored later.
     */
    public function up(): void
    {
        Schema::create('weather_outlook_runs', function (Blueprint $table) {
            $table->id();
            $table->string('product', 30); // key in config/open_meteo.php outlooks
            $table->string('model', 40); // Open-Meteo model name
            $table->string('point', 40);
            $table->char('payload_hash', 64);
            $table->dateTime('model_run_at')->nullable(); // UTC text, model initialisation time
            $table->timestamp('fetched_at');
            $table->date('first_date'); // Europe/Helsinki local day of members[*][*][0]
            $table->unsignedSmallInteger('day_count');
            $table->unsignedSmallInteger('member_count'); // control + perturbed members
            $table->double('grid_latitude');
            $table->double('grid_longitude');
            // {"variable": [[control day values...], [member01...], ...]}; null = no value.
            $table->json('members');
            $table->timestamps();

            $table->unique(['product', 'point', 'payload_hash'], 'weather_outlook_product_point_hash_unique');
            $table->index(['product', 'point', 'fetched_at'], 'weather_outlook_product_point_fetched_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_outlook_runs');
    }
};
