{{-- The opened bill comparison: the same torn-paper bill and help column as /maksatko-liikaa
     (Paper "R4 · Bill comparison beside results"). The fields are the shared paper layout of
     `partials/bill-comparison-form` (period basis only; the listing has no annual-estimate
     inputs, so the tool page's heating and annual-consumption choices are not here). Needs the
     parent's Alpine `billOpen`. --}}
<div id="listing-bill-panel" x-show="billOpen" x-collapse x-cloak class="vk-bill-panel">
    <div class="tool-two-col">
        <x-site.bill-paper class="vk-detail-paper lg:w-[620px] lg:shrink-0">
            <div class="flex flex-col gap-1">
                <p class="vk-kicker">Laskun tiedot</p>
                <h3 class="bill-paper-title">Syötä sähkölaskusi tiedot</h3>
                <p class="text-[15px] leading-[22px] text-ink-body">Kaikki löytyy sähkölaskulta, et tarvitse vuosikulutusta.</p>
            </div>

            {{-- Shared bill entry fields; the contract detail module includes the same partial,
                 so the surfaces cannot drift. --}}
            @include('partials.bill-comparison-form', [
                'idPrefix' => 'bill',
                'totalLabel' => 'Sähkösopimuksen hinta (€)',
                'paper' => true,
            ])

            @if ($this->isBillModeActive())
                <button type="button" wire:click="clearBill" class="vk-inked-link vk-focus self-start">
                    Tyhjennä ja palaa normaaliin vertailuun
                </button>
            @endif
        </x-site.bill-paper>

        <div class="flex flex-col gap-[26px] lg:pt-[30px]">
            @include('partials.bill-price-help', ['headingTag' => 'h3'])
        </div>
    </div>
</div>
