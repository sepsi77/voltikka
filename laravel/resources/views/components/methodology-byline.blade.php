@props([
    'updated' => null,
    'dateLabel' => 'Päivitetty',
    'inked' => false,
])
{{--
    This byline names the maintainer and links to the method. The optional date must be
    a real review or update date. Use dateLabel when the date needs a more exact meaning.
    `inked` = the redesign look (Paper "Lue lisää + ylläpito": 13/20 ink-muted, fox-deep link);
    without it the output is the original markup.
--}}
@if ($inked)
<p {{ $attributes->merge(['class' => 'text-[13px] leading-5 text-ink-muted']) }}>
    Sisällön ja laskentamenetelmän ylläpidosta vastaa Seppo Puusa, Voltikan tekijä.@if($updated) {{ $dateLabel }} {{ $updated }}.@endif
    <a href="/tietoa#menetelma" class="vk-text-link vk-focus">Tietoa Voltikasta ja menetelmästä</a>.
</p>
@else
<p {{ $attributes->merge(['class' => 'text-xs text-slate-500 leading-relaxed']) }}>
    Sisällön ja laskentamenetelmän ylläpidosta vastaa Seppo Puusa, Voltikan tekijä.@if($updated) {{ $dateLabel }} {{ $updated }}.@endif
    <a href="/tietoa#menetelma" class="font-medium text-slate-700 underline decoration-slate-300 underline-offset-2 hover:text-coral-600">Tietoa Voltikasta ja menetelmästä</a>.
</p>
@endif
