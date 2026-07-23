<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_source_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('contract_id');
            $table->char('source_fingerprint', 64);
            $table->json('source_payload');
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');

            $table->unique(
                ['contract_id', 'source_fingerprint'],
                'contract_source_snapshots_contract_fingerprint_unique'
            );
            $table->foreign('contract_id')
                ->references('id')
                ->on('electricity_contracts')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_source_snapshots');
    }
};
