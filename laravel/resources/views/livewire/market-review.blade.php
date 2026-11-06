{{--
    Monthly market review page shell (Paper page "Markkinakatsaus · sivu", desktop board).
    The review body is its own frozen view: resources/views/market-reviews/<month>.blade.php.
    It receives $review, $latest, $isLatest and $archive from this view.
--}}
<div>
    <x-schema-markup :schemas="$jsonLdSchemas" />

    {{-- Editorial header on the page ground (as the article board 6HX-0): no heroTone, so the
         top bar sits on its night band above it. --}}
    <x-site.longread-hero variant="article" tone="light" figure="article-fox-fireside" figure-width="560" figure-height="420" :drift="false" class="mr-hero">
        {{-- SEO (user decision 2026-11-06): the H1 is the keyword heading "Sähkömarkkinakatsaus
             lokakuu 2026", drawn as the kicker line; the display title is a <p>. The page looks the
             same. The separators are CSS content, so the H1 text has no "·". --}}
        <div class="vk-lr-kicker mr-kicker">
            <h1 class="mr-h1">Sähkömarkkinakatsaus <span class="mr-sep" aria-hidden="true"></span>{{ mb_strtolower($review['label']) }}</h1>
            <span class="mr-sep" aria-hidden="true"></span>@if ($review['draft'])Luonnos, julkaistaan {{ $review['published_label'] }}@else Julkaistu <time datetime="{{ $review['published_on'] }}">{{ $review['published_label'] }}</time>@endif
        </div>
        <div>
            <p class="vk-lr-title mr-title">{{ $review['title'] }}</p>
            <x-ink.wave width="560" stroke="5" class="vk-lr-title-wave" />
        </div>
        <p class="vk-lr-hero-lead">{{ $review['lead'] }}</p>
    </x-site.longread-hero>

    <x-page-action-strip />

    <div class="vk-wrap vk-lr-page mr-page">
        <x-market-review.video :video="$review['video'] ?? null" :draft="$review['draft']" />

        <nav class="vk-crumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/" class="vk-focus">Etusivu</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="/markkinakatsaus" class="vk-focus">Markkinakatsaukset</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">{{ $review['label'] }}</li>
            </ol>
        </nav>

        @unless ($isLatest)
            <p class="mr-older-note">
                Tämä on aiempi katsaus.
                <a href="/markkinakatsaus" class="vk-focus">Uusin katsaus: {{ $latest['label'] }} →</a>
            </p>
        @endunless

        @include('market-reviews.'.$review['month'])
    </div>
</div>
