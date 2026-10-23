{{--
    The torn-paper electricity bill of /maksatko-liikaa (Paper "Paper bill (form)"): white paper,
    inked sides, a 10px zigzag torn edge at the top and bottom, no shadow. The slot is the
    paper body. Used by the listing bill panel (Paper "R4 · Bill comparison beside results").
    /maksatko-liikaa and the contract detail module draw the same paper inline.
--}}
@php
    $zigTop = 'M0 8';
    $zigBottom = 'M0 6';
    for ($x = 10; $x <= 620; $x += 10) {
        $zigTop .= ' L'.$x.' '.(($x / 10) % 2 === 1 ? 1 : 8);
        $zigBottom .= ' L'.$x.' '.(($x / 10) % 2 === 1 ? 13 : 6);
    }
@endphp
<div {{ $attributes->class(['bill-paper']) }}>
    <svg class="bill-paper-edge" viewBox="0 0 620 14" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <path d="{{ $zigTop }} L620 14 L0 14 Z" style="fill: var(--color-snow-white)" />
        <path d="{{ $zigTop }}" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
    </svg>
    <div class="bill-paper-body">
        {{ $slot }}
    </div>
    <svg class="bill-paper-edge" viewBox="0 0 620 14" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <path d="{{ $zigBottom }} L620 0 L0 0 Z" style="fill: var(--color-snow-white)" />
        <path d="{{ $zigBottom }}" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
    </svg>
</div>
