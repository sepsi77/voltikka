<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_interpretations', function (Blueprint $table) {
            $table->json('llm_attempts')->nullable()->after('validation_errors');
        });
    }

    public function down(): void
    {
        Schema::table('contract_interpretations', function (Blueprint $table) {
            $table->dropColumn('llm_attempts');
        });
    }
};
