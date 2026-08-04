<?php

namespace App\Livewire;

use Livewire\Component;

/**
 * /tietosuoja — privacy & cookie statement.
 *
 * The public site has no user registration, contact form, or purchase processing.
 * Analytics uses cookieless Plausible and first-party seller-link events without visitor
 * or session IDs. Ordinary server and error logs can also exist.
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
    public const LAST_UPDATED = '2026-08-05';

    public function render()
    {
        return view('livewire.privacy-policy', [
            'lastUpdated' => \Illuminate\Support\Carbon::parse(self::LAST_UPDATED)->format('j.n.Y'),
        ])->layout('layouts.app', [
            'title' => 'Tietosuoja ja evästeet | Voltikka',
            'metaDescription' => 'Miten Voltikka käsittelee kävijätietoja. Käytämme evästeetöntä Plausible-analytiikkaa ja mittaamme sähköyhtiön tilaussivulle siirtymisiä ilman kävijätunnistetta.',
            'canonical' => config('app.url') . '/tietosuoja',
        ]);
    }
}
