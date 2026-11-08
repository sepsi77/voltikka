<?php

namespace Tests\Feature;

use App\Services\Caching\ExpiredFileCacheCleanup;
use Illuminate\Cache\FileStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExpiredFileCacheCleanupTest extends TestCase
{
    private string $directory;

    private FileStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/voltikka-cache-cleanup-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->store = new FileStore(new Filesystem, $this->directory);
        config(['cache.default' => 'cleanup-test', 'cache.stores.cleanup-test' => ['driver' => 'file', 'path' => $this->directory]]);
        Cache::purge('cleanup-test');
        $this->travelTo(now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        $this->travelBack();
        parent::tearDown();
    }

    public function test_only_expired_arrays_and_objects_are_truncated_and_reruns_are_safe(): void
    {
        $expired = $this->entry('expired', now()->timestamp - 1, ['contracts' => str_repeat('x', 10000)]);
        $object = $this->entry('object', now()->timestamp - 1, (object) ['canonical_pricing' => []]);
        $preserved = [
            $this->entry('fresh', now()->timestamp + 100, []),
            $this->entry('forever', 9999999999, []),
            $this->entry('zero', 0, []),
            $this->entry('owner', now()->timestamp - 1, 'lock-owner'),
            $this->entry('integer', now()->timestamp - 1, 42),
            $this->entry('boolean', now()->timestamp - 1, false),
            $this->entry('null', now()->timestamp - 1, null),
        ];
        foreach (['bad-date' => 'not-a-datea:0:{}', 'bad-type' => now()->timestamp.'a:bad:{}', 'bad-object' => now()->timestamp.'O:2:"stdClass":0:{}', 'short' => '', 'unknown' => now()->timestamp.'garbage'] as $key => $raw) {
            $path = $this->entry($key, 1, []);
            file_put_contents($path, $raw);
            $preserved[] = $path;
        }
        $contents = array_map('file_get_contents', $preserved);
        $inode = fileinode($expired);
        $bytes = filesize($expired) + filesize($object);
        $dry = $this->scan(false);
        $this->assertSame(2, $dry['eligible']);
        $this->assertSame(0, $dry['reclaimed']);
        $this->assertSame($bytes, $dry['bytes']);
        $this->assertNotSame('', file_get_contents($expired));
        $result = $this->scan();
        $this->assertSame(2, $result['reclaimed']);
        $this->assertSame($bytes, $result['reclaimed_bytes']);
        $this->assertSame('', file_get_contents($expired));
        $this->assertSame('', file_get_contents($object));
        $this->assertSame($inode, fileinode($expired));
        $this->assertSame($contents, array_map('file_get_contents', $preserved));
        $this->assertSame(0, $this->scan()['reclaimed']);
    }

    public function test_locked_files_and_symlinks_and_nonstandard_paths_are_preserved(): void
    {
        $locked = $this->entry('locked', 1, ['locked']);
        $handle = fopen($locked, 'r+b');
        flock($handle, LOCK_EX);
        $target = $this->directory.'/outside';
        file_put_contents($target, '0000000001a:0:{}');
        $link = $this->store->path('link');
        mkdir(dirname($link), 0700, true);
        symlink($target, $link);
        $shardTarget = $this->directory.'/outside-directory';
        mkdir($shardTarget);
        file_put_contents($shardTarget.'/'.str_repeat('a', 40), '0000000001a:0:{}');
        symlink($shardTarget, $this->directory.'/aa');
        $badPath = dirname($locked).'/not-a-hash';
        file_put_contents($badPath, '0000000001a:0:{}');
        try {
            $this->assertSame(0, $this->scan()['reclaimed']);
            $this->assertNotSame('', file_get_contents($locked));
            $this->assertSame('0000000001a:0:{}', file_get_contents($target));
            $this->assertSame('0000000001a:0:{}', file_get_contents($badPath));
            $this->assertTrue(is_link($link));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->assertSame(1, $this->scan()['reclaimed']);
    }

    public function test_an_already_open_writer_keeps_the_same_inode_and_can_write_after_cleanup(): void
    {
        $path = $this->entry('writer', 1, ['old']);
        // A put/add writer can open the inode before it acquires LOCK_EX.
        $writer = fopen($path, 'c+');
        $inode = fstat($writer)['ino'];
        try {
            $this->assertSame(1, $this->scan()['reclaimed']);
            flock($writer, LOCK_EX);
            $value = (now()->timestamp + 60).serialize(['new']);
            fwrite($writer, $value);
            fflush($writer);
            $this->assertSame($inode, fileinode($path));
            $this->assertSame($value, file_get_contents($path));
        } finally {
            flock($writer, LOCK_UN);
            fclose($writer);
        }
        $this->assertSame(0, $this->scan()['reclaimed']);
        $this->assertTrue($this->store->put('writer', ['put'], 60));
        $this->assertSame((now()->timestamp + 60).serialize(['put']), file_get_contents($path));
        $add = $this->entry('add', 1, ['old']);
        $this->scan();
        $this->assertTrue($this->store->add('add', ['added'], 60));
        $this->assertSame((now()->timestamp + 60).serialize(['added']), file_get_contents($add));
    }

    public function test_command_defaults_to_dry_run_and_other_stores_are_noops(): void
    {
        $path = $this->entry('command', 1, []);
        $this->artisan('cache:reclaim-expired-files')->expectsOutputToContain('Dry run:')->assertSuccessful();
        $this->assertNotSame('', file_get_contents($path));
        config(['cache.default' => 'array']);
        $this->artisan('cache:reclaim-expired-files --apply')->expectsOutputToContain('not FileStore')->assertSuccessful();
        $this->assertNotSame('', file_get_contents($path));
        config(['cache.default' => 'cleanup-test']);
        $this->artisan('cache:reclaim-expired-files --apply')->expectsOutputToContain('reclaimed=1')->assertSuccessful();
        $this->assertSame('', file_get_contents($path));
        $this->artisan('cache:reclaim-expired-files --max-seconds=0')->assertExitCode(2);
    }

    public function test_schedule_and_rotation_cover_local_hash_shards(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'cache:reclaim-expired-files'));
        $this->assertNotNull($event);
        $this->assertStringContainsString('--apply --scheduled', $event->command);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertFalse($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(2, $event->expiresAt);

        $path = $this->entry('rotated', 1, []);
        $shard = hexdec(substr(sha1('rotated'), 0, 2));
        $minute = intdiv(now()->timestamp, 60);
        $start = $minute - ($minute % 256);
        $this->travelTo(now()->setTimestamp(($start + (($shard + 1) % 256)) * 60));
        $this->assertSame(0, $this->scan(true, true)['reclaimed']);
        $this->travelTo(now()->setTimestamp(($start + $shard) * 60));
        $this->assertSame(1, $this->scan(true, true)['reclaimed']);
        $this->assertSame('', file_get_contents($path));
    }

    public function test_command_reports_safe_errors_and_incomplete_scans_as_failures(): void
    {
        $this->mock(ExpiredFileCacheCleanup::class, function ($mock) {
            $mock->shouldReceive('scan')->once()->andThrow(new \RuntimeException('private cache payload'));
        });
        $this->artisan('cache:reclaim-expired-files --apply')
            ->expectsOutput('File-cache reclamation failed. No cache paths or payloads are reported.')
            ->assertFailed();

        $this->mock(ExpiredFileCacheCleanup::class, function ($mock) {
            $mock->shouldReceive('scan')->once()->andReturn([
                'scanned' => 0, 'eligible' => 0, 'reclaimed' => 0, 'skipped' => 0,
                'bytes' => 0, 'reclaimed_bytes' => 0, 'errors' => 0, 'incomplete' => true,
            ]);
        });
        $this->artisan('cache:reclaim-expired-files --apply')
            ->expectsOutputToContain('Runtime limit reached; scan incomplete.')
            ->assertFailed();
    }

    public function test_runtime_limit_is_reported_without_mutation(): void
    {
        $path = $this->entry('limited', 1, []);
        $result = app(ExpiredFileCacheCleanup::class)->scan($this->store, true, false, 0);
        $this->assertTrue($result['incomplete']);
        $this->assertSame(0, $result['scanned']);
        $this->assertNotSame('', file_get_contents($path));
    }

    private function entry(string $key, int $expiration, mixed $value): string
    {
        $path = $this->store->path($key);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, sprintf('%010d', $expiration).serialize($value));

        return $path;
    }

    private function scan(bool $apply = true, bool $scheduled = false): array
    {
        return app(ExpiredFileCacheCleanup::class)->scan($this->store, $apply, $scheduled, 30);
    }
}
