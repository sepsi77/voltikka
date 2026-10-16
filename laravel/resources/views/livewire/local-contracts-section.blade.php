<div>
    {{-- City page local tiers (redesign, Paper board 5BI-0 "Local companies section"): display
         headings with an inked map-pin vignette and the wavy fox underline, ledger entries
         without a rank, an inked "Näytä kaikki" action, a paw-print divider, then the heading of
         the main list. The distance (or "Alueellinen") is an inked map-pin mark; on a wide
         screen CSS moves it onto the entry's category line, at the left of "Arvio" (spec §5
         "City listings"). The fox and villagers never appear here. --}}
    @php
        $pin = '<svg class="vk-local-pin" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M9 16.2 C 6.2 12.6, 4 10, 4 7.2 C 4 4.4, 6.2 2.4, 9 2.4 C 11.8 2.4, 14 4.4, 14 7.2 C 14 10, 11.8 12.6, 9 16.2 Z" fill="none" style="stroke: var(--color-fox-deep)" stroke-width="1.7" stroke-linejoin="round" /><circle cx="9" cy="7.2" r="1.9" style="fill: var(--color-fox-deep)" /></svg>';
    @endphp
    @if($hasContent)
    <section class="vk-seo-local">
        {{-- Local Companies Section (Tier 1) --}}
        @if($localCompanyContracts->isNotEmpty())
        <div x-data="{ expanded: false }" class="vk-seo-local-tier">
            <div class="vk-seo-local-head">
                <x-site.seo-pin class="vk-seo-local-vignette" />
                <div class="min-w-0">
                    <x-site.section-heading :wave="300">Lähialueen sähköyhtiöt</x-site.section-heading>
                    <p class="vk-seo-subline">
                        Sähköyhtiöt, joiden kotipaikka on lähellä {{ $cityName }}a
                    </p>
                </div>
            </div>

            <div class="vk-ledger">
                @foreach($localCompanyContracts as $index => $contract)
                    <div class="vk-seo-local-item" x-show="expanded || {{ $index }} < 10" x-collapse.duration.300ms>
                        @if(isset($contract->company_distance_km) && $contract->company_distance_km > 0)
                            <p class="vk-local-mark">
                                {!! $pin !!}
                                {{ number_format($contract->company_distance_km, 0) }} km
                            </p>
                        @elseif(isset($contract->company_distance_km) && $contract->company_distance_km == 0)
                            <p class="vk-local-mark">
                                {!! $pin !!}
                                {{ $cityName }}
                            </p>
                        @endif
                        <x-contract-card
                            :contract="$contract"
                            :rank="$index + 1"
                            :consumption="$consumption"
                            :showRank="false"
                        />
                    </div>
                @endforeach
            </div>

            {{-- Expand/Collapse --}}
            @if($localCompanyContracts->count() > 10)
            <div class="vk-seo-show-all">
                <button type="button" @click="expanded = !expanded" class="vk-action vk-seo-action--center vk-focus" :aria-expanded="expanded ? 'true' : 'false'">
                    <span class="inline-flex items-center gap-2">
                        <span x-text="expanded ? 'Näytä vähemmän' : 'Näytä kaikki {{ $localCompanyContracts->count() }} sopimusta'">Näytä kaikki {{ $localCompanyContracts->count() }} sopimusta</span>
                        <svg class="vk-chevron" :class="expanded ? 'rotate-180' : ''" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </span>
                    <x-ink.wave width="230" stroke="2.6" />
                </button>
            </div>
            @endif
        </div>
        @endif

        {{-- Regional Contracts Section (Tier 2) --}}
        @if($regionalContracts->isNotEmpty())
        <div x-data="{ expanded: false }" class="vk-seo-local-tier">
            <div class="vk-seo-local-head">
                <x-site.seo-pin class="vk-seo-local-vignette" />
                <div class="min-w-0">
                    <x-site.section-heading :wave="300">Alueelliset sopimukset {{ $cityLocative }}</x-site.section-heading>
                    <p class="vk-seo-subline">
                        Sopimukset, jotka ovat saatavilla vain tietyillä alueilla
                    </p>
                </div>
            </div>

            <div class="vk-ledger">
                @foreach($regionalContracts as $index => $contract)
                    <div class="vk-seo-local-item" x-show="expanded || {{ $index }} < 10" x-collapse.duration.300ms>
                        <p class="vk-local-mark">
                            {!! $pin !!}
                            Alueellinen
                        </p>
                        <x-contract-card
                            :contract="$contract"
                            :rank="$index + 1"
                            :consumption="$consumption"
                            :showRank="false"
                        />
                    </div>
                @endforeach
            </div>

            {{-- Expand/Collapse --}}
            @if($regionalContracts->count() > 10)
            <div class="vk-seo-show-all">
                <button type="button" @click="expanded = !expanded" class="vk-action vk-seo-action--center vk-focus" :aria-expanded="expanded ? 'true' : 'false'">
                    <span class="inline-flex items-center gap-2">
                        <span x-text="expanded ? 'Näytä vähemmän' : 'Näytä kaikki {{ $regionalContracts->count() }} sopimusta'">Näytä kaikki {{ $regionalContracts->count() }} sopimusta</span>
                        <svg class="vk-chevron" :class="expanded ? 'rotate-180' : ''" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </span>
                    <x-ink.wave width="230" stroke="2.6" />
                </button>
            </div>
            @endif
        </div>
        @endif

        <x-site.seo-paws />

        {{-- Heading before the main list --}}
        <div>
            <x-site.section-heading :wave="330">Kaikki sopimukset {{ $cityLocative }}</x-site.section-heading>
            <p class="vk-seo-subline">
                Vertaa kaikkia saatavilla olevia sähkösopimuksia
            </p>
        </div>
    </section>
    @endif
</div>
