@props([
    'updated' => null,
])
{{--
    Editorial accountability byline for authoritative/data-driven pages: names the
    maintainer (an independent hobby project, consistent with /tietoa) and links to the
    methodology. Optional "Päivitetty" date — pass a real review/refresh date, not now().
--}}
<p {{ $attributes->merge(['class' => 'text-xs text-slate-500 leading-relaxed']) }}>
    Sisällön ja laskentamenetelmän ylläpidosta vastaa Voltikka, riippumaton harrasteprojekti.@if($updated) Päivitetty {{ $updated }}.@endif
    <a href="/tietoa#menetelma" class="font-medium text-slate-700 underline decoration-slate-300 underline-offset-2 hover:text-coral-600">Tietoa Voltikasta ja menetelmästä</a>.
</p>
