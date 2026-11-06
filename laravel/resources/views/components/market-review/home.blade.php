@props(['art', 'kwh', 'wave' => 300])
{{--
    Section heading of one home type: the seasonal home vignette, the yearly consumption in caps
    and the display heading with the fox wave (Paper "Sähkölämmitteinen pientalo" group).
    Extra attributes (id) go on the heading.
--}}
<div class="mr-home">
    <img src="{{ \App\Support\Season::art($art) }}" alt="" width="110" height="96" class="mr-home-art" loading="lazy" decoding="async">
    <div class="mr-home-text">
        <p class="mr-caps">Noin {{ $kwh }} kWh vuodessa</p>
        <h2 {{ $attributes->merge(['class' => 'mr-section-title']) }}>{{ $slot }}</h2>
        <x-ink.wave :width="$wave" stroke="3" />
    </div>
</div>
