<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Own tables of the own spot price forecast code (forecast/ package, model s_v1; see
     * tasks/own-forecast-model-data/s-model-freeze.md). Only the Python model commands write
     * them (agreement with the Temporal orchestration, 2026-11-07); nothing public reads them yet.
     * Vintages are append-only: one per model and issue day, never rewritten. All datetimes are
     * UTC text; target_date is the Europe/Helsinki day of the target hour.
     */
    public function up(): void
    {
        // One row per fitted model version. The file (a pickle) is in the private archive bucket;
        // the model code loads it only when its SHA-256 and scikit-learn version match this row.
        Schema::create('forecast_model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('model', 20);
            $table->string('version', 60)->unique();
            $table->date('refit_monday');
            $table->string('object_key', 200);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sklearn_version', 16);
            $table->char('code_sha256', 64);
            $table->json('fit_facts');
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['model', 'is_active'], 'forecast_model_versions_active_index');
        });

        Schema::create('forecast_vintages', function (Blueprint $table) {
            $table->id();
            $table->string('model', 20);
            $table->foreignId('model_version_id')->constrained('forecast_model_versions');
            $table->date('issue_date');
            $table->dateTime('morning_cut');
            $table->dateTime('afternoon_cut');
            $table->dateTime('started_at'); // the real run start (a record, never an input)
            $table->dateTime('finished_at');
            $table->char('code_sha256', 64);
            $table->json('inputs'); // latest stored time or count per input
            $table->timestamps();

            $table->unique(['model', 'issue_date'], 'forecast_vintages_model_issue_unique');
        });

        Schema::create('forecast_vintage_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vintage_id')->constrained('forecast_vintages');
            $table->unsignedBigInteger('target_timestamp'); // UTC hour start, unix seconds
            $table->dateTime('target_utc_datetime');
            $table->date('target_date');
            $table->unsignedTinyInteger('target_day'); // j of D+j
            $table->double('p10');
            $table->double('p50');
            $table->double('p90'); // EUR/MWh, VAT 0

            $table->unique(['vintage_id', 'target_timestamp'], 'forecast_vintage_values_unique');
            $table->index('target_timestamp', 'forecast_vintage_values_target_index');
        });

        // Raw model part predictions: with a price (y) they are the miss store of the range
        // calibration. Seeded rows (source E30/E32) are the frozen backtest misses.
        Schema::create('forecast_part_predictions', function (Blueprint $table) {
            $table->id();
            $table->string('model', 20);
            $table->string('part', 20);
            $table->string('view', 10); // morning, afternoon
            $table->string('source', 10); // live, E30, E32
            $table->foreignId('vintage_id')->nullable()->constrained('forecast_vintages');
            $table->date('issue_date');
            $table->unsignedBigInteger('target_timestamp');
            $table->date('target_date');
            $table->unsignedTinyInteger('k');
            $table->unsignedTinyInteger('month');
            $table->double('p10')->nullable();
            $table->double('p50')->nullable();
            $table->double('p90')->nullable();
            $table->double('y')->nullable();
            $table->dateTime('scored_at')->nullable();
            $table->timestamps();

            $table->unique(['model', 'part', 'view', 'issue_date', 'target_timestamp'], 'forecast_part_predictions_unique');
            $table->index(['model', 'target_timestamp'], 'forecast_part_predictions_target_index');
        });

        Schema::create('forecast_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vintage_id')->constrained('forecast_vintages');
            $table->unsignedBigInteger('target_timestamp');
            $table->date('target_date');
            $table->unsignedTinyInteger('target_day');
            $table->double('y');
            $table->double('p10');
            $table->double('p50');
            $table->double('p90');
            $table->double('thirdparty_p50')->nullable(); // latest third-party vintage before D 14:30
            $table->foreignId('thirdparty_run_id')->nullable()->constrained('spot_price_forecast_runs');
            $table->dateTime('scored_at');
            $table->timestamps();

            $table->unique(['vintage_id', 'target_timestamp'], 'forecast_scores_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_scores');
        Schema::dropIfExists('forecast_part_predictions');
        Schema::dropIfExists('forecast_vintage_values');
        Schema::dropIfExists('forecast_vintages');
        Schema::dropIfExists('forecast_model_versions');
    }
};
