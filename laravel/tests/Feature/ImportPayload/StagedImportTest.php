<?php

namespace Tests\Feature\ImportPayload;

use App\Console\Concerns\StagedImport;
use App\Services\ImportPayload\ImportFetchFailed;
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

        $this->assertEqualsCanonicalizing(['status', 'step', 'payload', 'complete', 'counts'], array_keys($line));
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
        $this->assertEqualsCanonicalizing(['path', 'sha256', 'size', 'fetched_at', 'source', 'run_id', 'requests', 'failed_units', 'complete'], array_keys($fetched['payload']));
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

        $payloads = glob(config('import_payloads.path').'/probe/2026-11-19/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');
        $opened = ['payload_path' => $payloads[0], 'payload_sha256' => hash_file('sha256', $payloads[0])];
        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'store_failed', ...$opened,
            'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'staged_probe_rows',
        ], $line);
        $this->assertStringNotContainsString('binding-secret-value', Artisan::output());
        $this->assertSame([], $this->rows(), 'A failed store writes no row of the run (S21).');
        $this->assertSame([], StagedProbeCommand::$afterCommitLevels);
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($opened): bool {
            $this->assertSame('Import failed: staged:probe', $message);
            $this->assertSame([
                'command' => 'staged:probe', 'step' => 'store', 'error' => 'store_failed',
                'exception_class' => UniqueConstraintViolationException::class, ...$opened,
                'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'staged_probe_rows',
            ], $context);

            return true;
        });

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

    public function test_a_fetch_failed_after_recording_keeps_its_payload_and_reports_the_fetch_step(): void
    {
        Log::spy();
        Http::fake([self::URL.'*' => Http::response('{"error":"down"}', 503)]);
        StagedProbeCommand::$allFailed = true;

        $this->assertSame(1, Artisan::call('staged:probe'));

        $payloads = glob(config('import_payloads.path').'/probe/2026-11-19/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The recorded error responses stay for diagnosis.');
        $sha256 = hash_file('sha256', $payloads[0]);
        $this->assertSame([
            'status' => 'failed', 'step' => 'fetch', 'error' => 'fetch_failed', 'reason' => 'all_failed',
            'payload_path' => $payloads[0], 'payload_sha256' => $sha256,
        ], $this->lastJsonLine());
        $this->assertSame($sha256, trim(file_get_contents(str_replace('.ndjson.gz', '.sha256', $payloads[0]))));
        $reader = ImportPayloadReader::open($payloads[0]);
        $requests = iterator_to_array($reader->requests(), false);
        $this->assertSame([503, '{"error":"down"}'], [$requests[0]['status'], ImportPayloadReader::body($requests[0])]);
        $this->assertSame(['failed' => 1], $reader->end()['facts']);
        $this->assertFalse($reader->complete());
        $this->assertSame([], $this->rows());
        Log::shouldHaveReceived('error')->once()->with('Import failed: staged:probe', [
            'command' => 'staged:probe', 'step' => 'fetch', 'error' => 'fetch_failed',
            'exception_class' => ImportFetchFailed::class,
            'reason' => 'all_failed', 'payload_path' => $payloads[0], 'payload_sha256' => $sha256,
        ]);
    }

    public function test_from_payload_refuses_wrong_sha_options_source_and_missing_files(): void
    {
        $this->fakeRows();
        Artisan::call('staged:probe', ['--fetch-only' => true, '--label' => 'one']);
        $payload = $this->lastJsonLine()['payload'];

        // Refusals before the file is verified name no payload; later ones name the verified file.
        $this->assertRefusal('sha256_mismatch', ['--from-payload' => $payload['path'], '--payload-sha256' => str_repeat('a', 64), '--label' => 'one']);
        $this->assertRefusal('options_mismatch', ['--from-payload' => $payload['path']], $payload);
        $this->assertRefusal('missing', ['--from-payload' => $payload['path'].'.gone']);

        $other = ImportPayloadWriter::open('probe', 'other:command');
        $other->record([], Http::get(self::URL));
        $otherReference = $other->finish();
        $this->assertRefusal('wrong_source', ['--from-payload' => $otherReference->path], $otherReference->toArray());

        // A header without source or command is an unfinished file, not an undefined-key error.
        foreach (['source', 'command'] as $missing) {
            $path = $this->rewrittenHeader($payload['path'], fn (array $header) => array_diff_key($header, [$missing => true]));
            $this->assertRefusal('incomplete', ['--from-payload' => $path, '--label' => 'one']);
        }

        // A refusal the store step decides (for example the date rule) also rolls back and is closed.
        StagedProbeCommand::$refuseDate = true;
        $this->assertRefusal('wrong_date', ['--from-payload' => $payload['path'], '--label' => 'one'], $payload);
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
        $this->assertSame([$line['payload']['path'], $line['payload']['sha256']], [$line['payload_path'], $line['payload_sha256']]);
        $this->assertFileExists($line['payload_path']);
        $this->assertCount(2, $this->rows());
    }

    public function test_an_after_commit_failure_names_the_payload(): void
    {
        $this->fakeRows();
        StagedProbeCommand::$afterCommitThrows = true;

        $this->assertSame(1, Artisan::call('staged:probe'));

        $payloads = glob(config('import_payloads.path').'/probe/2026-11-19/*.ndjson.gz');
        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'after_commit_failed',
            'payload_path' => $payloads[0], 'payload_sha256' => hash_file('sha256', $payloads[0]),
        ], $this->lastJsonLine());
        $this->assertCount(2, $this->rows(), 'The committed rows stay.');
    }

    public function test_a_replay_refuses_a_payload_superseded_by_a_newer_complete_payload_of_the_same_command_and_options(): void
    {
        $this->fakeRows();
        $older = $this->fetchOnly('2026-11-19 06:00:00');
        // The next Helsinki date directory is scanned as well.
        $newer = $this->fetchOnly('2026-11-20 06:00:00');
        $this->assertNotSame(dirname($older['path']), dirname($newer['path']));

        Http::preventStrayRequests();
        $this->assertRefusal('superseded', ['--from-payload' => $older['path']], $older);
        $this->assertSame([], $this->rows(), 'A superseded payload writes nothing.');
        $this->assertFileExists($older['path'], 'The superseded payload stays.');

        // The newest payload itself is accepted, so a re-store after a fix works.
        $this->assertSame(0, Artisan::call('staged:probe', ['--from-payload' => $newer['path']]));
        $this->assertSame([['a', '2026-11-20T06:00:00Z'], ['b', '2026-11-20T06:00:00Z']], $this->rows());
    }

    public function test_a_replay_ignores_newer_payloads_of_other_options_other_commands_and_unfinished_files(): void
    {
        $this->fakeRows();
        $payload = $this->fetchOnly('2026-11-19 06:00:00');
        $this->fetchOnly('2026-11-19 07:00:00', ['--label' => 'other']);
        Carbon::setTestNow(Carbon::parse('2026-11-19 08:00:00', 'UTC'));
        $other = ImportPayloadWriter::open('probe', 'other:command', ['label' => 'default']);
        $other->record(['page' => 1], Http::get(self::URL));
        $other->finish();
        // A newer file without its .sha256 file never finished.
        $unfinished = $this->fetchOnly('2026-11-19 09:00:00');
        unlink(substr($unfinished['path'], 0, -strlen('.ndjson.gz')).'.sha256');

        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('staged:probe', ['--from-payload' => $payload['path']]));
        $this->assertSame([['a', '2026-11-19T06:00:00Z'], ['b', '2026-11-19T06:00:00Z']], $this->rows());
    }

    public function test_a_newer_all_failed_payload_does_not_supersede_but_a_newer_partial_one_does(): void
    {
        $this->fakeRows();
        $payload = $this->fetchOnly('2026-11-19 06:00:00');

        // 07:00: every request failed; the trait keeps the file, but it holds nothing usable.
        Carbon::setTestNow(Carbon::parse('2026-11-19 07:00:00', 'UTC'));
        StagedProbeCommand::$allFailed = true;
        $this->assertSame(1, Artisan::call('staged:probe'));
        $allFailed = $this->lastJsonLine()['payload_path'];
        $this->assertSame([1, 1], [ImportPayloadReader::open($allFailed)->header()['requests'], ImportPayloadReader::open($allFailed)->header()['failed_units']]);
        StagedProbeCommand::$allFailed = false;

        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('staged:probe', ['--from-payload' => $payload['path']]));
        $this->assertSame('stored', $this->lastJsonLine()['status']);

        // 08:00: one of two requests failed; a partial payload is still newer data.
        Carbon::setTestNow(Carbon::parse('2026-11-19 08:00:00', 'UTC'));
        $partial = ImportPayloadWriter::open('probe', 'staged:probe', ['label' => 'default']);
        $partial->record(['page' => 1], Http::get(self::URL));
        $partial->record(['page' => 2], Http::get(self::URL), failed: true);
        $partial->finish();
        $this->assertRefusal('superseded', ['--from-payload' => $payload['path']], $payload);
    }

    public function test_a_newer_fetch_that_failed_as_a_whole_does_not_supersede_even_with_answered_pages(): void
    {
        $this->fakeRows();
        $payload = $this->fetchOnly('2026-11-19 06:00:00');

        Carbon::setTestNow(Carbon::parse('2026-11-19 07:00:00', 'UTC'));
        StagedProbeCommand::$allFailed = StagedProbeCommand::$partialFailedFetch = true;
        $this->assertSame(1, Artisan::call('staged:probe'));
        $failed = ImportPayloadReader::open($this->lastJsonLine()['payload_path'])->header();
        $this->assertSame([2, 1, true], [$failed['requests'], $failed['failed_units'], $failed['facts']['fetch_failed']]);
        StagedProbeCommand::$allFailed = StagedProbeCommand::$partialFailedFetch = false;

        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('staged:probe', ['--from-payload' => $payload['path']]));
        $this->assertSame('stored', $this->lastJsonLine()['status']);
    }

    public function test_default_mode_and_fetch_only_do_not_check_for_newer_payloads(): void
    {
        $this->fakeRows();
        $this->fetchOnly('2026-11-19 07:00:00');
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));

        // The clock is behind the stored 07:00 payload: default mode still stores its own fetch.
        $this->assertSame(0, Artisan::call('staged:probe'));
        $this->assertSame('stored', $this->lastJsonLine()['status']);
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string, mixed> the fetch JSON line's payload object
     */
    private function fetchOnly(string $now, array $options = []): array
    {
        Carbon::setTestNow(Carbon::parse($now, 'UTC'));
        $this->assertSame(0, Artisan::call('staged:probe', ['--fetch-only' => true, ...$options]));

        return $this->lastJsonLine()['payload'];
    }

    /** A complete copy of a payload (with its .sha256 file) whose header line is changed. */
    private function rewrittenHeader(string $path, \Closure $change): string
    {
        $lines = explode("\n", gzdecode(file_get_contents($path)));
        $lines[0] = json_encode($change(json_decode($lines[0], true)), JSON_UNESCAPED_SLASHES);
        $copy = config('import_payloads.path').'/probe/rewritten-'.md5($lines[0]).'.ndjson.gz';
        file_put_contents($copy, gzencode(implode("\n", $lines)));
        file_put_contents(substr($copy, 0, -strlen('.ndjson.gz')).'.sha256', hash_file('sha256', $copy)."\n");

        return $copy;
    }

    private function fakeRows(): void
    {
        Http::fake([self::URL.'*' => Http::response(['a', 'b'])]);
    }

    /** @param  array<string, mixed>|null  $opened  the payload reference when the refusal came after the file was verified */
    private function assertRefusal(string $reason, array $options, ?array $opened = null): void
    {
        $this->assertSame(1, Artisan::call('staged:probe', $options), $reason);
        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => $reason,
            ...($opened === null ? [] : ['payload_path' => $opened['path'], 'payload_sha256' => $opened['sha256']]),
        ], $this->lastJsonLine());
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

    public static bool $allFailed = false;

    public static bool $partialFailedFetch = false;

    public static bool $afterCommitThrows = false;

    /** @var list<int> */
    public static array $afterCommitLevels = [];

    public static int $storeTransactionLevel = 0;

    protected $signature = 'staged:probe {--label=default}';

    public static function reset(): void
    {
        self::$duplicateRow = self::$resultError = null;
        self::$refuseDate = self::$allFailed = self::$partialFailedFetch = self::$afterCommitThrows = false;
        self::$afterCommitLevels = [];
        self::$storeTransactionLevel = 0;
    }

    public function handle(): int
    {
        return $this->runStaged(
            function (ImportPayloadWriter $payload): void {
                if (self::$allFailed) {
                    if (self::$partialFailedFetch) {
                        // An answered page, then the fetch fails as a whole (ENTSO-E outage paging).
                        $payload->record(['page' => 0], Http::get('https://probe.test/rows'));
                    }
                    $payload->record(['page' => 1], Http::get('https://probe.test/rows'), failed: true);

                    throw ImportFetchFailed::because('all_failed', ['failed' => 1]);
                }
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
                    [fn () => self::$afterCommitThrows ? throw new \RuntimeException('after commit') : self::$afterCommitLevels[] = DB::transactionLevel()],
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
