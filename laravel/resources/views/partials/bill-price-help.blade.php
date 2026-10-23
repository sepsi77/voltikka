{{-- "Mikä hinta syötetään?": the help beside the torn-paper bill. One source for /maksatko-liikaa
     and the listing bill panel, so the two explanations cannot drift. `headingTag` keeps the
     page's heading order (h2 on the tool page, h3 under the listing's results heading). --}}
@php $headingTag = $headingTag ?? 'h2'; @endphp
<div class="flex gap-3.5">
    <svg width="40" height="40" viewBox="0 0 40 40" class="shrink-0" aria-hidden="true" focusable="false">
        <path d="M20 4 C 29 4.3, 36 11, 35.7 20.2 C 35.4 29.4, 28.6 36, 19.6 35.8 C 10.6 35.5, 4.2 28.6, 4.4 19.6 C 4.6 10.8, 11.4 3.8, 20 4 Z" fill="none" style="stroke: var(--color-fox)" stroke-width="2.2" />
        <path d="M20 17.5 V27.5" fill="none" style="stroke: var(--color-ink)" stroke-width="2.6" stroke-linecap="round" />
        <circle cx="20" cy="12.4" r="1.8" style="fill: var(--color-ink)" />
    </svg>
    <div class="flex min-w-0 flex-col gap-1.5">
        <{{ $headingTag }} class="tool-h3 tool-h3--lg">Mikä hinta syötetään?</{{ $headingTag }}>
        <p class="text-base leading-[26px] text-ink-body">Syötä <strong class="font-extrabold text-ink">vain sähkösopimuksen hinta</strong>, ei sähkön siirtoa. Siirto laskutetaan verkkoyhtiöltä erikseen, eikä sitä voi säästää vaihtamalla sopimusta. Jos saat yhdistelmälaskun, etsi "sähkö"- tai "energia"-rivi. Hinnan voi syöttää verollisena; merkitse valinnalla, sisältyykö hintaan ALV.</p>
    </div>
</div>
