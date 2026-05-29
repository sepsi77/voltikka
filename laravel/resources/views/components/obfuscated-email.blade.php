@props([
    'email',
    'label' => 'Näytä sähköposti',
    'linkClass' => '',
])
@php
    // Split server-side so the literal "user@domain" string never appears in the HTML
    // source; the address is reassembled by JS only when the user clicks to reveal it.
    [$emailUser, $emailDomain] = array_pad(explode('@', (string) $email, 2), 2, '');
@endphp
<span x-data="{ revealed: false, address() { return {!! \Illuminate\Support\Js::from($emailUser) !!} + String.fromCharCode(64) + {!! \Illuminate\Support\Js::from($emailDomain) !!}; } }" {{ $attributes }}>
    <button type="button" x-show="!revealed" @click="revealed = true" class="{{ $linkClass }} cursor-pointer bg-transparent border-0 p-0">{{ $label }}</button>
    <a x-show="revealed" x-cloak x-bind:href="revealed ? 'mailto:' + address() : '#'" x-text="revealed ? address() : ''" class="{{ $linkClass }}"></a>
</span>
