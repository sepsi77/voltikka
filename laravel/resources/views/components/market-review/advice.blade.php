@props(['stance'])
{{-- "Neuvomme" block: a fox rule at the left, the stance in display type, then the reason. --}}
<div class="mr-advice">
    <p class="mr-caps mr-caps--fox">Neuvomme</p>
    <p class="mr-stance">{{ $stance }}</p>
    @if ($slot->isNotEmpty())
        <p class="mr-advice-text">{{ $slot }}</p>
    @endif
</div>
