<?php

namespace App\Livewire;

use App\Support\MarketReviews;
use Livewire\Component;

/**
 * Monthly market review (/markkinakatsaus and /markkinakatsaus/YYYY-MM).
 *
 * The page shell (hero, archive, notice) is shared; the body of each review is its own frozen
 * Blade view, resources/views/market-reviews/<month>.blade.php. See config/market_reviews.php.
 */
class MarketReview extends Component
{
    public ?string $month = null;

    public function mount(?string $month = null): void
    {
        $review = $month === null ? MarketReviews::latest() : MarketReviews::find($month);
        abort_if($review === null, 404);

        $this->month = $review['month'];
    }

    public function render()
    {
        $review = MarketReviews::find($this->month);
        $label = mb_strtolower($review['label']);
        $latest = MarketReviews::latest();
        $isLatestUrl = request()->is('markkinakatsaus');
        $url = config('app.url').($isLatestUrl ? '/markkinakatsaus' : '/markkinakatsaus/'.$review['month']);

        return view('livewire.market-review', [
            'review' => $review,
            'latest' => $latest,
            'isLatest' => $latest['month'] === $review['month'],
            'archive' => array_values(array_filter(
                MarketReviews::visible(),
                fn (array $other) => $other['month'] !== $review['month'],
            )),
            'jsonLdSchemas' => array_values(array_filter([
                $this->jsonLdSchema($review, $label, $url),
                $this->videoSchema($review, $label),
            ])),
        ])->layout('layouts.app', [
            'title' => "Sähkömarkkinakatsaus {$label}: {$review['title']} | Voltikka",
            'metaDescription' => $review['description'],
            // Both URLs are self-canonical (user decision 2026-11-06).
            'canonical' => $url,
            'ogArt' => 'article-fox-fireside',
        ]);
    }

    /**
     * VideoObject for the review video, only when the video is on YouTube (id and upload date).
     *
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>|null
     */
    private function videoSchema(array $review, string $label): ?array
    {
        $video = $review['video'] ?? null;
        if (empty($video['youtube_id']) || empty($video['uploaded_on'])) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $video['title'] ?? "Sähkömarkkinakatsaus {$label}: {$review['title']}",
            'description' => $review['description'],
            'thumbnailUrl' => config('app.url').$video['poster'],
            'uploadDate' => $video['uploaded_on'],
            'duration' => $video['duration'],
            'embedUrl' => 'https://www.youtube-nocookie.com/embed/'.$video['youtube_id'],
            'publisher' => ['@type' => 'Organization', 'name' => 'Voltikka', 'url' => config('app.url')],
        ];
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    private function jsonLdSchema(array $review, string $label, string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => "Sähkömarkkinakatsaus {$label}: {$review['title']}",
            'description' => $review['description'],
            'author' => AboutPage::maintainerSchema(),
            'publisher' => [
                '@type' => 'Organization',
                'name' => 'Voltikka',
                'url' => config('app.url'),
            ],
            'datePublished' => $review['published_on'],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        ];
    }
}
