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
        Schema::create('dsos', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('name_slug');
            $table->timestamps();

            $table->index('name_slug');
        });

        Schema::create('contract_dso', function (Blueprint $table) {
            $table->string('contract_id');
            $table->unsignedBigInteger('dso_id');

            $table->primary(['contract_id', 'dso_id']);

            $table->foreign('contract_id')
                ->references('id')
                ->on('electricity_contracts')
                ->onDelete('cascade');

            $table->foreign('dso_id')
                ->references('id')
                ->on('dsos')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_dso');
        Schema::dropIfExists('dsos');
    }
};
