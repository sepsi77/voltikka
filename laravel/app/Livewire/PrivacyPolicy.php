<?php

namespace App\Livewire;

use Livewire\Component;

/**
 * /tietosuoja — privacy & cookie statement.
 *
 * Voltikka collects no personal data in normal use: no accounts, no forms, no purchases.
 * Analytics is cookieless Plausible; only ordinary server/log data may arise. This page
 * documents that and is the reason the site shows no cookie-consent banner.
 *
 * Contact email reuses AboutPage::CONTACT_EMAIL via the click-to-reveal <x-obfuscated-email>
 * so the address is not harvested from the HTML source.
 */
class PrivacyPolicy extends Component
{
    /**
     * Content last-updated date (ISO). Single source of truth for both the on-page
     * "Päivitetty viimeksi" stamp and the sitemap <lastmod>. Bump only when the policy
     * text actually changes — not on every deploy.
     */
    public const LAST_UPDATED = '2026-05-30';

    public function render()
    {
        return view('livewire.privacy-policy', [
            'lastUpdated' => \Illuminate\Support\Carbon::parse(self::LAST_UPDATED)->format('j.n.Y'),
        ])->layout('layouts.app', [
            'title' => 'Tietosuoja ja evästeet | Voltikka',
            'metaDescription' => 'Miten Voltikka käsittelee kävijöiden tietoja ja käyttää evästeitä. Voltikka ei kerää henkilötietoja, käyttää evästeetöntä Plausible-analytiikkaa eikä näytä evästebanneria.',
            'canonical' => config('app.url') . '/tietosuoja',
        ]);
    }
}
