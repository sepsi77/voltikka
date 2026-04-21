<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_percentiles', function (Blueprint $table) {
            $table->id();
            $table->string('component', 50)->unique(); // e.g. 'spot_margin', 'fixed_energy', 'monthly_fee'
            $table->decimal('p15', 10, 4);
            $table->decimal('p85', 10, 4);
            $table->integer('count'); // how many contracts in the distribution
            $table->timestamp('calculated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_percentiles');
    }
};