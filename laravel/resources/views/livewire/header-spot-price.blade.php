<div>
    @if ($currentPrice)
        <a href="/spot-price" class="flex items-center gap-2 bg-coral-50 px-3 py-1.5 sm:px-4 sm:py-2 rounded-xl border border-coral-200 hover:border-coral-300 hover:bg-coral-100 transition-colors">
            <div class="w-2 h-2 bg-coral-500 rounded-full animate-pulse"></div>
            <span class="text-xs sm:text-sm text-coral-700 font-medium">Spot</span>
            <span class="text-xs sm:text-sm font-bold text-coral-900">{{ number_format($currentPrice['price_with_tax'], 2, ',', ' ') }} c/kWh</span>
        </a>
    @else
        <a href="/spot-price" class="flex items-center gap-2 bg-slate-100 px-3 py-1.5 sm:px-4 sm:py-2 rounded-xl border border-slate-200 hover:border-slate-300 transition-colors">
            <span class="text-xs sm:text-sm text-slate-500">Spot-hinta</span>
            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </a>
    @endif
</div>
