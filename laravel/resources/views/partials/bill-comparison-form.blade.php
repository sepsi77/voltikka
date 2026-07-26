{{--
    Shared bill entry fields.

    Used by the in-listing bill mode (`seo-contracts-list.blade.php`) and by the
    contract detail page's "Vertaa nykyiseen sähkölaskuusi" module. Both bind the
    property names defined in `App\Livewire\Concerns\BillComparisonInputs`, so a
    new field belongs here and in the trait, never in one template only.

    Options:
      $idPrefix    unique DOM id prefix for the labels (default 'bill')
      $totalLabel  label for the paid-total field
--}}
@php
    $idPrefix = $idPrefix ?? 'bill';
    $totalLabel = $totalLabel ?? 'Maksoit sähköstä (€)';
@endphp

{{-- Billing period preset chips --}}
<div class="flex flex-wrap gap-2 mb-4">
    @foreach ($billPresetLabels as $key => $label)
        <button
            type="button"
            wire:click="setBillPeriodPreset('{{ $key }}')"
            aria-pressed="{{ $billPeriodPreset === $key ? 'true' : 'false' }}"
            class="px-4 py-2 rounded-full text-sm font-medium border transition-colors {{ $billPeriodPreset === $key ? 'bg-slate-950 text-white border-slate-950' : 'bg-slate-50 text-slate-600 border-slate-200 hover:border-slate-300' }}"
        >
            {{ $label }}
        </button>
    @endforeach
</div>

{{-- Custom date range --}}
@if ($billPeriodPreset === 'custom')
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        <div>
            <label for="{{ $idPrefix }}-start" class="block text-sm font-medium text-slate-700 mb-1.5">Laskutusjakson alku</label>
            <input type="date" id="{{ $idPrefix }}-start" wire:model.live="billStartDate" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500">
        </div>
        <div>
            <label for="{{ $idPrefix }}-end" class="block text-sm font-medium text-slate-700 mb-1.5">Laskutusjakson loppu</label>
            <input type="date" id="{{ $idPrefix }}-end" wire:model.live="billEndDate" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500">
        </div>
    </div>
@endif

{{-- Required inputs: kWh + total paid --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label for="{{ $idPrefix }}-kwh" class="block text-sm font-medium text-slate-700 mb-1.5">Kulutus jaksolla (kWh)</label>
        <input type="number" id="{{ $idPrefix }}-kwh" min="0" step="any" inputmode="decimal" wire:model.live.debounce.500ms="billKwh" placeholder="esim. 400" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 tabular-nums">
    </div>
    <div>
        <label for="{{ $idPrefix }}-total" class="block text-sm font-medium text-slate-700 mb-1.5">{{ $totalLabel }}</label>
        <input type="number" id="{{ $idPrefix }}-total" min="0" step="any" inputmode="decimal" wire:model.live.debounce.500ms="billTotalEur" placeholder="esim. 35" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 tabular-nums">
        <p class="text-xs text-slate-500 mt-1">Vain sähkösopimuksen osuus, ei sähkön siirtoa.</p>
    </div>
</div>

{{-- VAT basis toggle --}}
<label class="flex items-start justify-between gap-3 mt-4 p-3 rounded-lg border border-slate-200 cursor-pointer hover:bg-slate-50 sm:max-w-md">
    <span class="min-w-0">
        <span class="block text-sm font-medium text-slate-700">Hinta sisältää arvonlisäveron</span>
        <span class="block text-xs text-slate-500">Useimmissa laskuissa kyllä (alv 25,5 %).</span>
    </span>
    <span class="relative inline-flex shrink-0 mt-0.5">
        <input type="checkbox" role="switch" wire:model.live="billIncludesVat" class="peer sr-only">
        <span aria-hidden="true" class="block h-6 w-11 rounded-full bg-slate-300 transition-colors peer-checked:bg-coral-500"></span>
        <span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
    </span>
</label>
