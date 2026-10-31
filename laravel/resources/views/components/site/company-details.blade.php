{{-- Legal identity of the operating company. /tietoa, /tietosuoja and /kayttoehdot share it. --}}
@php($about = \App\Livewire\AboutPage::class)
<address {{ $attributes->merge(['class' => 'vk-lr-text not-italic']) }}>
    <strong>{{ $about::COMPANY_NAME }}</strong><br>
    Rekisterikoodi {{ $about::COMPANY_REGISTRATION_CODE }}<br>
    ALV-numero {{ $about::COMPANY_VAT_ID }}<br>
    {{ $about::COMPANY_STREET }}, {{ $about::COMPANY_POSTAL_CODE }} {{ $about::COMPANY_CITY }}, Viro
</address>
