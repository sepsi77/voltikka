{{--
    Short paw-print divider between two blocks of a city listing (Paper 7DC-0): three fox prints,
    centred, at 55% opacity. Decorative.
--}}
<div {{ $attributes->merge(['class' => 'vk-seo-paws']) }} aria-hidden="true">
    @for ($i = 0; $i < 3; $i++)
        <svg width="22" height="22" viewBox="0 0 22 22" focusable="false" class="block shrink-0">
            <g style="fill: var(--color-fox-deep)">
                <ellipse cx="11" cy="14" rx="5" ry="4.2" />
                <ellipse cx="5.2" cy="8.2" rx="1.9" ry="2.4" />
                <ellipse cx="9" cy="5" rx="1.9" ry="2.5" />
                <ellipse cx="13.4" cy="5" rx="1.9" ry="2.5" />
                <ellipse cx="17" cy="8.4" rx="1.9" ry="2.4" />
            </g>
        </svg>
    @endfor
</div>
