<?php

namespace Tests\Unit\ImportPayload;

use App\Services\ImportPayload\ImportPayloadInvalid;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadReference;
use App\Services\ImportPayload\ImportPayloadWriter;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportPayloadWriterReaderTest extends TestCase
{
    private const BINARY = "PK\x03\x04\x00\xff\xfe binary zip bytes";

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_round_trip_writes_header_requests_and_end_atomically_and_streams_them_back(): void
    {
        // 22:30 UTC on 18 November is 00:30 on 19 November in Helsinki: the directory uses Helsinki.
        Carbon::setTestNow(Carbon::parse('2026-11-18 22:30:15.123456', 'UTC'));
        Http::fake([
            'api.test/text*' => Http::response('<Doc>ä</Doc>', 200),
            'api.test/zip*' => Http::response(self::BINARY, 200),
            'api.test/forced*' => Http::response('plain text', 200),
            'api.test/down*' => Http::response('{"error":"maintenance"}', 503),
        ]);

        $writer = ImportPayloadWriter::open('test-source', 'test:fetch', ['zone' => 'FI', 'all' => false], ['effective_date' => '2026-11-19']);
        $writer->record(['document' => 'A44'], Http::get('https://api.test/text', ['securityToken' => 'SECRET-TOKEN', 'documentType' => 'A44']));
        $writer->record(['document' => 'zip'], Http::get('https://api.test/zip', ['api_key' => 'SECRET-KEY']));
        $writer->record(['document' => 'forced'], Http::get('https://api.test/forced'), 'base64');
        try {
            Http::get('https://api.test/down', ['apikey' => 'SECRET-APIKEY'])->throw();
            $this->fail('Expected a RequestException.');
        } catch (RequestException $e) {
            $writer->recordFailure(['document' => 'down'], 'GET', 'https://api.test/down', [], $e);
        }
        $writer->recordFailure(['document' => 'offline'], 'GET', 'https://user:SECRET-PASS@api.test/offline?token=SECRET-QUERY', ['Key' => 'SECRET-PARAM', 'day' => '2026-11-19'], new ConnectionException('SECRET-MESSAGE'));
        $writer->setFact('plan', ['A44', 'zip']); // facts set during the fetch still land in the header
        $reference = $writer->finish(['pages' => 5]);

        $directory = config('import_payloads.path').'/test-source/2026-11-19';
        $this->assertSame("{$directory}/{$reference->runId}.ndjson.gz", $reference->path);
        $files = array_values(array_diff(scandir($directory), ['.', '..']));
        sort($files);
        $this->assertSame([basename($reference->path), "{$reference->runId}.sha256"], $files, 'No temporary file stays behind.');
        $this->assertSame($reference->sha256."\n", file_get_contents("{$directory}/{$reference->runId}.sha256"));
        $this->assertSame(hash_file('sha256', $reference->path), $reference->sha256);
        $this->assertSame(filesize($reference->path), $reference->size);
        $this->assertSame([
            'path' => $reference->path,
            'sha256' => $reference->sha256,
            'size' => $reference->size,
            'fetched_at' => '2026-11-18T22:30:15Z',
            'source' => 'test-source',
            'run_id' => $reference->runId,
            'requests' => 5,
            'failed_units' => 2,
            'complete' => false,
        ], $reference->toArray());

        $raw = gzdecode(file_get_contents($reference->path));
        foreach (['SECRET-TOKEN', 'SECRET-KEY', 'SECRET-APIKEY', 'SECRET-PASS', 'SECRET-QUERY', 'SECRET-PARAM', 'SECRET-MESSAGE', 'user:'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $lines = array_map(fn ($line) => json_decode($line, true), explode("\n", rtrim($raw, "\n")));
        $this->assertCount(7, $lines);
        $this->assertSame([
            'type' => 'header', 'version' => 1, 'source' => 'test-source', 'command' => 'test:fetch',
            'options' => ['zone' => 'FI', 'all' => false], 'run_id' => $reference->runId,
            'fetched_at' => '2026-11-18T22:30:15Z', 'facts' => ['effective_date' => '2026-11-19', 'plan' => ['A44', 'zip']],
            'requests' => 5, 'failed_units' => 2,
        ], $lines[0]);
        $this->assertSame([
            'type' => 'request', 'unit' => ['document' => 'A44'], 'method' => 'GET', 'url' => 'https://api.test/text',
            'query' => ['documentType' => 'A44'], 'status' => 200, 'body' => '<Doc>ä</Doc>',
        ], $lines[1]);
        $this->assertSame(base64_encode(self::BINARY), $lines[2]['body_base64']);
        $this->assertArrayNotHasKey('body', $lines[2]);
        $this->assertSame(base64_encode('plain text'), $lines[3]['body_base64']);
        $this->assertSame(['status' => 503, 'body' => '{"error":"maintenance"}'], array_intersect_key($lines[4], ['status' => 0, 'body' => 0]));
        $this->assertSame([
            'type' => 'request', 'unit' => ['document' => 'offline'], 'method' => 'GET', 'url' => 'https://api.test/offline',
            'query' => ['day' => '2026-11-19'], 'connection_failed' => true, 'exception' => ConnectionException::class,
        ], $lines[5]);
        $this->assertSame([
            'type' => 'end', 'requests' => 5,
            'failed_units' => [['document' => 'down'], ['document' => 'offline']], 'facts' => ['pages' => 5],
        ], $lines[6]);

        $reader = ImportPayloadReader::open($reference->path);
        $this->assertSame('2026-11-18T22:30:15+00:00', $reader->fetchedAt()->toIso8601String());
        $this->assertSame(['A44', 'zip'], $reader->fact('plan'));
        $this->assertSame('2026-11-19', $reader->fact('effective_date'));
        $this->assertFalse($reader->complete());
        $this->assertSame($reference->sha256, $reader->sha256());
        $bodies = array_map(fn (array $request) => ImportPayloadReader::body($request), iterator_to_array($reader->requests(), false));
        $this->assertSame(['<Doc>ä</Doc>', self::BINARY, 'plain text', '{"error":"maintenance"}', null], $bodies);
    }

    public function test_reader_refuses_a_missing_changed_or_unfinished_file(): void
    {
        $this->assertRefused('missing', fn () => ImportPayloadReader::open(config('import_payloads.path').'/none/2026-11-19/x.ndjson.gz'));

        $reference = $this->finishedPayload();
        $this->assertRefused('sha256_mismatch', fn () => ImportPayloadReader::open($reference->path, str_repeat('0', 64)));
        ImportPayloadReader::open($reference->path, $reference->sha256);

        // Without the .sha256 file (renamed last) the run never finished, even with a supplied digest.
        unlink(substr($reference->path, 0, -strlen('.ndjson.gz')).'.sha256');
        $this->assertRefused('incomplete', fn () => ImportPayloadReader::open($reference->path));
        $this->assertRefused('incomplete', fn () => ImportPayloadReader::open($reference->path, $reference->sha256));

        $other = $this->finishedPayload();
        file_put_contents($other->path, gzencode('changed'), FILE_APPEND);
        $this->assertRefused('sha256_mismatch', fn () => ImportPayloadReader::open($other->path));
    }

    public function test_reader_refuses_a_file_without_end_line_or_with_lines_after_it(): void
    {
        $header = '{"type":"header","version":1,"source":"s","command":"c","options":{},"run_id":"r","fetched_at":"2026-11-19T06:00:00Z","facts":{}}';
        $request = '{"type":"request","unit":{},"method":"GET","url":"https://a.test/x","query":{},"status":200,"body":"[]"}';
        $end = '{"type":"end","requests":1,"failed_units":[],"facts":{}}';

        foreach ([
            'no end line' => [$header, $request],
            'request after end' => [$header, $request, $end, $request],
            'no header' => [$request, $end],
            'wrong request count' => [$header, $end],
            'not json' => [$header, '{broken', $end],
            'header without source' => [str_replace('"source":"s",', '', $header), $request, $end],
            'header without command' => [str_replace('"command":"c",', '', $header), $request, $end],
        ] as $case => $lines) {
            $path = $this->writeFinished('case-'.md5($case), implode("\n", $lines)."\n");
            $this->assertRefused('incomplete', fn () => ImportPayloadReader::open($path, hash_file('sha256', $path)), $case);
        }

        $path = $this->writeFinished('valid', "{$header}\n{$request}\n{$end}\n");
        $this->assertTrue(ImportPayloadReader::open($path, hash_file('sha256', $path))->complete());
    }

    public function test_abandon_removes_the_temporary_files_and_redaction_drops_user_info(): void
    {
        Http::fake(['api.test/*' => Http::response('[]')]);
        $writer = ImportPayloadWriter::open('test-source', 'test:fetch');
        $writer->record(['page' => 1], Http::get('https://api.test/a'));
        $writer->abandon();
        $writer->abandon();
        $directory = config('import_payloads.path').'/test-source/'.now('Europe/Helsinki')->toDateString();
        $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])));

        $this->assertSame(
            ['https://api.test:8443/v1/data', ['Day' => '1', 'zone' => ['FI', 'SE1']]],
            ImportPayloadWriter::redactUrl('https://me:pw@api.test:8443/v1/data?Day=1&SUBSCRIPTION-KEY=k&zone=FI&zone=SE1&X-Api-Key=k2&password=p&secret=s&access_token=t'),
        );
    }

    public function test_a_later_answered_line_recovers_a_failed_unit(): void
    {
        Http::fake(['api.test/*' => Http::sequence()->push('busy', 503)->push('[]')->push('[]', 500)]);
        $writer = ImportPayloadWriter::open('test-source', 'test:fetch');
        $writer->record(['maturity' => '202612'], Http::get('https://api.test/a'), failed: true);
        $writer->record(['maturity' => '202612'], Http::get('https://api.test/a'));
        $writer->record(['maturity' => '202701'], Http::get('https://api.test/b'), failed: true);
        $reference = $writer->finish();

        $reader = ImportPayloadReader::open($reference->path);
        $this->assertSame([['maturity' => '202701']], $reader->end()['failed_units']);
        $this->assertSame(3, $reference->requests);
        $this->assertSame(1, $reference->failedUnits);
    }

    /** A hand-made payload with its .sha256 file, so only its structure decides. */
    private function writeFinished(string $name, string $content): string
    {
        $path = config('import_payloads.path')."/{$name}.ndjson.gz";
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, gzencode($content));
        file_put_contents(config('import_payloads.path')."/{$name}.sha256", hash_file('sha256', $path)."\n");

        return $path;
    }

    private function finishedPayload(): ImportPayloadReference
    {
        Http::fake(['api.test/*' => Http::response('[]')]);
        $writer = ImportPayloadWriter::open('test-source', 'test:fetch');
        $writer->record(['page' => 1], Http::get('https://api.test/a'));

        return $writer->finish();
    }

    private function assertRefused(string $reason, \Closure $open, string $case = ''): void
    {
        try {
            $open();
            $this->fail("Expected {$reason} refusal {$case}");
        } catch (ImportPayloadInvalid $e) {
            $this->assertSame($reason, $e->reason, $case);
        }
    }
}
