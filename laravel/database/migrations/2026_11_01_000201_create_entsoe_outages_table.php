<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTSO-E unavailability (REMIT) document versions for market reviews: A77 production units,
     * A78 transmission, A80 generation units. Insert-only per document version and TimeSeries.
     */
    public function up(): void
    {
        Schema::create('entsoe_outages', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 4); // A77, A78, A80
            // Bidding zone (FI, SE1...) for A77/A80; border FROM_TO (SE1_FI) for A78.
            $table->string('zone', 16);
            $table->string('document_mrid', 64);
            $table->unsignedInteger('revision_number');
            $table->string('time_series_mrid', 16);
            $table->string('business_type', 4)->nullable(); // A53 planned, A54 unplanned
            $table->string('doc_status', 4)->nullable(); // null = active; A09 cancelled
            $table->boolean('is_cancelled')->default(false);
            $table->dateTime('document_created_at')->nullable(); // UTC text
            $table->dateTime('start_utc'); // UTC text
            $table->dateTime('end_utc'); // UTC text
            $table->unsignedBigInteger('start_timestamp');
            $table->unsignedBigInteger('end_timestamp');
            $table->string('resource_mrid', 32)->nullable();
            $table->string('resource_name', 150)->nullable();
            $table->string('location_name', 150)->nullable();
            $table->string('psr_type', 4)->nullable(); // B14 nuclear, B21/B22 AC/DC link...
            $table->string('unit_mrid', 32)->nullable(); // A80 generation unit
            $table->string('unit_name', 150)->nullable();
            $table->double('nominal_mw')->nullable();
            $table->double('min_available_mw')->nullable();
            $table->json('available_periods'); // [{start, end, mw}] constant segments, UTC
            $table->json('assets')->nullable(); // A78 transmission assets
            $table->json('reasons')->nullable(); // [{code, text}], TimeSeries first
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['document_mrid', 'revision_number', 'time_series_mrid'], 'entsoe_outages_version_unique');
            $table->index(['zone', 'psr_type', 'start_timestamp'], 'entsoe_outages_zone_psr_start_index');
            $table->index(['document_type', 'end_timestamp'], 'entsoe_outages_type_end_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entsoe_outages');
    }
};
