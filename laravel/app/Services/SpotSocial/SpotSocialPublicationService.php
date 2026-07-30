<?php

namespace App\Services\SpotSocial;

use App\Models\SpotPriceHour;
use App\Models\SpotSocialPublication;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SpotSocialPublicationService
{
    public const TIMEZONE = 'Europe/Helsinki';

    public const STALE_PROCESSING_MINUTES = 30;

    public function readiness(Carbon $contentDate): SpotSocialReadinessResult
    {
        $date = $contentDate->copy()->setTimezone(self::TIMEZONE)->startOfDay();
        $incompleteDates = [];

        foreach ([$date, $date->copy()->addDay()] as $day) {
            if (! $this->hasExactHourlySequence($day)) {
                $incompleteDates[] = $day->format('Y-m-d');
            }
        }

        return new SpotSocialReadinessResult($incompleteDates === [], $incompleteDates);
    }

    public function claim(
        Carbon $contentDate,
        Carbon $dataAsOf,
        bool $retry = false,
        ?Carbon $now = null,
    ): SpotSocialClaimResult {
        $date = $contentDate->copy()->setTimezone(self::TIMEZONE)->format('Y-m-d');
        $claimedAt = ($now ?? Carbon::now())->copy()->setTimezone(config('app.timezone', 'UTC'));

        return DB::transaction(function () use ($date, $dataAsOf, $retry, $claimedAt) {
            if (! $retry) {
                $inserted = DB::table('spot_social_publications')->insertOrIgnore([
                    'content_date' => $date,
                    'status' => SpotSocialPublication::STATUS_PROCESSING,
                    'attempt_count' => 1,
                    'data_as_of' => $dataAsOf->copy()->setTimezone(config('app.timezone', 'UTC')),
                    'started_at' => $claimedAt,
                    'created_at' => $claimedAt,
                    'updated_at' => $claimedAt,
                ]);

                $publication = SpotSocialPublication::query()
                    ->where('content_date', $date)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($inserted === 1) {
                    return new SpotSocialClaimResult(true, 'claimed', $publication);
                }

                return new SpotSocialClaimResult(false, $publication->status, $publication);
            }

            $publication = SpotSocialPublication::query()
                ->where('content_date', $date)
                ->lockForUpdate()
                ->first();

            if ($publication === null) {
                return new SpotSocialClaimResult(false, 'not_found', null);
            }

            if ($publication->status === SpotSocialPublication::STATUS_PUBLISHED) {
                return new SpotSocialClaimResult(false, 'published', $publication);
            }

            $canRetry = $publication->status === SpotSocialPublication::STATUS_FAILED
                || ($publication->status === SpotSocialPublication::STATUS_PROCESSING
                    && $publication->started_at->lte($claimedAt->copy()->subMinutes(self::STALE_PROCESSING_MINUTES)));

            if (! $canRetry) {
                return new SpotSocialClaimResult(false, $publication->status, $publication);
            }

            $publication->forceFill([
                'status' => SpotSocialPublication::STATUS_PROCESSING,
                'attempt_count' => $publication->attempt_count + 1,
                'started_at' => $claimedAt,
                'completed_at' => null,
                'postfast_video_key' => null,
                'posted_count' => null,
                'skipped_platforms' => null,
                'error' => null,
            ])->save();

            return new SpotSocialClaimResult(true, 'retry_claimed', $publication->fresh());
        });
    }

    /**
     * @param  list<string>  $skippedPlatforms
     */
    public function markPublished(
        SpotSocialPublication $publication,
        string $videoKey,
        int $postedCount,
        array $skippedPlatforms,
        ?Carbon $now = null,
    ): bool {
        $completedAt = ($now ?? Carbon::now())->copy()->setTimezone(config('app.timezone', 'UTC'));
        $attributes = (new SpotSocialPublication)->forceFill([
            'status' => SpotSocialPublication::STATUS_PUBLISHED,
            'completed_at' => $completedAt,
            'published_at' => $completedAt,
            'postfast_video_key' => $videoKey,
            'posted_count' => $postedCount,
            'skipped_platforms' => $skippedPlatforms,
            'error' => null,
        ])->getAttributes();

        return SpotSocialPublication::query()
            ->whereKey($publication->getKey())
            ->where('status', SpotSocialPublication::STATUS_PROCESSING)
            ->where('attempt_count', $publication->attempt_count)
            ->update($attributes) === 1;
    }

    public function markFailed(
        SpotSocialPublication $publication,
        string $error,
        ?Carbon $now = null,
    ): bool {
        $attributes = (new SpotSocialPublication)->forceFill([
            'status' => SpotSocialPublication::STATUS_FAILED,
            'completed_at' => ($now ?? Carbon::now())->copy()->setTimezone(config('app.timezone', 'UTC')),
            'error' => mb_substr($error, 0, 1000),
        ])->getAttributes();

        return SpotSocialPublication::query()
            ->whereKey($publication->getKey())
            ->where('status', SpotSocialPublication::STATUS_PROCESSING)
            ->where('attempt_count', $publication->attempt_count)
            ->update($attributes) === 1;
    }

    private function hasExactHourlySequence(Carbon $helsinkiDay): bool
    {
        $start = $helsinkiDay->copy()->startOfDay()->setTimezone('UTC');
        $end = $helsinkiDay->copy()->addDay()->startOfDay()->setTimezone('UTC');
        $expected = [];

        for ($hour = $start->copy(); $hour->lt($end); $hour->addHour()) {
            $expected[] = $hour->format('Y-m-d H:i:s');
        }

        $actual = SpotPriceHour::query()
            ->forRegion('FI')
            ->where('utc_datetime', '>=', $start)
            ->where('utc_datetime', '<', $end)
            ->orderBy('utc_datetime')
            ->get(['utc_datetime'])
            ->map(fn (SpotPriceHour $price) => Carbon::parse(
                $price->getRawOriginal('utc_datetime'),
                'UTC',
            )->format('Y-m-d H:i:s'))
            ->all();

        return $actual === $expected;
    }
}
