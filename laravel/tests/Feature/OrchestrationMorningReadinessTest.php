<?php

namespace Tests\Feature;

use App\Models\DataFreshnessCheckpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OrchestrationMorningReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prints_the_status_of_each_morning_checkpoint_for_the_date(): void
    {
        DataFreshnessCheckpoint::query()->create(['key' => 'eex_futures', 'effective_date' => '2026-11-09', 'status' => 'ready', 'recorded_at' => now()]);
        DataFreshnessCheckpoint::query()->create(['key' => 'contract_import', 'effective_date' => '2026-11-09', 'status' => 'pending_completion', 'recorded_at' => now()]);
        DataFreshnessCheckpoint::query()->create(['key' => 'morning_retail', 'effective_date' => '2026-11-08', 'status' => 'ready', 'recorded_at' => now()]);

        $this->assertSame(0, Artisan::call('orchestration:readiness', ['pipeline' => 'morning', '--date' => '2026-11-09']));
        $this->assertSame([
            'date' => '2026-11-09',
            'checkpoints' => ['eex_futures' => 'ready', 'contract_import' => 'pending_completion', 'morning_retail' => null, 'morning_forecast' => null],
            'scheduled_complete' => ['morning_retail' => false, 'morning_forecast' => false],
        ], json_decode(trim(Artisan::output()), true));
    }

    public function test_only_a_completed_scheduled_run_certifies_a_consumer(): void
    {
        // A successful manual subset run leaves `ready` with scheduled_complete=false.
        DataFreshnessCheckpoint::query()->create(['key' => 'morning_retail', 'effective_date' => '2026-11-09', 'status' => 'ready',
            'metadata' => ['reason' => 'completed', 'scheduled_complete' => false], 'recorded_at' => now()]);
        DataFreshnessCheckpoint::query()->create(['key' => 'morning_forecast', 'effective_date' => '2026-11-09', 'status' => 'ready',
            'metadata' => ['reason' => 'completed', 'scheduled_complete' => true], 'recorded_at' => now()]);

        $this->assertSame(0, Artisan::call('orchestration:readiness', ['pipeline' => 'morning', '--date' => '2026-11-09']));
        $result = json_decode(trim(Artisan::output()), true);
        $this->assertSame(['morning_retail' => 'ready', 'morning_forecast' => 'ready'],
            array_intersect_key($result['checkpoints'], array_flip(['morning_retail', 'morning_forecast'])));
        $this->assertSame(['morning_retail' => false, 'morning_forecast' => true], $result['scheduled_complete']);
    }

    public function test_a_failed_row_or_a_row_without_the_key_is_not_complete(): void
    {
        DataFreshnessCheckpoint::query()->create(['key' => 'morning_retail', 'effective_date' => '2026-11-09', 'status' => 'failed',
            'metadata' => ['reason' => 'execution_failed', 'scheduled_complete' => false], 'recorded_at' => now()]);
        DataFreshnessCheckpoint::query()->create(['key' => 'morning_forecast', 'effective_date' => '2026-11-09', 'status' => 'ready',
            'metadata' => [], 'recorded_at' => now()]);

        $this->assertSame(0, Artisan::call('orchestration:readiness', ['pipeline' => 'morning', '--date' => '2026-11-09']));
        $this->assertSame(['morning_retail' => false, 'morning_forecast' => false], json_decode(trim(Artisan::output()), true)['scheduled_complete']);
    }

    public function test_an_invalid_date_fails_with_a_closed_error_key(): void
    {
        $this->assertSame(1, Artisan::call('orchestration:readiness', ['pipeline' => 'morning', '--date' => '2026-02-30']));
        $this->assertSame('invalid_arguments', json_decode(trim(Artisan::output()), true)['error']);
    }
}
