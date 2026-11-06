@props(['review' => null])
{{--
    Latest published market review on the home page (user decision 2026-11-06, Paper board
    "Etusivu + uusin markkinakatsaus" on page "Markkinakatsaus · sivu"): directly under the
    Preferred Sources strip, in the pattern of the "Maksatko sähköstäsi liikaa?" row. It follows
    MarketReviews::latest(), so each new edition replaces it without a home page change; no
    review, no row. Poster, title and "Lue katsaus" open /markkinakatsaus; "Katso video" opens
    the video block on the review page.
--}}
@if ($review)
    @php
        $video = $review['video'] ?? null;
        $hasVideo = ! empty($video['youtube_id']);
    @endphp
    <section class="mr-feature" aria-labelledby="home-review-title">
        <div class="home-bill-row">
            <a href="/markkinakatsaus" class="mr-feature-poster vk-focus" tabindex="-1" aria-hidden="true">
                @if (! empty($video['poster']))
                    <img src="{{ $video['poster_960'] ?? $video['poster'] }}" alt="" width="1280" height="720" loading="lazy" decoding="async">
                    @if ($hasVideo)
                        <span class="mr-video-time">{{ $video['duration_label'] }}</span>
                    @endif
                @else
                    <img src="{{ \App\Support\Season::art('article-fox-fireside') }}" alt="" width="560" height="420" loading="lazy" decoding="async" class="mr-feature-art">
                @endif
            </a>
            <div class="home-bill-text mr-feature-text">
                <p class="home-kicker">Uusin sähkömarkkinakatsaus · {{ $review['label'] }}</p>
                <h2 id="home-review-title" class="home-heading"><a href="/markkinakatsaus" class="vk-focus">{{ $review['title'] }}</a></h2>
                @if (! empty($review['teaser']))
                    <p class="home-lead">{{ $review['teaser'] }}</p>
                @endif
            </div>
            <div class="mr-feature-actions">
                <a href="/markkinakatsaus" class="home-action home-action--lg vk-focus">
                    <span>Lue katsaus <span aria-hidden="true">→</span></span>
                    <x-ink.wave width="170" stroke="3" />
                </a>
                @if ($hasVideo)
                    <a href="/markkinakatsaus#video" class="mr-feature-video vk-focus">Katso video, {{ $video['duration_label'] }}</a>
                @endif
            </div>
        </div>
        <x-ink.hairline />
    </section>
@endif
