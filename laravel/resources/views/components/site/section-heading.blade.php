@props([
    'level' => 2,
    'size' => 'section',
    'wave' => null,
])
{{--
    Display heading with the hand-inked wavy fox underline (design spec §4 "Line language",
    §5 "Section heading"). Reusable on every redesigned page.

    level  heading level (2..4); the visual size does not depend on it.
    size   'section' = Bricolage 34/38 (spec section heading; 28/32 on a phone)
           'compact' = 30/34 (Paper "Katso myös")
           'sub'     = 24/28 (a heading inside a section)
    wave   underline width in px; the default follows the size.

    Extra attributes (id, class) go on the wrapper; `id` goes on the heading element so
    aria-labelledby keeps working. An optional `actions` slot sits at the right on the
    same baseline (for example "Lataa CSV").
--}}
@php
    $tag = 'h'.max(2, min(4, (int) $level));
    $waveWidth = $wave ?? match ($size) {
        'compact' => 180,
        'sub' => 110,
        default => 200,
    };
    $stroke = $size === 'sub' ? 3 : 3.5;
    $headingId = $attributes->get('id');
@endphp
<div {{ $attributes->except('id')->class(['vk-section-heading', 'vk-section-heading--'.$size]) }}>
    <div class="flex min-w-0 flex-col gap-0.5">
        <{{ $tag }} @if ($headingId) id="{{ $headingId }}" @endif class="vk-section-heading-text">{{ $slot }}</{{ $tag }}>
        <x-ink.wave :width="$waveWidth" :stroke="$stroke" />
    </div>
    @isset($actions)
        <div class="shrink-0">{{ $actions }}</div>
    @endisset
</div>
