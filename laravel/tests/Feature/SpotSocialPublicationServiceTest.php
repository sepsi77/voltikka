<?php

namespace Tests\Feature;

use App\Models\SpotPriceHour;
use App\Models\SpotSocialPublication;
use App\Services\SpotSocial\SpotSocialPublicationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpotSocialPublicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SpotSocialPublicationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SpotSocialPublicationService::class);
    }

    public function test_readiness_accepts_exact_23_hour_dst_day_and_next_day(): void
    {
        $date = Carbon::create(2026, 3, 29, 0, 0, 0, SpotSocialPublicationService::TIMEZONE);
        $this->insertDay($date);
        $this->insertDay($date->copy()->addDay());

        $result = $this->service->readiness($date);

        $this->assertTrue($result->ready);
        $this->assertCount(47, SpotPriceHour::all());
    }

    public function test_readiness_accepts_exact_25_hour_dst_day_and_next_day(): void
    {
        $date = Carbon::create(2026, 10, 25, 0, 0, 0, SpotSocialPublicationService::TIMEZONE);
        $this->insertDay($date);
        $this->insertDay($date->copy()->addDay());

        $result = $this->service->readiness($date);

        $this->assertTrue($result->ready);
        $this->assertCount(49, SpotPriceHour::all());
    }

    public function test_readiness_rejects_a_gap_in_either_day(): void
    {
        $date = Carbon::create(2026, 1, 19, 0, 0, 0, SpotSocialPublicationService::TIMEZONE);
        $this->insertDay($date);
        $this->insertDay($date->copy()->addDay(), skipUtcHour: 5);

        $result = $this->service->readiness($date);

        $this->assertFalse($result->ready);
        $this->assertSame(['2026-01-20'], $result->incompleteDates);
    }

    public function test_first_claim_is_atomic_and_normal_repetition_is_blocked(): void
    {
        $date = Carbon::create(2026, 1, 19, 0, 0, 0, SpotSocialPublicationService::TIMEZONE);
        $asOf = $date->copy()->setTime(14, 15);
        $now = Carbon::create(2026, 1, 19, 12, 15, 0, 'UTC');

        $first = $this->service->claim($date, $asOf, now: $now);
        $second = $this->service->claim($date, $asOf, now: $now->copy()->addMinute());

        $this->assertTrue($first->claimed);
        $this->assertFalse($second->claimed);
        $this->assertSame(SpotSocialPublication::STATUS_PROCESSING, $second->reason);
        $this->assertSame(1, SpotSocialPublication::count());
        $this->assertSame(1, $first->publication->attempt_count);
    }

    public function test_failed_row_needs_explicit_retry_and_reuses_data_as_of(): void
    {
        $date = Carbon::create(2026, 1, 19, 0, 0, 0, SpotSocialPublicationService::TIMEZONE);
        $asOf = $date->copy()->setTime(14, 15);
        $claim = $this->service->claim($date, $asOf);
        $this->assertTrue($this->service->markFailed($claim->publication, 'provider failed'));

        $normal = $this->service->claim($date, $date->copy()->setTime(18, 0));
        $retry = $this->service->claim($date, $date->copy()->setTime(18, 0), retry: true);

        $this->assertFalse($normal->claimed);
        $this->assertSame(SpotSocialPublication::STATUS_FAILED, $normal->reason);
        $this->assertTrue($retry->claimed);
        $this->assertSame(2, $retry->publication->attempt_count);
        $this->assertTrue($retry->publication->data_as_of->eq($asOf));
    }

    public function test_published_row_can_never_be_retried(): void
    {
        $date = Carbon::create(2026, 1, 19, 0, 0, 0, SpotSocialPublicationService::TIMEZONE);
        $claim = $this->service->claim($date, $date->copy()->setTime(14, 15));
        $this->assertTrue($this->service->markPublished($claim->publication, 'video-key', 3, ['X']));

        $retry = $this->service->claim($date, $date, retry: true);

        $this->assertFalse($retry->claimed);
        $this->assertSame(SpotSocialPublication::STATUS_PUBLISHED, $retry->reason);
        $this->assertSame(3, $claim->publication->fresh()->posted_count);
        $this->assertSame(['X'], $claim->publication->fresh()->skipped_platforms);
    }

    public function test_fresh_processing_row_blocks_retry_but_stale_row_can_retry(): void
    {
        $date = Carbon::create(2026, 1, 19, 0, 0, 0, SpotSocialPublicationService::TIMEZONE);
        $startedAt = Carbon::create(2026, 1, 19, 12, 0, 0, 'UTC');
        $this->service->claim($date, $date->copy()->setTime(14, 0), now: $startedAt);

        $fresh = $this->service->claim($date, $date, retry: true, now: $startedAt->copy()->addMinutes(29));
        $stale = $this->service->claim($date, $date, retry: true, now: $startedAt->copy()->addMinutes(30));

        $this->assertFalse($fresh->claimed);
        $this->assertSame(SpotSocialPublication::STATUS_PROCESSING, $fresh->reason);
        $this->assertTrue($stale->claimed);
        $this->assertSame(2, $stale->publication->attempt_count);
    }

    public function test_stale_attempt_failure_cannot_overwrite_a_newer_published_attempt(): void
    {
        $date = Carbon::create(2026, 1, 19, 0, 0, 0, SpotSocialPublicationService::TIMEZONE);
        $startedAt = Carbon::create(2026, 1, 19, 12, 0, 0, 'UTC');
        $attemptOne = $this->service->claim($date, $date->copy()->setTime(14, 0), now: $startedAt);
        $attemptTwo = $this->service->claim(
            $date,
            $date,
            retry: true,
            now: $startedAt->copy()->addMinutes(30),
        );

        $this->assertTrue($attemptTwo->claimed);
        $this->assertTrue($this->service->markPublished(
            $attemptTwo->publication,
            'attempt-two-video',
            3,
            ['X'],
        ));
        $this->assertFalse($this->service->markFailed($attemptOne->publication, 'late attempt-one failure'));

        $publication = SpotSocialPublication::firstOrFail();
        $this->assertSame(SpotSocialPublication::STATUS_PUBLISHED, $publication->status);
        $this->assertSame(2, $publication->attempt_count);
        $this->assertSame('attempt-two-video', $publication->postfast_video_key);
        $this->assertSame(3, $publication->posted_count);
        $this->assertNull($publication->error);
    }

    public function test_stale_attempt_publish_cannot_overwrite_a_newer_processing_attempt(): void
    {
        $date = Carbon::create(2026, 1, 19, 0, 0, 0, SpotSocialPublicationService::TIMEZONE);
        $startedAt = Carbon::create(2026, 1, 19, 12, 0, 0, 'UTC');
        $attemptOne = $this->service->claim($date, $date->copy()->setTime(14, 0), now: $startedAt);
        $attemptTwo = $this->service->claim(
            $date,
            $date,
            retry: true,
            now: $startedAt->copy()->addMinutes(30),
        );

        $this->assertFalse($this->service->markPublished(
            $attemptOne->publication,
            'attempt-one-video',
            1,
            [],
        ));

        $publication = SpotSocialPublication::firstOrFail();
        $this->assertSame(SpotSocialPublication::STATUS_PROCESSING, $publication->status);
        $this->assertSame(2, $publication->attempt_count);
        $this->assertNull($publication->postfast_video_key);

        $this->assertTrue($this->service->markPublished(
            $attemptTwo->publication,
            'attempt-two-video',
            2,
            [],
        ));
        $this->assertSame('attempt-two-video', $publication->fresh()->postfast_video_key);
    }

    public function test_stale_attempt_cannot_overwrite_a_newer_failed_attempt(): void
    {
        $date = Carbon::create(2026, 1, 19, 0, 0, 0, SpotSocialPublicationService::TIMEZONE);
        $startedAt = Carbon::create(2026, 1, 19, 12, 0, 0, 'UTC');
        $attemptOne = $this->service->claim($date, $date->copy()->setTime(14, 0), now: $startedAt);
        $attemptTwo = $this->service->claim(
            $date,
            $date,
            retry: true,
            now: $startedAt->copy()->addMinutes(30),
        );

        $this->assertTrue($this->service->markFailed($attemptTwo->publication, 'attempt-two failure'));
        $this->assertFalse($this->service->markFailed($attemptOne->publication, 'attempt-one failure'));
        $this->assertFalse($this->service->markPublished(
            $attemptOne->publication,
            'attempt-one-video',
            1,
            [],
        ));

        $publication = SpotSocialPublication::firstOrFail();
        $this->assertSame(SpotSocialPublication::STATUS_FAILED, $publication->status);
        $this->assertSame(2, $publication->attempt_count);
        $this->assertSame('attempt-two failure', $publication->error);
        $this->assertNull($publication->postfast_video_key);
    }

    private function insertDay(Carbon $helsinkiDay, ?int $skipUtcHour = null): void
    {
        $start = $helsinkiDay->copy()->startOfDay()->setTimezone('UTC');
        $end = $helsinkiDay->copy()->addDay()->startOfDay()->setTimezone('UTC');
        $index = 0;

        for ($hour = $start->copy(); $hour->lt($end); $hour->addHour(), $index++) {
            if ($index === $skipUtcHour) {
                continue;
            }

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
