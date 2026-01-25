<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('postcodes', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('municipal_language_ratio_code');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            $table->index(['latitude', 'longitude'], 'postcodes_coordinates_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('postcodes', function (Blueprint $table) {
            $table->dropIndex('postcodes_coordinates_index');
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
