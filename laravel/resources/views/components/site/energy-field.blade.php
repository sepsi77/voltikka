@props([
    'id',
    'label',
    'model',
    'unit' => null,
    'min' => '0',
    'max' => null,
    'step' => null,
    'placeholder' => null,
    'size' => 'lg',
])
{{--
    Underlined number field of the energy calculators (design spec §5 "Field", Paper board C1D-0
    "Inputs"): label above, no box, the value in Bricolage, the unit in ink-muted, a 2px ink
    underline that turns fox on focus. `model` is the Livewire property; the binding is always
    wire:model.live.blur (form input policy). `size` lg = 28px value, sm = 20px value.
--}}
<div {{ $attributes->class(['energy-labelled', 'energy-labelled--'.$size]) }}>
    <label for="{{ $id }}" class="energy-label">{{ $label }}</label>
    <div class="energy-field">
        <input
            id="{{ $id }}"
            type="number"
            wire:model.live.blur="{{ $model }}"
            min="{{ $min }}"
            @if ($max !== null) max="{{ $max }}" @endif
            @if ($step !== null) step="{{ $step }}" @endif
            @if ($placeholder !== null) placeholder="{{ $placeholder }}" @endif
            class="energy-field-input"
        >
        @if ($unit)
            <span class="energy-field-unit" aria-hidden="true">{{ $unit }}</span>
        @endif
    </div>
</div>
