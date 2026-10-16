{{--
    Citation formats of the data pages (Paper 6MP-0 / D8Q-0 "Viittaa tähän"): inked tabs
    (Teksti, Markdown, HTML), the citation text with a fox rule at its left, and the inked
    "Kopioi viittaus" action. Must sit inside an element with x-data="voltikkaCite({...})";
    the Alpine state (active, formats, copy) and the copy behaviour are unchanged.
--}}
<div {{ $attributes->class(['flex flex-col gap-3.5']) }}>
    <div role="tablist" class="data-cite-tabs">
        <template x-for="format in ['plain','markdown','html']" :key="format">
            <button
                type="button"
                role="tab"
                :aria-selected="active === format"
                @click="active = format"
                class="data-tab vk-focus"
                :class="active === format ? 'is-current' : ''"
            >
                <span x-text="format === 'plain' ? 'Teksti' : (format === 'markdown' ? 'Markdown' : 'HTML')"></span>
                <x-ink.wave width="full" stroke="2.6" class="data-tab-wave" />
            </button>
        </template>
    </div>

    <div class="data-cite-box">
        <span class="data-cite-rule" aria-hidden="true"></span>
        <pre x-text="formats[active]" class="data-cite-pre"></pre>
    </div>

    <button
        type="button"
        @click="copy()"
        class="data-copy data-copy--large vk-focus"
    >
        <svg x-show="!copied" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
        </svg>
        <svg x-show="copied" x-cloak width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M20 6L9 17l-5-5"/>
        </svg>
        <span x-text="copied ? 'Kopioitu' : 'Kopioi viittaus'">Kopioi viittaus</span>
    </button>
</div>
