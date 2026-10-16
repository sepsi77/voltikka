@props(['art' => 'interlude-walk'])
{{--
    Villager interlude strip (design spec §5 "Villager interlude strip", brand guide §4):
    a transparent painted strip, about 1000 wide, centred, no frame, between two page
    blocks. Decorative (alt=""), lazy. Never put it inside or next to a contract entry,
    a seller or a seller price. The strip height changes by season (flying birds,
    clouds), so the width is fixed and the height follows the image.
--}}
@php
    use App\Support\Season;
@endphp
<div {{ $attributes->class(['vk-interlude']) }} aria-hidden="true">
    <img
        src="{{ Season::art($art) }}"
        srcset="{{ Season::art($art, 960) }} 960w, {{ Season::art($art) }} 1672w"
        sizes="(min-width: 1064px) 1000px, calc(100vw - 32px)"
        alt=""
        loading="lazy"
        decoding="async"
        class="vk-interlude-img"
    >
</div>
