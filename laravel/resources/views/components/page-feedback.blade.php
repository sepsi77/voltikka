@props(['pageType', 'subject' => null, 'ledger' => false])
@php
    // "Löysitkö etsimäsi?" (Paper page "Feedback · Löysitkö etsimäsi?"). Every step is in the
    // HTML; resources/js/page-feedback.js shows the widget, shuffles the choices and moves
    // between steps. The root starts hidden, so a visitor without JavaScript sees nothing.
    // wire:ignore keeps the visitor's step when Livewire re-renders the page around it.
    $hasVote = \App\Services\Analytics\PageFeedbackCatalog::hasVote($pageType);
    $reasons = \App\Services\Analytics\PageFeedbackCatalog::reasonsFor($pageType);
    $goals = \App\Services\Analytics\PageFeedbackCatalog::goalsFor($pageType);
    $context = app(\App\Services\Analytics\PageFeedbackContextSigner::class)->sign($pageType, $subject);
    $uid = 'vk-fb-'.$pageType;
    $thumbUp = 'M16 26 L20.8 26 L20.8 38.6 L16 38.6 Z M20.8 27 L24.4 26 L27.6 17.6 C 28.1 16.1, 30.6 16.2, 31 18.1 L30.5 24.8 L37.4 24.8 C 39.2 24.8, 40.2 26.4, 39.7 28 L37.6 36.8 C 37.2 38.1, 36.2 38.6, 35 38.6 L20.8 38.6';
    $thumbDown = 'M16 17.4 L20.8 17.4 L20.8 30 L16 30 Z M20.8 29 L24.4 30 L27.6 38.4 C 28.1 39.9, 30.6 39.8, 31 37.9 L30.5 31.2 L37.4 31.2 C 39.2 31.2, 40.2 29.6, 39.7 28 L37.6 19.2 C 37.2 17.9, 36.2 17.4, 35 17.4 L20.8 17.4';
    $privacyNote = 'Älä kirjoita nimeäsi, puhelinnumeroasi tai sähköpostiosoitettasi.';
@endphp
<section
    {{ $attributes->merge(['class' => 'vk-fb'.($ledger ? ' vk-fb--ledger' : '')]) }}
    data-page-feedback
    data-feedback-page-type="{{ $pageType }}"
    data-feedback-start="{{ $hasVote ? 'vote' : 'goal' }}"
    data-feedback-context="{{ $context }}"
    aria-label="Palaute sivusta"
    hidden
    wire:ignore
    wire:key="page-feedback-{{ $pageType }}"
>
    <div class="vk-fb-kicker">
        <span class="vk-fb-kicker-label"><x-ink.sparkle color="var(--color-fox)" />Voltikka kysyy</span>
        <span class="vk-fb-kicker-end">
            <span class="vk-fb-counter" data-feedback-counter></span>
            <button type="button" class="vk-fb-dismiss" data-feedback-action="dismiss">
                <span data-feedback-dismiss-label>{{ $hasVote ? 'Ei nyt' : 'Sulje' }}</span>
                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="M3 3.2 L11 10.9 M10.8 3 L3.1 11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
            </button>
        </span>
    </div>

    @if ($hasVote)
        <div class="vk-fb-step" data-feedback-step="vote">
            <div class="vk-fb-lane" aria-hidden="true">
                <svg width="48" height="44" viewBox="0 0 48 44" focusable="false"><path d="M8.5 9 C 16 6.5, 33 6, 41 8.5 C 43.5 13, 43.8 22, 41.5 27.5 C 33 29.6, 24 29.8, 19.5 29.6 L 11.5 37.5 L 12.6 29.2 C 9.8 28.8, 8 28.3, 7.4 27.5 C 5.6 21, 5.9 13.8, 8.5 9 Z" style="fill: var(--color-edge)" /><path d="M6.5 7.2 C 15 4.8, 32 4.2, 39.5 6.8 C 42 11.5, 42.2 20.4, 40 26 C 31.5 28.2, 22.5 28.2, 18 28 L 10 36 L 11.2 27.6 C 8.4 27.2, 6.6 26.7, 6 25.9 C 4.2 19.4, 4.2 12, 6.5 7.2 Z" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" /><path d="M15.5 17.5 C 15.9 17.3, 16.4 17.4, 16.5 17.9 M23 17.3 C 23.4 17.1, 23.9 17.2, 24 17.7 M30.5 17.4 C 30.9 17.2, 31.4 17.3, 31.5 17.8" fill="none" style="stroke: var(--color-ink)" stroke-width="2.4" stroke-linecap="round" /></svg>
            </div>
            <div class="vk-fb-question">
                <h2 class="vk-fb-heading" id="{{ $uid }}-question">Löysitkö etsimäsi?</h2>
                <p class="vk-fb-lead">Vastauksesi auttaa meitä parantamaan palvelua.</p>
            </div>
            <div class="vk-fb-votes" role="group" aria-labelledby="{{ $uid }}-question">
                @foreach (['up' => ['Kyllä', $thumbUp], 'down' => ['En', $thumbDown]] as $vote => [$label, $path])
                    <button type="button" class="vk-fb-vote" data-feedback-vote="{{ $vote }}">
                        <svg width="56" height="56" viewBox="0 0 56 56" aria-hidden="true" focusable="false"><circle cx="30" cy="30" r="23" style="fill: var(--color-edge)" /><path d="M27.5 4.6 C 41.5 3.8, 52.2 13.6, 51.4 27.2 C 50.8 40.5, 40.6 50.6, 27.3 50.9 C 14 51.2, 4.4 41, 4.6 27.6 C 4.8 15.4, 13.8 5.6, 25 4.9" fill="none" style="stroke: var(--color-ink)" stroke-width="1.8" stroke-linecap="round" /><path d="{{ $path }}" fill="none" style="stroke: var(--color-ink)" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" /></svg>
                        <span>{{ $label }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @foreach (array_filter(['reason' => $reasons, 'goal' => $goals]) as $step => $choices)
        <div class="vk-fb-step" data-feedback-step="{{ $step }}" @if ($hasVote || $step !== 'goal') hidden @endif>
            @if ($hasVote)
                <div class="vk-fb-lane" aria-hidden="true">
                    @foreach (['up' => [$thumbUp, 'Vastasit: kyllä'], 'down' => [$thumbDown, 'Vastasit: en']] as $vote => [$path, $answered])
                        <span class="vk-fb-mark" data-feedback-mark="{{ $vote }}" @if ($step === 'goal' || $vote === 'up') hidden @endif>
                            <svg width="48" height="48" viewBox="0 0 56 56" focusable="false"><circle cx="28" cy="28" r="23.5" style="fill: var(--color-fox)" /><path d="{{ $path }}" fill="none" stroke="#FFFFFF" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" /></svg>
                            <span>{{ $answered }}</span>
                        </span>
                    @endforeach
                </div>
            @endif
            <div class="vk-fb-body" role="group" aria-labelledby="{{ $uid }}-{{ $step }}-heading">
                <div class="vk-fb-question">
                    @if ($step === 'reason')
                        <h2 class="vk-fb-heading" id="{{ $uid }}-{{ $step }}-heading" tabindex="-1" data-feedback-focus>Kiitos. Mikä jäi puuttumaan?</h2>
                        <p class="vk-fb-lead">Valitse parhaiten sopiva vaihtoehto. Näin tiedämme, mitä korjata ensin.</p>
                    @else
                        <h2 class="vk-fb-heading" id="{{ $uid }}-{{ $step }}-heading" tabindex="-1" data-feedback-focus data-feedback-goal-heading
                            data-heading-up="Hienoa! Mitä olit tänään tekemässä?"
                            data-heading-down="Viimeinen kysymys: mitä olit tänään tekemässä?">{{ $hasVote ? 'Mitä olit tänään tekemässä?' : 'Mitä olit etsimässä?' }}</h2>
                        <p class="vk-fb-lead">{{ $hasVote ? 'Vastaus kertoo meille, miksi ihmiset tulevat Voltikkaan.' : 'Vastauksesi auttaa meitä korjaamaan puuttuvat sivut ja linkit.' }}</p>
                    @endif
                </div>

                <div class="vk-fb-options" data-feedback-options>
                    @foreach ($choices as $code => $label)
                        <label class="vk-fb-option" @if ($code === \App\Services\Analytics\PageFeedbackCatalog::OTHER) data-feedback-fixed-last @endif>
                            <input type="radio" class="vk-fb-radio" name="{{ $uid }}-{{ $step }}" value="{{ $code }}">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="vk-fb-end">
                    <div class="vk-fb-field">
                        <label class="vk-fb-field-label" for="{{ $uid }}-{{ $step }}-text">
                            {{ $step === 'reason' ? 'Kerro tarkemmin' : 'Kerro omin sanoin' }} <span>(vapaaehtoinen)</span>
                        </label>
                        <div class="vk-fb-field-line">
                            <textarea id="{{ $uid }}-{{ $step }}-text" rows="2" maxlength="500" aria-describedby="{{ $uid }}-{{ $step }}-note"></textarea>
                            <span class="vk-fb-count" data-feedback-count aria-hidden="true">0/500</span>
                        </div>
                        <p class="vk-fb-note" id="{{ $uid }}-{{ $step }}-note">{{ $privacyNote }}</p>
                    </div>
                    <div class="vk-fb-actions">
                        <button type="button" class="vk-fb-submit" data-feedback-action="submit">
                            <span>{{ $step === 'reason' ? 'Jatka' : 'Lähetä' }} &rarr;</span>
                            <x-ink.wave :width="80" />
                        </button>
                        <button type="button" class="vk-fb-skip" data-feedback-action="skip">Ohita</button>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="vk-fb-step vk-fb-thanks" data-feedback-step="thanks" role="status" hidden>
        <div class="vk-fb-lane" aria-hidden="true">
            <svg width="44" height="36" viewBox="0 0 44 36" focusable="false"><path d="M14 4 C 14.7 10, 18 13.3, 24 14 C 18 14.7, 14.7 18, 14 24 C 13.3 18, 10 14.7, 4 14 C 10 13.3, 13.3 10, 14 4 Z" style="fill: var(--color-fox)" /><path d="M33 16 C 33.4 19.5, 35.5 21.6, 39 22 C 35.5 22.4, 33.4 24.5, 33 28 C 32.6 24.5, 30.5 22.4, 27 22 C 30.5 21.6, 32.6 19.5, 33 16 Z" style="fill: var(--color-star)" /><path d="M30 3 C 30.3 5.2, 31.8 6.7, 34 7 C 31.8 7.3, 30.3 8.8, 30 11 C 29.7 8.8, 28.2 7.3, 26 7 C 28.2 6.7, 29.7 5.2, 30 3 Z" style="fill: var(--color-fox-deep)" /></svg>
        </div>
        <p class="vk-fb-thanks-text"><strong tabindex="-1" data-feedback-focus>Kiitos! Vastauksesi on tallessa.</strong> <span>Käytämme vastauksia sivun parantamiseen.</span></p>
    </div>

    @if ($ledger)
        <x-ink.hairline class="vk-ledger-sep" />
    @endif
</section>
