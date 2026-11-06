@props(['video' => null, 'draft' => false])
{{--
    Review video (user decision 2026-11-06): full content width directly under the Preferred
    Sources strip. The page shows only our own copy of the YouTube thumbnail; the player
    (youtube-nocookie.com) loads after a click, so no request goes to YouTube before that.
    Without JavaScript the poster is a plain link to the video on YouTube.
    Without a youtube_id the block is not shown; on a draft (only visible with show_drafts) the
    poster shows with a note, so the place can be reviewed before the video is uploaded.
--}}
@php
    $id = $video['youtube_id'] ?? null;
    $show = $video !== null && ($id !== null || $draft);
@endphp
@if ($show)
    <section id="video" class="mr-video" aria-labelledby="mr-video-title">
        <h2 id="mr-video-title" class="mr-caps mr-caps--fox">Katso videona</h2>
        <div class="mr-video-frame" @if ($id) x-data="{ playing: false }" @endif>
            @if ($id)
                <a
                    href="https://www.youtube.com/watch?v={{ $id }}"
                    class="mr-video-poster vk-focus"
                    x-show="! playing"
                    x-on:click.prevent="playing = true"
                    aria-label="Toista video: Sähkömarkkinakatsaus, {{ $video['duration_label'] }}"
                >
            @else
                <div class="mr-video-poster">
            @endif
                    <img
                        src="{{ $video['poster'] }}"
                        srcset="{{ $video['poster_960'] }} 960w, {{ $video['poster'] }} 1280w"
                        sizes="(min-width: 1280px) 1232px, calc(100vw - 32px)"
                        alt=""
                        width="1280"
                        height="720"
                        decoding="async"
                    >
                    <span class="mr-video-play" aria-hidden="true">
                        <svg viewBox="0 0 34 36" focusable="false"><path d="M8 4.5 C 7.6 3.2, 9 2.6, 10.1 3.3 L 30 16.2 C 31.2 17, 31.2 18.9, 30 19.7 L 10.1 32.7 C 9 33.4, 7.6 32.8, 8 31.5 Z" fill="var(--color-fox)" stroke="var(--color-ink)" stroke-width="2" stroke-linejoin="round" /></svg>
                    </span>
                    <span class="mr-video-time">{{ $video['duration_label'] }}</span>
                    @unless ($id)
                        <span class="mr-video-draft">Luonnos: YouTube-tunnus puuttuu</span>
                    @endunless
            @if ($id)
                </a>
                <template x-if="playing">
                    <iframe
                        class="mr-video-player"
                        src="https://www.youtube-nocookie.com/embed/{{ $id }}?autoplay=1&rel=0"
                        title="Sähkömarkkinakatsaus videona"
                        allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                        allowfullscreen
                        referrerpolicy="strict-origin-when-cross-origin"
                    ></iframe>
                </template>
            @else
                </div>
            @endif
        </div>
        <p class="mr-figcaption mr-video-caption">{{ $video['caption'] }}</p>
    </section>
@endif
