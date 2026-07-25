@props([
    'company' => null,
    'name' => null,
    'imgClass' => 'bg-white',
])
{{--
    A company logo tile that always occupies its space.

    The initials render first and the logo paints over them; if the logo fails to load it is
    removed and the initials stay. There is NO external placeholder service involved.

    This replaced five hand-rolled copies of an `@if (getLogoUrl())` / `@else` pair whose error
    fallback was `onerror="this.src='https://placehold.co/...'"`. That had two problems:

    1. When placehold.co was slow or blocked, the fallback image failed too and the tile
       rendered as a blank gap. Observed live during a design review, where every card on the
       listing lost its logo.
    2. It sent every visitor's browser to a third-party host on page load, on a site that
       deliberately uses privacy-friendly analytics and controls its own asset hosting.

    The logo starts at `opacity:0` and is revealed by its own `onload`, but only when it
    actually decoded (`naturalWidth` is non-zero). That covers every state with no blank tile:

    - request in flight: the initials show through, however long the host takes
    - network error: `onerror` removes the image, initials stay
    - 200 response that is not a decodable image (an error page, a redirect to HTML): `onload`
      fires rather than `onerror`, so the `naturalWidth` guard is what removes it. Without the
      guard the browser paints its own broken-image icon over the initials.
    - success: the logo appears over the initials

    Inline style, not a utility class, so the reveal cannot depend on a CSS build step.

    The tile is decorative: the company name is always present as adjacent text wherever this
    is used, so the initials are aria-hidden and only the logo carries alt text.

    Sizing, radius and colours come from the caller via $attributes, because the tile appears
    on white cards, on a dark hero, and inside a green renewable-energy panel.
--}}
@php
    $label = $name ?? $company?->name ?? '';
    $logoUrl = $company?->getLogoUrl();
@endphp
<span {{ $attributes->class(['relative flex flex-shrink-0 items-center justify-center overflow-hidden']) }}>
    <span aria-hidden="true">{{ mb_substr($label ?: '?', 0, 3) }}</span>
    @if ($logoUrl)
        <img
            src="{{ $logoUrl }}"
            alt="{{ $label }}"
            class="absolute inset-0 h-full w-full object-contain {{ $imgClass }}"
            loading="lazy"
            style="opacity:0"
            onload="this.naturalWidth ? this.style.opacity = '' : this.remove()"
            onerror="this.remove()"
        >
    @endif
</span>
