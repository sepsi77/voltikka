<?php

namespace Tests\Feature\ImportPayload;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ImportPayloadCleanupTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        // 19 November 12:00 Helsinki: date directories from 5 November on are kept (14 days).
        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00', 'Europe/Helsinki'));
        $this->base = config('import_payloads.path');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_reports_and_apply_deletes_expired_payloads_old_tmp_files_and_empty_directories(): void
    {
        $now = Carbon::now()->getTimestamp();
        $files = [
            'fingrid/2026-11-04/a.ndjson.gz' => $now,
            'fingrid/2026-11-04/a.sha256' => $now,
            'fingrid/2026-11-05/b.ndjson.gz' => $now - 20 * 86400,
            'fingrid/2026-11-05/b.sha256' => $now,
            'fingrid/2026-11-19/c.ndjson.gz.tmp' => $now - 25 * 3600,
            'fingrid/2026-11-19/d.body.ndjson.gz.tmp' => $now - 23 * 3600,
            'fingrid/2026-11-19/e.ndjson.gz' => $now,
            'fingrid/notes/readme.txt' => $now - 400 * 86400,
            'jao/2026-10-01/f.ndjson.gz' => $now,
            'jao/2026-10-01/f.sha256.tmp' => $now,
            'jao/2026-10-01/other.json' => $now,
            'fmi/2026-10-30/g.ndjson.gz' => $now,
        ];
        foreach ($files as $path => $mtime) {
            @mkdir(dirname("{$this->base}/{$path}"), 0775, true);
            file_put_contents("{$this->base}/{$path}", 'x');
            touch("{$this->base}/{$path}", $mtime);
        }

        $this->assertSame(0, Artisan::call('import-payloads:cleanup'));
        $output = Artisan::output();
        $this->assertStringContainsString('Dry run: payload_files=4 tmp_files=1 directories=3', $output);
        $this->assertStringEndsWith("\n".'{"status":"dry_run","payload_files":4,"tmp_files":1,"directories":3}', trim($output));
        foreach (array_keys($files) as $path) {
            $this->assertFileExists("{$this->base}/{$path}", 'A dry run deletes nothing.');
        }

        $this->assertSame(0, Artisan::call('import-payloads:cleanup', ['--apply' => true]));
        $this->assertStringEndsWith("\n".'{"status":"deleted","payload_files":4,"tmp_files":1,"directories":3}', trim(Artisan::output()));

        $remaining = collect(array_keys($files))->filter(fn (string $path) => file_exists("{$this->base}/{$path}"))->values()->all();
        $this->assertSame([
            'fingrid/2026-11-05/b.ndjson.gz', // by its date directory, not its file time
            'fingrid/2026-11-05/b.sha256',
            'fingrid/2026-11-19/d.body.ndjson.gz.tmp', // under 24 hours: a fetch can still be writing it
            'fingrid/2026-11-19/e.ndjson.gz',
            'fingrid/notes/readme.txt',
            'jao/2026-10-01/f.sha256.tmp',
            'jao/2026-10-01/other.json', // not a payload file: kept, and so is its directory
        ], $remaining);
        $this->assertDirectoryDoesNotExist("{$this->base}/fingrid/2026-11-04");
        $this->assertDirectoryDoesNotExist("{$this->base}/fmi");
        $this->assertDirectoryExists("{$this->base}/jao/2026-10-01");
    }

    public function test_a_missing_payload_directory_is_not_an_error(): void
    {
        $this->assertSame(0, Artisan::call('import-payloads:cleanup', ['--apply' => true]));
        $this->assertStringContainsString('"payload_files":0', Artisan::output());
    }
}
