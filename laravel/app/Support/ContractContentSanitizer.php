<?php

namespace App\Support;

/**
 * Cleans up upstream contract text before it reaches a public page.
 *
 * Everything here fixes a defect that comes from the source payload, not from Voltikka:
 *
 * - `billing_frequency` arrives as one localized map per contract (`EN`/`FI`/`SV`/`Default`)
 *   that carries the same Finnish string three times, so a naive `implode()` printed
 *   "1 kk, 1 kk, 1 kk, ".
 * - Contract names are sometimes shouted ("Hehku KIINTEÄ 12 kk - 0€ KUUKAUSIMAKSU
 *   ENSIMMÄISET 3 KK!"). Shouting in an `<h1>` and in a `<title>` reads as spam.
 * - Seller descriptions are raw marketing HTML. They can be wrapped in quotes and they
 *   carry call-to-action words ("TÄÄLTÄ", "TÄSTÄ") that look like links. When the word
 *   really is a link the visitor can use it, but a bare one is a dead end.
 *
 * Keep every rule here conservative and lossless where possible. This is display
 * hygiene, not editing: it must never change a price, a date, or a product name's
 * meaning. Later phases of the contract-detail overhaul reuse these helpers, so add new
 * cleanup rules here instead of in a Blade template.
 */
class ContractContentSanitizer
{
    /**
     * Uppercase tokens that must keep their casing. Tokens with a digit (CO2, 24H) and
     * tokens of three letters or fewer (ALV, EU) are already left alone by the length
     * and digit guards, so only longer pure-letter acronyms need an entry.
     */
    private const KEEP_UPPERCASE = [
        'ENTSO-E',
        'PVGIS',
        'NORDPOOL',
    ];

    /**
     * Uppercase call-to-action words used as a fake link. Longest first, because the
     * alternation is matched in order.
     */
    private const LINK_CALLOUTS = [
        'KLIKKAA TÄSTÄ',
        'KLIKKAA TÄÄLTÄ',
        'LUE LISÄÄ TÄSTÄ',
        'KATSO TÄSTÄ',
        'TILAA TÄSTÄ',
        'LUE LISÄÄ',
        'TÄÄLTÄ',
        'TÄÄLLÄ',
        'TÄSTÄ',
        'TÄNNE',
    ];

    /**
     * Collapse a localized/duplicated value list to the values a reader should see.
     *
     * Comparison is case-insensitive on the trimmed value, so "1 kk" and "1 KK" count
     * as one value, but two genuinely different intervals both survive.
     *
     * @return list<string>
     */
    public static function uniqueLabels(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $labels = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $label = trim($value);
            if ($label === '') {
                continue;
            }

            $key = mb_strtolower($label, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $labels[] = $label;
        }

        return $labels;
    }

    /**
     * The billing intervals a terms summary should print.
     *
     * On top of the localized-duplicate collapse this drops explicit no-data markers
     * and expands a bare number. 273 contracts store the interval as "12" with no
     * unit, which renders as "Laskutusväli 12" and says nothing; the surrounding
     * vocabulary in the same column ("12 laskua vuodessa", "12 krt/v") makes the
     * meaning unambiguous. 112 contracts store "Ei ilmoitettu", which is an absence
     * of data and must not become a row.
     *
     * @return list<string>
     */
    public static function billingFrequencyLabels(mixed $values): array
    {
        $labels = [];

        foreach (self::uniqueLabels($values) as $label) {
            if (in_array(mb_strtolower($label, 'UTF-8'), ['ei ilmoitettu', 'ei tiedossa', '-'], true)) {
                continue;
            }

            if (preg_match('/^\d{1,3}$/', $label) === 1) {
                $label .= ' laskua vuodessa';
            }

            $labels[] = $label;
        }

        return $labels;
    }

    /**
     * Normalize a shouted contract name for the H1 and the title tag.
     *
     * Only a word that is fully uppercase, has more than three letters, carries no
     * digit, and is not an allow-listed acronym is touched. Consecutive shouted words
     * are treated as one run: the run starts with a capital and continues lowercase, so
     * "0€ KUUKAUSIMAKSU ENSIMMÄISET 4 KK!" becomes "0€ Kuukausimaksu ensimmäiset 4 KK!"
     * instead of English-style title case.
     */
    public static function displayName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        $tokens = preg_split('/(\s+)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $inShoutedRun = false;

        foreach ($tokens as $index => $token) {
            if (trim($token) === '') {
                continue;
            }

            if (! self::isShouted($token)) {
                // A word that already carries lowercase letters ends the run. A bare
                // number or a separator ("24", "-") is neutral and keeps it open.
                if (preg_match('/\p{Ll}/u', $token)) {
                    $inShoutedRun = false;
                }

                continue;
            }

            $lowered = mb_strtolower($token, 'UTF-8');
            $tokens[$index] = $inShoutedRun ? $lowered : self::ucfirst($lowered);
            $inShoutedRun = true;
        }

        return implode('', $tokens);
    }

    /**
     * Clean a seller description that is rendered as HTML.
     *
     * Returns null when nothing readable is left, so the caller can drop the whole
     * section instead of printing an empty heading.
     */
    public static function descriptionHtml(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }

        $html = self::stripUnsafeMarkup($html);
        $html = self::stripWrappingQuotes($html);
        $html = self::unwrapHreflessAnchors($html);
        $html = self::removeBareLinkCallouts($html);
        $html = self::tidy($html);

        return trim(strip_tags($html)) === '' ? null : $html;
    }

    /**
     * Clean a seller description that is rendered as escaped plain text.
     */
    public static function descriptionText(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        $text = self::stripWrappingQuotes($text);
        $text = self::removeCallouts($text);
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+([.,!?:;])/u', '$1', $text) ?? $text;
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    private static function isShouted(string $token): bool
    {
        if (preg_match('/\p{Nd}/u', $token)) {
            return false;
        }

        if (in_array(mb_strtoupper($token, 'UTF-8'), self::KEEP_UPPERCASE, true)) {
            return false;
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $token) ?? '';
        if (mb_strlen($letters, 'UTF-8') <= 3) {
            return false;
        }

        // Scripts without letter case (for example digits-only leftovers) must not be
        // "normalized" into something different.
        if (mb_strtolower($letters, 'UTF-8') === $letters) {
            return false;
        }

        return mb_strtoupper($token, 'UTF-8') === $token;
    }

    private static function ucfirst(string $value): string
    {
        $first = mb_substr($value, 0, 1, 'UTF-8');

        return mb_strtoupper($first, 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
    }

    /**
     * Seller descriptions are printed unescaped, so drop the markup that must never run
     * on a Voltikka page. This is deliberately a small blocklist and not a full HTML
     * purifier: the payloads are plain marketing markup and a purifier would be a new
     * dependency.
     */
    private static function stripUnsafeMarkup(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*/?>#i', '', $html) ?? $html;
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        return preg_replace('/href\s*=\s*("|\')\s*javascript:[^"\']*\1/i', 'href="#"', $html) ?? $html;
    }

    private static function stripWrappingQuotes(string $value): string
    {
        $value = trim($value);
        // Finnish uses ”…” and »…», so an opening mark can equal its closing mark.
        $pairs = [
            ['"', '"'],
            ["'", "'"],
            ['“', '”'],
            ['”', '”'],
            ['»', '»'],
            ['»', '«'],
        ];

        foreach ($pairs as [$open, $close]) {
            if (mb_strlen($value, 'UTF-8') > 1 && str_starts_with($value, $open) && str_ends_with($value, $close)) {
                $inner = mb_substr($value, mb_strlen($open, 'UTF-8'), null, 'UTF-8');
                $inner = mb_substr($inner, 0, -mb_strlen($close, 'UTF-8'), 'UTF-8');

                // Only unwrap a quote that really wraps the whole text, not a quoted
                // phrase at the start followed by another quoted phrase at the end.
                if (! str_contains($inner, $close)) {
                    return trim($inner);
                }
            }
        }

        return $value;
    }

    /**
     * An `<a>` with no href is not a link. Keep its text and drop the element, so the
     * callout pass below can treat the text like any other prose.
     */
    private static function unwrapHreflessAnchors(string $html): string
    {
        return preg_replace_callback(
            '#<a\b([^>]*)>(.*?)</a>#is',
            function (array $match): string {
                $hasHref = (bool) preg_match('/\bhref\s*=\s*("[^"]*[^"\s][^"]*"|\'[^\']*[^\'\s][^\']*\'|[^\s>]+)/i', $match[1]);

                return $hasHref ? $match[0] : $match[2];
            },
            $html
        ) ?? $html;
    }

    /**
     * Remove a callout word only where it is not inside a real link. A live
     * `<a href="...">TÄÄLTÄ</a>` still helps the visitor and stays untouched.
     */
    private static function removeBareLinkCallouts(string $html): string
    {
        $chunks = preg_split('#(<a\b[^>]*>.*?</a>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        foreach ($chunks as $index => $chunk) {
            if (str_starts_with(strtolower(ltrim($chunk)), '<a')) {
                continue;
            }

            // Work on text nodes only so a tag or an attribute can never be damaged.
            $parts = preg_split('/(<[^>]*>)/u', $chunk, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            foreach ($parts as $partIndex => $part) {
                if (str_starts_with($part, '<')) {
                    continue;
                }

                $parts[$partIndex] = self::removeCallouts($part);
            }

            $chunks[$index] = implode('', $parts);
        }

        return implode('', $chunks);
    }

    private static function removeCallouts(string $text): string
    {
        $alternation = implode('|', array_map(
            static fn (string $callout): string => preg_quote($callout, '/'),
            self::LINK_CALLOUTS
        ));

        // The arrow decorations that follow such a word ("TÄSTÄ>>", "TÄSTÄ »") go with it,
        // but sentence punctuation stays, so removing the word cannot merge two sentences.
        $pattern = '/(?<!\p{L})(?:' . $alternation . ')(?!\p{L})[\s»]*(?:&gt;|>)*[\s»]*/u';

        return preg_replace($pattern, '', $text) ?? $text;
    }

    private static function tidy(string $html): string
    {
        // Tags first: removing a callout can leave "<b>.</b>", so unwrap an inline tag
        // that now holds only punctuation before the whitespace pass tightens it back
        // onto the preceding word.
        $html = preg_replace('#<(b|strong|em|i|u|span)>([\s.,!?:;]*)</\1>#i', '$2', $html) ?? $html;
        $html = preg_replace('#<p>(?:\s|<br\s*/?>)*</p>#i', '', $html) ?? $html;

        $html = preg_replace('/[ \t]{2,}/u', ' ', $html) ?? $html;
        $html = preg_replace('/[ \t]+([.,!?:;])/u', '$1', $html) ?? $html;
        $html = preg_replace('#[ \t]+(</)#', '$1', $html) ?? $html;
        $html = preg_replace('/[ \t]+$/m', '', $html) ?? $html;

        return trim($html);
    }
}
