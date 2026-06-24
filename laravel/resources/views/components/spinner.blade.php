@props([
    'size' => 'h-5 w-5',
    'color' => 'text-coral-500',
    'label' => null,
])

@php
    // Single source of truth for the site's coral loading spinner.
    // Used by the calculators (bill comparison, heat pump, solar) with
    // wire:loading directives on the wrapping element. Keep the SVG markup
    // identical across usages so the loading indicator stays visually
    // consistent. `label` is optional accessible text (visually hidden).
@endphp

<svg
    class="animate-spin {{ $size }} {{ $color }}"
    xmlns="http://www.w3.org/2000/svg"
    fill="none"
    viewBox="0 0 24 24"
    role="status"
    @if ($label) aria-label="{{ $label }}" @else aria-hidden="true" @endif
>
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
</svg>
