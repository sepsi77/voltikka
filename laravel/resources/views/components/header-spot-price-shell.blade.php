<div data-header-spot-price data-url="/api/header-spot-price" data-state="loading" aria-live="polite">
    <a href="/spot-price" class="flex items-center gap-2 bg-slate-100 px-3 py-1.5 sm:px-4 sm:py-2 rounded-xl border border-slate-200 hover:border-slate-300 transition-colors">
        <span class="relative flex h-2.5 w-2.5">
            <span class="absolute inline-flex h-full w-full rounded-full bg-slate-300 opacity-75 animate-pulse"></span>
            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-slate-400"></span>
        </span>
        <span class="text-xs sm:text-sm text-slate-500">Spot-hinta</span>
        <span class="text-xs sm:text-sm font-semibold text-slate-500">Ladataan…</span>
    </a>
    <template data-header-spot-price-unavailable>
        <a href="/spot-price" class="flex items-center gap-2 bg-slate-100 px-3 py-1.5 sm:px-4 sm:py-2 rounded-xl border border-slate-200 hover:border-slate-300 transition-colors">
            <span class="text-xs sm:text-sm text-slate-500">Spot-hintaa ei ole saatavilla</span>
            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </a>
    </template>
</div>
