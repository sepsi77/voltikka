<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spot_social_publications', function (Blueprint $table) {
            $table->id();
            $table->date('content_date')->unique();
            $table->string('status', 24);
            $table->unsignedInteger('attempt_count')->default(1);
            $table->timestamp('data_as_of');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('postfast_video_key')->nullable();
            $table->unsignedInteger('posted_count')->nullable();
            $table->json('skipped_platforms')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_social_publications');
    }
};
