<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_feedback', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->timestamp('occurred_at')->index();
            $table->string('page_type', 32);
            $table->string('page_path', 500);
            $table->string('subject_id')->nullable();
            // Null only on a page without a vote (404 opens at the goal step).
            $table->string('vote', 8)->nullable();
            $table->string('reason_code', 48)->nullable();
            $table->unsignedTinyInteger('reason_position')->nullable();
            $table->text('reason_text')->nullable();
            $table->string('goal_code', 32)->nullable();
            $table->unsignedTinyInteger('goal_position')->nullable();
            $table->text('goal_text')->nullable();
            $table->string('device', 16);
            // Listing facts at the time of the vote (listing pages only).
            $table->unsignedInteger('consumption_kwh')->nullable();
            $table->string('pricing_filter', 100)->nullable();
            $table->unsignedInteger('result_count')->nullable();
            $table->boolean('postcode_selected')->nullable();
            $table->boolean('bill_mode')->nullable();
            $table->unsignedInteger('listing_page')->nullable();
            $table->string('session_source', 100);
            $table->string('session_medium', 100);
            $table->string('session_campaign', 150)->nullable();
            $table->string('landing_path', 500);
            $table->timestamps();

            $table->index(['page_type', 'occurred_at'], 'page_feedback_type_time_idx');
            $table->index(['vote', 'occurred_at'], 'page_feedback_vote_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_feedback');
    }
};
