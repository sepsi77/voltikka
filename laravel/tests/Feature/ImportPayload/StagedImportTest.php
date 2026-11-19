<?php

namespace Tests\Feature\ImportPayload;

use App\Console\Concerns\StagedImport;
use App\Services\ImportPayload\ImportPayloadInvalid;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StagedImportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://probe.test/rows';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        Schema::create('staged_probe_rows', function (Blueprint $table): void {
            $table->string('value')->unique();
            $table->string('observed_at');
        });
        StagedProbeCommand::reset();
        $this->app[Kernel::class]->registerCommand(new StagedProbeCommand);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_mode_fetches_then_stores_from_the_written_file_and_prints_one_json_line(): void
    {
        $this->fakeRows();
        $this->assertSame(0, Artisan::call('staged:probe'));
        $line = $this->lastJsonLine();

        $this->assertSame(['status', 'step', 'payload', 'complete', 'counts'], array_keys($line));
        $this->assertSame(['stored', 'store', true, ['rows' => 2]], [$line['status'], $line['step'], $line['complete'], $line['counts']]);
        $this->assertFileExists($line['payload']['path']);
        $this->assertSame(hash_file('sha256', $line['payload']['path']), $line['payload']['sha256']);
        $this->assertStringNotContainsString('probe-key', gzdecode(file_get_contents($line['payload']['path'])));
        $this->assertSame([['a', '2026-11-19T06:00:00Z'], ['b', '2026-11-19T06:00:00Z']], $this->rows());
        $this->assertSame([DB::transactionLevel()], StagedProbeCommand::$afterCommitLevels, 'After-commit work runs outside the store transaction.');
        $this->assertSame(1, StagedProbeCommand::$storeTransactionLevel - DB::transactionLevel());
    }

    public function test_fetch_only_writes_no_rows_and_from_payload_stores_the_same_rows_without_http(): void
    {
        $this->fakeRows();
        $this->assertSame(0, Artisan::call('staged:probe', ['--fetch-only' => true]));
        $fetched = $this->lastJsonLine();
        $this->assertSame(['fetched', 'fetch'], [$fetched['status'], $fetched['step']]);
        $this->assertSame(['path', 'sha256', 'size', 'fetched_at', 'source', 'run_id', 'requests', 'failed_units', 'complete'], array_keys($fetched['payload']));
        $this->assertSame(['2026-11-19T06:00:00Z', 'probe', 1, 0, true], [
            $fetched['payload']['fetched_at'], $fetched['payload']['source'], $fetched['payload']['requests'],
            $fetched['payload']['failed_units'], $fetched['payload']['complete'],
        ]);
        $this->assertSame([], $this->rows());

        // The store keeps the fetch time (S5) although it runs later, and makes no request.
        Carbon::setTestNow(Carbon::parse('2026-11-19 08:00:00', 'UTC'));
        Http::preventStrayRequests();
        Http::fake();
        $this->assertSame(0, Artisan::call('staged:probe', [
            '--from-payload' => $fetched['payload']['path'], '--payload-sha256' => $fetched['payload']['sha256'],
        ]));
        $this->assertSame('stored', $this->lastJsonLine()['status']);
        $this->assertSame([['a', '2026-11-19T06:00:00Z'], ['b', '2026-11-19T06:00:00Z']], $this->rows());
        Http::assertNothingSent();
    }

    public function test_store_query_exception_rolls_back_the_run_reports_safe_sql_facts_and_the_payload_replays(): void
    {
        Log::spy();
        $this->fakeRows();
        StagedProbeCommand::$duplicateRow = 'binding-secret-value';

        $this->assertSame(1, Artisan::call('staged:probe'));
        $line = $this->lastJsonLine();

        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', 'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'staged_probe_rows'], $line);
        $this->assertStringNotContainsString('binding-secret-value', Artisan::output());
        $this->assertSame([], $this->rows(), 'A failed store writes no row of the run (S21).');
        $this->assertSame([], StagedProbeCommand::$afterCommitLevels);
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Import failed: staged:probe', $message);
            $this->assertSame([
                'command' => 'staged:probe', 'step' => 'store', 'error' => 'store_failed',
                'exception_class' => UniqueConstraintViolationException::class,
                'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'staged_probe_rows',
            ], $context);

            return true;
        });

        $payloads = glob(config('import_payloads.path').'/probe/2026-11-19/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        StagedProbeCommand::$duplicateRow = null; // the fix
        Http::preventStrayRequests();
        Http::fake();
        $this->assertSame(0, Artisan::call('staged:probe', ['--from-payload' => $payloads[0]]));
        $this->assertSame(['a', 'b'], array_column($this->rows(), 0));
        Http::assertNothingSent();
    }

    public function test_a_failed_fetch_leaves_no_payload_and_reports_the_fetch_step(): void
    {
        Log::spy();
        Http::fake([self::URL.'*' => Http::response('down', 503)]);

        $this->assertSame(1, Artisan::call('staged:probe'));

        $this->assertSame(['status' => 'failed', 'step' => 'fetch', 'error' => 'fetch_failed'], $this->lastJsonLine());
        $this->assertSame([], glob(config('import_payloads.path').'/probe/2026-11-19/*'));
        Log::shouldHaveReceived('error')->once()->with('Import failed: staged:probe', [
            'command' => 'staged:probe', 'step' => 'fetch', 'error' => 'fetch_failed',
            'exception_class' => RequestException::class,
        ]);
    }

    public function test_from_payload_refuses_wrong_sha_options_source_and_missing_files(): void
    {
        $this->fakeRows();
        Artisan::call('staged:probe', ['--fetch-only' => true, '--label' => 'one']);
        $payload = $this->lastJsonLine()['payload'];

        $this->assertRefusal('sha256_mismatch', ['--from-payload' => $payload['path'], '--payload-sha256' => str_repeat('a', 64), '--label' => 'one']);
        $this->assertRefusal('options_mismatch', ['--from-payload' => $payload['path']]);
        $this->assertRefusal('missing', ['--from-payload' => $payload['path'].'.gone']);

        $other = ImportPayloadWriter::open('probe', 'other:command');
        $other->record([], Http::get(self::URL));
        $this->assertRefusal('wrong_source', ['--from-payload' => $other->finish()->path]);

        // A refusal the store step decides (for example the date rule) also rolls back and is closed.
        StagedProbeCommand::$refuseDate = true;
        $this->assertRefusal('wrong_date', ['--from-payload' => $payload['path'], '--label' => 'one']);
        $this->assertSame([], $this->rows());

        $this->assertSame(1, Artisan::call('staged:probe', ['--fetch-only' => true, '--from-payload' => $payload['path']]));
        $this->assertSame(['status' => 'failed', 'step' => 'fetch', 'error' => 'invalid_options'], $this->lastJsonLine());
    }

    public function test_a_closed_store_result_error_keeps_the_rows_and_exits_one(): void
    {
        $this->fakeRows();
        StagedProbeCommand::$resultError = 'fetch_incomplete';

        $this->assertSame(1, Artisan::call('staged:probe'));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete', ['rows' => 2]], [$line['status'], $line['step'], $line['error'], $line['counts']]);
        $this->assertCount(2, $this->rows());
    }

    private function fakeRows(): void
    {
        Http::fake([self::URL.'*' => Http::response(['a', 'b'])]);
    }

    private function assertRefusal(string $reason, array $options): void
    {
        $this->assertSame(1, Artisan::call('staged:probe', $options), $reason);
        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => $reason], $this->lastJsonLine());
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(): array
    {
        $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<array{0: string, 1: string}> */
    private function rows(): array
    {
        return DB::table('staged_probe_rows')->orderBy('value')->get()->map(fn ($row) => [$row->value, $row->observed_at])->all();
    }
}

class StagedProbeCommand extends Command
{
    use StagedImport;

    public static ?string $duplicateRow = null;

    public static ?string $resultError = null;

    public static bool $refuseDate = false;

    /** @var list<int> */
    public static array $afterCommitLevels = [];

    public static int $storeTransactionLevel = 0;

    protected $signature = 'staged:probe {--label=default}';

    public static function reset(): void
    {
        self::$duplicateRow = self::$resultError = null;
        self::$refuseDate = false;
        self::$afterCommitLevels = [];
        self::$storeTransactionLevel = 0;
    }

    public function handle(): int
    {
        return $this->runStaged(
            function (ImportPayloadWriter $payload): void {
                $payload->record(['page' => 1], Http::get('https://probe.test/rows', ['api_key' => 'probe-key'])->throw());
            },
            function (ImportPayloadReader $payload): ImportStoreResult {
                if (self::$refuseDate) {
                    throw ImportPayloadInvalid::because('wrong_date');
                }
                self::$storeTransactionLevel = DB::transactionLevel();
                $values = [];
                foreach ($payload->requests() as $request) {
                    $values = [...$values, ...json_decode(ImportPayloadReader::body($request), true)];
                }
                $observedAt = $payload->fetchedAt()->format('Y-m-d\TH:i:s\Z');
                foreach ([...$values, ...(self::$duplicateRow !== null ? [self::$duplicateRow, self::$duplicateRow] : [])] as $value) {
                    DB::table('staged_probe_rows')->insert(['value' => $value, 'observed_at' => $observedAt]);
                }

                return new ImportStoreResult(
                    ['rows' => count($values)],
                    [fn () => self::$afterCommitLevels[] = DB::transactionLevel()],
                    self::$resultError,
                    self::$resultError === 'fetch_incomplete' ? 'fetch' : 'store',
                );
            },
        );
    }

    protected function stagedImportSource(): string
    {
        return 'probe';
    }
}
