@props(['warnings' => [], 'facts' => []])
{{--
    Two visual classes, warnings first.

    Warnings are filled coral pills: things that qualify or limit the price. They use coral
    rather than amber because DESIGN.md reserves red/amber/green for measured emissions
    data. Priority order and the two-item cap live in
    App\Services\ContractCard\CardFooterItems, not here.

    Facts are quiet inline tags: a promotion, and the energy source with its real figure.
    The energy tag also carries what the card's coloured left stripe used to encode.

    What used to be here and is gone: the percentile callouts ("Edullinen marginaali"),
    which only ever rendered on SEO listing pages, and the bare "Arvio" tag, which now sits
    in the band next to the number it qualifies.

    The footer surface is WHITE with a hairline, not a tinted strip. It was `slate-50`, which
    is the exact colour of the page body (`layouts/app.blade.php`), so the card's bottom edge
    dissolved: reading down a list you got white body, invisible footer, then the next card's
    band. A caveat that floats between two cards is worse than no caveat, because it is
    ambiguous about which contract it qualifies. Keeping the footer white also leaves the type
    band as the card's only tinted strip, so the category reads harder.
--}}
@if (count($warnings) > 0 || count($facts) > 0)
    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-200 px-6 py-3 text-sm font-semibold">
        @foreach ($warnings as $warning)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-coral-200 bg-coral-50 px-3 py-1 text-coral-700">
                <svg class="h-3.5 w-3.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v2m0 4h.01M5 19h14c1.5 0 2.5-1.7 1.7-3L13.7 4c-.8-1.3-2.7-1.3-3.5 0L3.3 16c-.7 1.3.3 3 1.7 3z"/></svg>
                {{ $warning->text }}
            </span>
        @endforeach

        @foreach ($facts as $fact)
            <span class="inline-flex items-center gap-1.5 {{ $fact->icon === 'leaf' ? 'text-emerald-700' : 'text-coral-700' }}">
                @if ($fact->icon === 'leaf')
                    <svg class="h-3.5 w-3.5 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17 8C8 10 5.9 16.2 3.8 21.3l1.9.7 1-2.3c.5.2 1 .3 1.3.3C19 20 22 3 22 3c-1 2-8 2.2-13 3.2S2 11.5 2 13.5s1.8 3.8 1.8 3.8C7 8 17 8 17 8z"/></svg>
                @else
                    <svg class="h-3.5 w-3.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 7h.01M7 3h5c.5 0 1 .2 1.4.6l7 7a2 2 0 010 2.8l-7 7a2 2 0 01-2.8 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg>
                @endif
                {{ $fact->text }}
            </span>
        @endforeach
    </div>
@endif
