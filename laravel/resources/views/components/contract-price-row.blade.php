@props([
    'label',
    'sublabel' => null,
    'type',
    'discountedComponents' => [],
    'highlight' => false,
    'value' => null,
    'unit' => null,
])

@php
$comp = $discountedComponents[$type] ?? null;

// If value is explicitly passed (e.g. computed spot average), use it directly
$hasExplicitValue = $value !== null && $unit !== null;

if (!$hasExplicitValue && !$comp) {
    return;
}

$hasDiscount = $comp && $comp['has_discount'] && ($comp['discounted_price'] !== null || $comp['discount_type'] === 'NFirstKwh');
$displayPrice = $hasExplicitValue ? (float) $value : ($comp['price'] ?? 0);
$displayUnit = $hasExplicitValue ? $unit : ($comp['unit'] ?? '');
$displayDiscountedPrice = $comp['discounted_price'] ?? null;
$discountLabel = $comp['discount_label'] ?? null;
$discountType = $comp['discount_type'] ?? null;
@endphp

<div class="flex justify-between items-start py-3.5 border-b border-slate-100 {{ $highlight ? 'bg-slate-50 -mx-6 px-6' : '' }}">
    <div>
        <span class="text-slate-600">{{ $label }}</span>
        @if ($sublabel)
            <span class="text-sm text-slate-400 ml-2">{{ $sublabel }}</span>
        @endif
        @if ($hasDiscount && $discountLabel)
            <span class="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-coral-700 bg-coral-50 px-2.5 py-1 rounded-md ring-1 ring-inset ring-coral-300/50 w-fit">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                </svg>
                {{ $discountLabel }}
            </span>
        @endif
    </div>
    <div class="text-right shrink-0 pl-4">
        @if ($hasDiscount && $displayDiscountedPrice !== null)
            <div class="flex items-baseline gap-2 justify-end flex-wrap">
                <span class="text-sm text-slate-400 line-through decoration-slate-300 tabular-nums">
                    {{ number_format($displayPrice, 2, ',', ' ') }}
                </span>
                <span class="text-xl font-bold text-coral-700 tabular-nums">
                    {{ number_format($displayDiscountedPrice, 2, ',', ' ') }}
                </span>
                <span class="text-sm font-medium text-slate-500">{{ $displayUnit }}</span>
            </div>
        @elseif ($hasDiscount && $discountType === 'NFirstKwh')
            <div class="flex items-baseline gap-1.5 justify-end">
                <span class="text-xl font-semibold text-slate-900 tabular-nums">
                    {{ number_format($displayPrice, 2, ',', ' ') }}
                </span>
                <span class="text-sm font-medium text-slate-500">{{ $displayUnit }}</span>
            </div>
            <span class="text-xs font-semibold text-coral-700 mt-0.5 block">
                {{ $discountLabel }}
            </span>
        @else
            <span class="text-xl font-semibold text-slate-900 tabular-nums">
                {{ number_format($displayPrice, 2, ',', ' ') }} {{ $displayUnit }}
            </span>
        @endif
    </div>
</div>
