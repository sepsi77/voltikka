<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_freshness_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80);
            $table->date('effective_date');
            $table->string('status', 24);
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['key', 'effective_date'], 'data_freshness_key_date_unique');
            $table->index(['status', 'effective_date'], 'data_freshness_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_freshness_checkpoints');
    }
};
