<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NVE min/median/max reservoir filling per area and ISO week over a twenty-year period.
     * The period is part of the key, so the reference of each year stays when NVE moves on.
     */
    public function up(): void
    {
        Schema::create('nve_reservoir_week_references', function (Blueprint $table) {
            $table->id();
            $table->string('area_type', 8);
            $table->unsignedTinyInteger('area_no');
            $table->unsignedTinyInteger('iso_week'); // week 53 uses only the years that have one
            $table->unsignedSmallInteger('reference_from_year');
            $table->unsignedSmallInteger('reference_to_year');
            $table->double('min_ratio')->nullable();
            $table->double('min_twh')->nullable();
            $table->double('median_ratio');
            $table->double('median_twh')->nullable();
            $table->double('max_ratio')->nullable();
            $table->double('max_twh')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(
                ['area_type', 'area_no', 'iso_week', 'reference_from_year', 'reference_to_year'],
                'nve_reservoir_references_area_week_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nve_reservoir_week_references');
    }
};
