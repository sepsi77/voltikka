{{--
    Shared bill entry fields.

    Used by the in-listing bill mode (`seo-contracts-list.blade.php`) and by the
    contract detail page's "Vertaa nykyiseen sähkölaskuusi" module. Both bind the
    property names defined in `App\Livewire\Concerns\BillComparisonInputs`, so a
    new field belongs here and in the trait, never in one template only.

    Options:
      $idPrefix    unique DOM id prefix for the labels (default 'bill')
      $totalLabel  label for the paid-total field
      $paper       true = the torn-paper bill layout of /maksatko-liikaa (contract detail page,
                   round 3 board IND-0): bill lines with a dotted leader, dates shown for every
                   period choice. The caller draws the paper (edges, body, title) around it.
                   Default false keeps the compact listing form. Both layouts bind the same
                   properties with the same ids, so a field is still added in one place.
--}}
@php
    $idPrefix = $idPrefix ?? 'bill';
    $totalLabel = $totalLabel ?? 'Maksoit sähköstä (€)';
    $paper = $paper ?? false;
@endphp

@if ($paper)
    {{-- Paper bill layout: the classes are the /maksatko-liikaa bill (redesign-tools.css). --}}
    <div class="flex flex-col gap-3">
        <p class="text-sm font-extrabold leading-[18px] text-ink" id="{{ $idPrefix }}-period-label">Laskutusjakso</p>
        <div class="tool-radios bill-periods bill-periods--grid" role="group" aria-labelledby="{{ $idPrefix }}-period-label">
            @foreach ($billPresetLabels as $key => $label)
                @php $isPeriod = $billPeriodPreset === $key; @endphp
                <button
                    type="button"
                    wire:click="setBillPeriodPreset('{{ $key }}')"
                    aria-pressed="{{ $isPeriod ? 'true' : 'false' }}"
                    class="tool-radio tool-period"
                >
                    @if ($isPeriod)
                        <svg class="vk-mark tool-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    @else
                        <svg class="vk-mark tool-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
                    @endif
                    {{ $label }}
                </button>
            @endforeach
        </div>
        {{-- Dates show for every period choice; a preset fills them, editing one selects "Muu jakso". --}}
        <div class="grid grid-cols-2 gap-5 pt-1 sm:gap-7">
            <div class="tool-field-wrap">
                <label for="{{ $idPrefix }}-start" class="text-[13px] font-bold leading-4 text-ink-muted">Alkaa</label>
                <input type="date" id="{{ $idPrefix }}-start" wire:model.live.blur="billStartDate" class="bill-date">
            </div>
            <div class="tool-field-wrap">
                <label for="{{ $idPrefix }}-end" class="text-[13px] font-bold leading-4 text-ink-muted">Päättyy</label>
                <input type="date" id="{{ $idPrefix }}-end" wire:model.live.blur="billEndDate" class="bill-date">
            </div>
        </div>
    </div>

    <div class="flex flex-col gap-5">
        <div class="flex flex-col gap-1">
            <div class="bill-line">
                <label for="{{ $idPrefix }}-kwh" class="bill-line-label">Kulutus jaksolla (kWh)</label>
                <span class="vk-leader" aria-hidden="true"></span>
                <span class="tool-field bill-line-field">
                    <input type="number" id="{{ $idPrefix }}-kwh" min="1" step="any" inputmode="decimal" wire:model.live.blur="billKwh" placeholder="esim. 400" @if (isset($billInputNotices['billKwh'])) aria-invalid="true" aria-describedby="{{ $idPrefix }}-kwh-notice" @endif>
                    <span class="tool-unit" aria-hidden="true">kWh</span>
                </span>
            </div>
            @if (isset($billInputNotices['billKwh']))
                <p id="{{ $idPrefix }}-kwh-notice" role="alert" class="vk-notice">{{ $billInputNotices['billKwh'] }}</p>
            @endif
        </div>
        <div class="flex flex-col gap-1">
            <div class="bill-line">
                <label for="{{ $idPrefix }}-total" class="bill-line-label">{{ $totalLabel }}</label>
                <span class="vk-leader" aria-hidden="true"></span>
                <span class="tool-field bill-line-field">
                    <input type="number" id="{{ $idPrefix }}-total" min="0.01" step="any" inputmode="decimal" wire:model.live.blur="billTotalEur" placeholder="esim. 35" aria-describedby="{{ $idPrefix }}-total-hint{{ isset($billInputNotices['billTotalEur']) ? ' '.$idPrefix.'-total-notice' : '' }}" @if (isset($billInputNotices['billTotalEur'])) aria-invalid="true" @endif>
                    <span class="tool-unit" aria-hidden="true">€</span>
                </span>
            </div>
            <p id="{{ $idPrefix }}-total-hint" class="text-[13px] leading-[18px] text-ink-muted">Vain sähkösopimuksen osuus, ei sähkön siirtoa.</p>
            @if (isset($billInputNotices['billTotalEur']))
                <p id="{{ $idPrefix }}-total-notice" role="alert" class="vk-notice">{{ $billInputNotices['billTotalEur'] }}</p>
            @endif
        </div>

        <label class="bill-check bill-check--first">
            <input type="checkbox" role="switch" wire:model.live="billIncludesVat" class="vk-checkbox bill-checkbox">
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="text-[15px] font-extrabold leading-5 text-ink">Hinta sisältää arvonlisäveron</span>
                <span class="text-[13px] leading-[19px] text-ink-muted">Useimmissa laskuissa kyllä (alv 25,5 %).</span>
            </span>
        </label>
    </div>
@else

{{-- Redesign (design spec §5 "Field", "Pricing-category choice row"): the period presets are
     radio-look choices, the fields are underlined, the VAT switch is a real checkbox drawn as
     the design checkbox. No cards, chips or pills. Bindings and ids are unchanged. --}}

{{-- Billing period presets --}}
<div class="vk-choices mb-4" role="group" aria-label="Laskutusjakso">
    @foreach ($billPresetLabels as $key => $label)
        @php $isPeriod = $billPeriodPreset === $key; @endphp
        <button
            type="button"
            wire:click="setBillPeriodPreset('{{ $key }}')"
            aria-pressed="{{ $isPeriod ? 'true' : 'false' }}"
            class="vk-choice"
        >
            @if ($isPeriod)
                <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
            @else
                <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
            @endif
            {{ $label }}
        </button>
    @endforeach
</div>

{{-- Custom date range --}}
@if ($billPeriodPreset === 'custom')
    <div class="mb-5 grid max-w-xl grid-cols-1 gap-5 sm:grid-cols-2">
        <div>
            <label for="{{ $idPrefix }}-start" class="vk-form-label">Laskutusjakson alku</label>
            <input type="date" id="{{ $idPrefix }}-start" wire:model.live.blur="billStartDate" class="vk-input">
        </div>
        <div>
            <label for="{{ $idPrefix }}-end" class="vk-form-label">Laskutusjakson loppu</label>
            <input type="date" id="{{ $idPrefix }}-end" wire:model.live.blur="billEndDate" class="vk-input">
        </div>
    </div>
@endif

{{-- Required inputs: kWh + total paid --}}
<div class="grid max-w-xl grid-cols-1 gap-5 sm:grid-cols-2">
    <div>
        <label for="{{ $idPrefix }}-kwh" class="vk-form-label">Kulutus jaksolla (kWh)</label>
        <input type="number" id="{{ $idPrefix }}-kwh" min="1" step="any" inputmode="decimal" wire:model.live.blur="billKwh" placeholder="esim. 400" @if (isset($billInputNotices['billKwh'])) aria-invalid="true" aria-describedby="{{ $idPrefix }}-kwh-notice" @endif class="vk-input">
        @if (isset($billInputNotices['billKwh']))
            <p id="{{ $idPrefix }}-kwh-notice" role="alert" class="vk-notice mt-1">{{ $billInputNotices['billKwh'] }}</p>
        @endif
    </div>
    <div>
        <label for="{{ $idPrefix }}-total" class="vk-form-label">{{ $totalLabel }}</label>
        <input type="number" id="{{ $idPrefix }}-total" min="0.01" step="any" inputmode="decimal" wire:model.live.blur="billTotalEur" placeholder="esim. 35" @if (isset($billInputNotices['billTotalEur'])) aria-invalid="true" aria-describedby="{{ $idPrefix }}-total-notice" @endif class="vk-input">
        @if (isset($billInputNotices['billTotalEur']))
            <p id="{{ $idPrefix }}-total-notice" role="alert" class="vk-notice mt-1">{{ $billInputNotices['billTotalEur'] }}</p>
        @endif
        <p class="vk-form-hint mt-1">Vain sähkösopimuksen osuus, ei sähkön siirtoa.</p>
    </div>
</div>

{{-- VAT basis --}}
<label class="mt-5 flex max-w-md cursor-pointer items-start gap-2.5">
    <input type="checkbox" role="switch" wire:model.live="billIncludesVat" class="vk-checkbox mt-px">
    <span class="min-w-0">
        <span class="block text-[15px] font-bold leading-5 text-ink">Hinta sisältää arvonlisäveron</span>
        <span class="vk-form-hint">Useimmissa laskuissa kyllä (alv 25,5 %).</span>
    </span>
</label>
@endif
