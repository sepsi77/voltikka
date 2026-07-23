<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_interpretations', function (Blueprint $table) {
            $table->id();
            $table->string('contract_id');
            $table->unsignedBigInteger('source_snapshot_id');
            $table->char('analysis_fingerprint', 64)->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->string('schema_version', 64);
            $table->string('prompt_version', 64);
            $table->string('provider', 64);
            $table->string('model');
            $table->json('output')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('published_fields')->nullable();
            $table->boolean('relational_pricing_published')->default(false);
            $table->json('usage')->nullable();
            $table->string('provider_response_id')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign('contract_id')
                ->references('id')
                ->on('electricity_contracts')
                ->onDelete('cascade');
            $table->foreign('source_snapshot_id')
                ->references('id')
                ->on('contract_source_snapshots')
                ->onDelete('cascade');
            $table->index(['contract_id', 'published_at']);
        });

        Schema::table('electricity_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('published_interpretation_id')
                ->nullable()
                ->index();
            $table->json('canonical_pricing')->nullable();
            $table->json('canonical_source_consistency')->nullable();
            $table->json('canonical_calculation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('electricity_contracts', function (Blueprint $table) {
            $table->dropColumn([
                'published_interpretation_id',
                'canonical_pricing',
                'canonical_source_consistency',
                'canonical_calculation',
            ]);
        });

        Schema::dropIfExists('contract_interpretations');
    }
};
