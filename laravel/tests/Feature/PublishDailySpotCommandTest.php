<?php

namespace Tests\Feature;

use App\Models\SpotPriceHour;
use App\Models\SpotSocialPublication;
use App\Services\PostFastService;
use App\Services\SocialMediaPromptFormatter;
use App\Services\SpotPriceVideoService;
use App\Services\SpotSocial\SpotSocialPublicationService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PublishDailySpotCommandTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $contentDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contentDate = Carbon::create(2026, 1, 19, 13, 15, 0, SpotSocialPublicationService::TIMEZONE);
        Carbon::setTestNow($this->contentDate);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_is_independent_and_old_name_is_not_registered(): void
    {
        $commands = app(Kernel::class)->all();

        $this->assertArrayHasKey('social:publish-daily-spot', $commands);
        $this->assertArrayNotHasKey('social:daily-video', $commands);
    }

    public function test_disabled_default_skips_without_a_ledger_row(): void
    {
        config()->set('services.postfast.spot_social_publishing_enabled', false);

        $this->artisan('social:publish-daily-spot --skip-render')
            ->expectsOutputToContain('Spot social publishing is disabled')
            ->assertExitCode(0);
        $this->artisan('social:publish-daily-spot --skip-render --draft')
            ->expectsOutputToContain('Spot social publishing is disabled')
            ->assertExitCode(0);

        $this->assertSame(0, SpotSocialPublication::count());
    }

    public function test_incomplete_data_defers_without_a_ledger_row(): void
    {
        config()->set('services.postfast.spot_social_publishing_enabled', true);

        $this->artisan('social:publish-daily-spot --skip-render')
            ->expectsOutputToContain('Daily spot publication deferred')
            ->assertExitCode(0);

        $this->assertSame(0, SpotSocialPublication::count());
    }

    public function test_first_success_is_published_and_repeated_call_skips(): void
    {
        $this->insertRequiredHours();
        $postFast = $this->mockPipeline();
        $postFast->shouldReceive('isConfigured')->once()->andReturnTrue();
        $postFast->shouldReceive('uploadVideo')->once()->andReturn('video-key');
        $postFast->shouldReceive('schedulePosts')->once()->andReturn([
            'posted_count' => 3,
            'skipped_platforms' => ['X'],
        ]);

        $this->artisan('social:publish-daily-spot --skip-render')->assertExitCode(0);
        $this->artisan('social:publish-daily-spot --skip-render')
            ->expectsOutputToContain('already published')
            ->assertExitCode(0);

        $publication = SpotSocialPublication::firstOrFail();
        $this->assertSame(SpotSocialPublication::STATUS_PUBLISHED, $publication->status);
        $this->assertSame(1, $publication->attempt_count);
        $this->assertSame(3, $publication->posted_count);
        $this->assertSame(['X'], $publication->skipped_platforms);
    }

    public function test_cleanup_failure_after_postfast_success_keeps_publication_published(): void
    {
        $this->insertRequiredHours();
        $outputDir = storage_path('framework/testing/spot-social-cleanup-'.uniqid());
        $videoPath = $outputDir.'/daily-2026-01-19.mp4';
        mkdir($videoPath, 0755, true);
        config()->set('services.remotion.output_dir', $outputDir);

        $postFast = $this->mockPipeline();
        $postFast->shouldReceive('isConfigured')->once()->andReturnTrue();
        $postFast->shouldReceive('uploadVideo')->once()->with($videoPath)->andReturn('video-key');
        $postFast->shouldReceive('schedulePosts')->once()->andReturn([
            'posted_count' => 3,
            'skipped_platforms' => [],
        ]);

        try {
            $this->artisan('social:publish-daily-spot --skip-render')
                ->expectsOutputToContain('Could not delete local video file after successful PostFast publication')
                ->assertExitCode(0);

            $publication = SpotSocialPublication::firstOrFail();
            $this->assertSame(SpotSocialPublication::STATUS_PUBLISHED, $publication->status);
            $this->assertSame('video-key', $publication->postfast_video_key);
            $this->assertSame(3, $publication->posted_count);
        } finally {
            rmdir($videoPath);
            rmdir($outputDir);
        }
    }

    public function test_lost_publish_update_does_not_overwrite_a_newer_attempt(): void
    {
        $this->insertRequiredHours();
        $postFast = $this->mockPipeline();
        $postFast->shouldReceive('isConfigured')->once()->andReturnTrue();
        $postFast->shouldReceive('uploadVideo')->once()->andReturn('attempt-one-video');
        $postFast->shouldReceive('schedulePosts')->once()->andReturnUsing(function () {
            $publication = SpotSocialPublication::firstOrFail();
            $attemptTwo = app(SpotSocialPublicationService::class)->claim(
                $this->contentDate,
                $this->contentDate,
                retry: true,
                now: $publication->started_at->copy()->addMinutes(30),
            );

            $this->assertTrue($attemptTwo->claimed);
            $this->assertTrue(app(SpotSocialPublicationService::class)->markPublished(
                $attemptTwo->publication,
                'attempt-two-video',
                2,
                ['X'],
            ));

            return [
                'posted_count' => 1,
                'skipped_platforms' => [],
            ];
        });

        $this->artisan('social:publish-daily-spot --skip-render')
            ->expectsOutputToContain('a newer attempt owns the ledger row')
            ->assertExitCode(0);

        $publication = SpotSocialPublication::firstOrFail();
        $this->assertSame(SpotSocialPublication::STATUS_PUBLISHED, $publication->status);
        $this->assertSame(2, $publication->attempt_count);
        $this->assertSame('attempt-two-video', $publication->postfast_video_key);
        $this->assertSame(2, $publication->posted_count);
        $this->assertSame(['X'], $publication->skipped_platforms);
    }

    public function test_postfast_failure_is_persisted_and_is_not_retried_automatically(): void
    {
        $this->insertRequiredHours();
        $postFast = $this->mockPipeline();
        $postFast->shouldReceive('isConfigured')->once()->andReturnTrue();
        $postFast->shouldReceive('uploadVideo')->once()->andThrow(new \RuntimeException('network timeout'));
        $postFast->shouldNotReceive('schedulePosts');

        $this->artisan('social:publish-daily-spot --skip-render')
            ->expectsOutputToContain('Inspect PostFast before explicit retry')
            ->assertExitCode(1);
        $this->artisan('social:publish-daily-spot --skip-render')
            ->expectsOutputToContain('prior attempt failed')
            ->assertExitCode(0);

        $publication = SpotSocialPublication::firstOrFail();
        $this->assertSame(SpotSocialPublication::STATUS_FAILED, $publication->status);
        $this->assertSame(1, $publication->attempt_count);
        $this->assertStringContainsString('some external posts can already exist', $publication->error);
    }

    public function test_zero_postfast_posts_fail_the_claim(): void
    {
        $this->insertRequiredHours();
        $postFast = $this->mockPipeline();
        $postFast->shouldReceive('isConfigured')->once()->andReturnTrue();
        $postFast->shouldReceive('uploadVideo')->once()->andReturn('video-key');
        $postFast->shouldReceive('schedulePosts')->once()->andReturn([
            'posted_count' => 0,
            'skipped_platforms' => ['X', 'TIKTOK', 'INSTAGRAM', 'YOUTUBE'],
        ]);

        $this->artisan('social:publish-daily-spot --skip-render')->assertExitCode(1);

        $this->assertSame(SpotSocialPublication::STATUS_FAILED, SpotSocialPublication::firstOrFail()->status);
    }

    public function test_explicit_failed_retry_reuses_original_data_as_of_and_publishes(): void
    {
        $this->insertRequiredHours();
        $service = app(SpotSocialPublicationService::class);
        $originalAsOf = $this->contentDate->copy()->setTime(12, 45);
        $claim = $service->claim($this->contentDate, $originalAsOf);
        $this->assertTrue($service->markFailed($claim->publication, 'failed'));

        $postFast = $this->mockPipeline(expectedAsOf: $originalAsOf);
        $postFast->shouldReceive('isConfigured')->once()->andReturnTrue();
        $postFast->shouldReceive('uploadVideo')->once()->andReturn('retry-key');
        $postFast->shouldReceive('schedulePosts')->once()->andReturn([
            'posted_count' => 1,
            'skipped_platforms' => [],
        ]);

        $this->artisan('social:publish-daily-spot --skip-render --retry --date=2026-01-19')
            ->assertExitCode(0);

        $publication = SpotSocialPublication::firstOrFail();
        $this->assertSame(SpotSocialPublication::STATUS_PUBLISHED, $publication->status);
        $this->assertSame(2, $publication->attempt_count);
        $this->assertTrue($publication->data_as_of->eq($originalAsOf));
    }

    public function test_dry_run_skip_post_and_draft_do_not_change_the_ledger(): void
    {
        $this->insertRequiredHours();
        $postFast = $this->mockPipeline(videoCalls: 3);
        $postFast->shouldReceive('isConfigured')->once()->andReturnTrue();
        $postFast->shouldReceive('uploadVideo')->once()->andReturn('draft-key');
        $postFast->shouldReceive('schedulePosts')->once()->with(
            'draft-key',
            Mockery::type('array'),
            Mockery::type(Carbon::class),
            true,
        )->andReturn([
            'posted_count' => 2,
            'skipped_platforms' => [],
        ]);

        config()->set('services.postfast.spot_social_publishing_enabled', false);
        $this->artisan('social:publish-daily-spot --dry-run')->assertExitCode(0);
        $this->artisan('social:publish-daily-spot --skip-render --skip-post')->assertExitCode(0);

        config()->set('services.postfast.spot_social_publishing_enabled', true);
        $this->artisan('social:publish-daily-spot --skip-render --draft')->assertExitCode(0);

        $this->assertSame(0, SpotSocialPublication::count());
    }

    public function test_retry_validation_rejects_non_posting_modes_and_requires_date(): void
    {
        $this->artisan('social:publish-daily-spot --retry --dry-run --date=2026-01-19')->assertExitCode(1);
        $this->artisan('social:publish-daily-spot --retry')->assertExitCode(1);

        $this->assertSame(0, SpotSocialPublication::count());
    }

    public function test_schedule_runs_independently_each_hour_at_minute_15(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'social:publish-daily-spot'));

        $this->assertNotNull($event);
        $this->assertSame('15 * * * *', $event->expression);
        $this->assertSame('Europe/Helsinki', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertStringEndsWith('storage/logs/daily-spot-social.log', $event->output);
    }

    private function mockPipeline(int $videoCalls = 1, ?Carbon $expectedAsOf = null): PostFastService
    {
        config()->set('services.postfast.spot_social_publishing_enabled', true);
        config()->set('services.openrouter.api_key', null);

        $videoService = Mockery::mock(SpotPriceVideoService::class);
        $expectation = $videoService->shouldReceive('getDailyVideoData')->times($videoCalls);
        if ($expectedAsOf !== null) {
            $expectation->withArgs(fn (Carbon $asOf) => $asOf->eq($expectedAsOf));
        }
        $expectation->andReturnUsing(fn (Carbon $asOf) => $this->videoData($asOf));
        $this->app->instance(SpotPriceVideoService::class, $videoService);

        $formatter = Mockery::mock(SocialMediaPromptFormatter::class);
        $formatter->shouldReceive('formatPrompt')->times($videoCalls)->andReturn('prompt');
        $this->app->instance(SocialMediaPromptFormatter::class, $formatter);

        $postFast = Mockery::mock(PostFastService::class);
        $this->app->instance(PostFastService::class, $postFast);

        return $postFast;
    }

    private function videoData(Carbon $asOf): array
    {
        return [
            'as_of' => $asOf->toIso8601String(),
            'date' => ['iso' => $asOf->format('Y-m-d')],
            'statistics' => [
                'cheapest_hour' => ['label' => '02:00', 'price' => 3.5],
            ],
            'comparison' => [
                'day_rating' => ['code' => 'unknown'],
            ],
            'prices' => ['today' => [], 'tomorrow' => []],
        ];
    }

    private function insertRequiredHours(): void
    {
        $day = $this->contentDate->copy()->startOfDay();
        $this->insertDay($day);
        $this->insertDay($day->copy()->addDay());
    }

    private function insertDay(Carbon $helsinkiDay): void
    {
        $start = $helsinkiDay->copy()->startOfDay()->setTimezone('UTC');
        $end = $helsinkiDay->copy()->addDay()->startOfDay()->setTimezone('UTC');

        for ($hour = $start->copy(); $hour->lt($end); $hour->addHour()) {
            SpotPriceHour::create([
                'region' => 'FI',
                'timestamp' => $hour->timestamp,
                'utc_datetime' => $hour->copy(),
                'price_without_tax' => 5.0,
                'vat_rate' => 0.255,
            ]);
        }
    }
}
