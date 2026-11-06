<?php

/*
 * Monthly electricity market reviews (/markkinakatsaus). See app/Support/MarketReviews.php and
 * market-review/decisions.md ("URLs").
 *
 * Each key is the review month (YYYY-MM). Its files:
 *   resources/views/market-reviews/<month>.blade.php   page body (frozen copy and figures)
 *   resources/market-reviews/<month>/kuva-*.json       frozen chart data (interactive charts)
 *   resources/market-reviews/<month>/luvut.csv         key figures (/markkinakatsaus/<month>.csv)
 *   public/images/market-reviews/<month>/video*.webp   video poster (the YouTube thumbnail)
 *
 * A review with 'draft' => true returns 404 and is not in the sitemap or the navigation,
 * unless show_drafts is on (default: only APP_ENV=local). Publishing = set draft to false
 * and deploy.
 */
return [
    'show_drafts' => (bool) env('MARKET_REVIEW_SHOW_DRAFTS', env('APP_ENV') === 'local'),

    'editions' => [
        '2026-10' => [
            'label' => 'Lokakuu 2026',
            'title' => 'Halpa lokakuu, kallis talvi',
            'lead' => 'Lokakuu oli lämmin ja tuulinen, ja pörssisähkö maksoi keskimäärin vain 4,77 c/kWh. Halpa sähkö loppuu marraskuussa: tammikuun futuuri on 15,7 c/kWh. Katso, mitä se tarkoittaa sinun sähkölaskullesi.',
            'description' => 'Pörssisähkö maksoi lokakuussa 4,77 c/kWh, mutta talvesta tulee kallis. Katso, paljonko sähkö maksaa talvella ja kannattaako pörssisähkö vai kiinteä hinta.',
            'published_on' => '2026-11-06',
            'next_on' => '2026-12-05',
            'draft' => false,
            // Review video (YouTube). The block shows only when youtube_id is set; set
            // uploaded_on (YouTube publish date) with it, because VideoObject needs it.
            'video' => [
                'youtube_id' => 'i7ZopmWpqco',
                'uploaded_on' => '2026-11-06',
                'title' => 'Sähkön hinta talvella: pörssisähkö vai kiinteä? Sähkömarkkinakatsaus lokakuu 2026',
                'duration' => 'PT5M25S',
                'duration_label' => '5:25',
                'poster' => '/images/market-reviews/2026-10/video.webp',
                'poster_960' => '/images/market-reviews/2026-10/video-960.webp',
                'caption' => 'Lokakuu ja tuleva talvi viidessä minuutissa: miksi talvesta tulee kallis ja mikä sopimus nyt kannattaa. Video toistuu YouTubesta, kun painat sitä.',
            ],
        ],
    ],
];
