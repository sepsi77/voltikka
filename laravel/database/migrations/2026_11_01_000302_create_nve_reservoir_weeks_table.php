<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NVE weekly reservoir filling (Magasinstatistikk) per area and ISO week, for market reviews.
     * Rows are upserted, because NVE can revise a week.
     */
    public function up(): void
    {
        Schema::create('nve_reservoir_weeks', function (Blueprint $table) {
            $table->id();
            $table->string('area_type', 8); // NO = Norway total, EL = elspot zone, VASS = watercourse area
            $table->unsignedTinyInteger('area_no'); // 0 for NO; 1-5 for NO1-NO5
            $table->unsignedSmallInteger('iso_year');
            $table->unsignedTinyInteger('iso_week');
            $table->string('week_end_date', 10); // NVE dato_Id: the Sunday that ends the ISO week
            $table->double('filling_ratio'); // 0..1
            $table->double('filling_twh')->nullable();
            $table->double('capacity_twh')->nullable();
            $table->double('previous_week_ratio')->nullable();
            $table->double('change_ratio')->nullable(); // ratio points versus the previous week
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['area_type', 'area_no', 'iso_year', 'iso_week'], 'nve_reservoir_weeks_area_week_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nve_reservoir_weeks');
    }
};
