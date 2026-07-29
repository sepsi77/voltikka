@props([
    'updated' => null,
    'dateLabel' => 'Päivitetty',
])
{{--
    This byline names the maintainer and links to the method. The optional date must be
    a real review or update date. Use dateLabel when the date needs a more exact meaning.
--}}
<p {{ $attributes->merge(['class' => 'text-xs text-slate-500 leading-relaxed']) }}>
    Sisällön ja laskentamenetelmän ylläpidosta vastaa Voltikka, riippumaton harrasteprojekti.@if($updated) {{ $dateLabel }} {{ $updated }}.@endif
    <a href="/tietoa#menetelma" class="font-medium text-slate-700 underline decoration-slate-300 underline-offset-2 hover:text-coral-600">Tietoa Voltikasta ja menetelmästä</a>.
</p>
