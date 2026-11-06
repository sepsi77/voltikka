<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The monthly market reviews in config/market_reviews.php.
 *
 * URL rules (user decision 2026-11-06): /markkinakatsaus always shows the latest visible review
 * and /markkinakatsaus/YYYY-MM shows one review. Both URLs are self-canonical. A draft is
 * visible only when market_reviews.show_drafts is on; published() never contains a draft, so
 * the sitemap cannot list one.
 */
final class MarketReviews
{
    /**
     * Visible reviews, newest first.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function visible(): array
    {
        $showDrafts = (bool) config('market_reviews.show_drafts');

        return array_filter(self::all(), fn (array $review) => $showDrafts || ! $review['draft']);
    }

    /**
     * Published (non-draft) reviews, newest first.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function published(): array
    {
        return array_filter(self::all(), fn (array $review) => ! $review['draft']);
    }

    /** @return array<string, mixed>|null */
    public static function find(string $month): ?array
    {
        return self::visible()[$month] ?? null;
    }

    /** @return array<string, mixed>|null */
    public static function latest(): ?array
    {
        $visible = self::visible();

        return $visible === [] ? null : reset($visible);
    }

    public static function csvPath(string $month): string
    {
        return resource_path("market-reviews/{$month}/luvut.csv");
    }

    /**
     * Frozen data of one chart (resources/market-reviews/<month>/<name>.json). The page draws it
     * with the site's interactive chart scripts and also renders it as a data table.
     *
     * @return array<string, mixed>
     */
    public static function chartData(string $month, string $name): array
    {
        return json_decode((string) file_get_contents(resource_path("market-reviews/{$month}/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array<string, mixed>> */
    private static function all(): array
    {
        $reviews = [];
        foreach ((array) config('market_reviews.editions', []) as $month => $review) {
            $reviews[$month] = [
                ...$review,
                'month' => $month,
                'draft' => (bool) ($review['draft'] ?? true),
                'published_label' => Carbon::parse($review['published_on'])->format('j.n.Y'),
                'next_label' => isset($review['next_on']) ? Carbon::parse($review['next_on'])->format('j.n.Y') : null,
            ];
        }
        krsort($reviews);

        return $reviews;
    }
}
