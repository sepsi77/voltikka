<?php

namespace App\Livewire;

use Livewire\Component;

/**
 * /kayttoehdot — terms of service.
 *
 * Voltikka is a comparison service, not an electricity seller or contract party. This page
 * sets out that the displayed prices/annual costs are non-binding estimates, the user is
 * responsible for their own contract choice, and the usual liability/IP/applicable-law terms.
 *
 * Contact email reuses AboutPage::CONTACT_EMAIL via the click-to-reveal <x-obfuscated-email>
 * so the address is not harvested from the HTML source.
 */
class TermsOfService extends Component
{
    /**
     * Content last-updated date (ISO). Single source of truth for both the on-page
     * "Päivitetty viimeksi" stamp and the sitemap <lastmod>. Bump only when the terms
     * text actually changes — not on every deploy.
     */
    public const LAST_UPDATED = '2026-05-30';

    public function render()
    {
        return view('livewire.terms-of-service', [
            'lastUpdated' => \Illuminate\Support\Carbon::parse(self::LAST_UPDATED)->format('j.n.Y'),
        ])->layout('layouts.app', [
            'title' => 'Käyttöehdot | Voltikka',
            'metaDescription' => 'Voltikka.fi-sivuston käyttöehdot. Voltikka on sähkösopimusten vertailupalvelu; esitetyt hinnat ja vuosikustannukset ovat suuntaa antavia arvioita, eivät sitovia tarjouksia.',
            'canonical' => config('app.url') . '/kayttoehdot',
        ]);
    }
}
