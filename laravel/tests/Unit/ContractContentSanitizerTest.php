<?php

namespace Tests\Unit;

use App\Support\ContractContentSanitizer;
use PHPUnit\Framework\TestCase;

class ContractContentSanitizerTest extends TestCase
{
    public function test_localized_duplicate_billing_values_collapse_to_one(): void
    {
        // The real shape of electricity_contracts.billing_frequency.
        $values = ['EN' => '1 kk', 'FI' => '1 kk', 'SV' => '1 kk', 'Default' => null];

        $this->assertSame(['1 kk'], ContractContentSanitizer::uniqueLabels($values));
    }

    public function test_duplicate_detection_ignores_case_and_padding(): void
    {
        $values = ['1 KK', ' 1 kk ', '1 kk'];

        $this->assertSame(['1 KK'], ContractContentSanitizer::uniqueLabels($values));
    }

    public function test_genuinely_different_values_are_preserved_in_order(): void
    {
        $values = ['6 tai 12 laskua vuodessa.', '1 kk', '6 tai 12 laskua vuodessa.'];

        $this->assertSame(
            ['6 tai 12 laskua vuodessa.', '1 kk'],
            ContractContentSanitizer::uniqueLabels($values)
        );
    }

    public function test_non_array_and_empty_values_yield_an_empty_list(): void
    {
        $this->assertSame([], ContractContentSanitizer::uniqueLabels(null));
        $this->assertSame([], ContractContentSanitizer::uniqueLabels('1 kk'));
        $this->assertSame([], ContractContentSanitizer::uniqueLabels(['', '   ', null]));
    }

    /**
     * The terms grid must not print a bare "12" (273 contracts store the interval
     * that way) or an explicit "Ei ilmoitettu" (112 contracts).
     */
    public function test_billing_frequency_labels_expand_a_bare_number(): void
    {
        $values = ['EN' => '12', 'FI' => '12', 'SV' => '12', 'Default' => null];

        $this->assertSame(['12 laskua vuodessa'], ContractContentSanitizer::billingFrequencyLabels($values));
    }

    public function test_billing_frequency_labels_drop_explicit_no_data_markers(): void
    {
        $this->assertSame([], ContractContentSanitizer::billingFrequencyLabels(['FI' => 'Ei ilmoitettu']));
        $this->assertSame([], ContractContentSanitizer::billingFrequencyLabels(['FI' => '-']));
    }

    public function test_billing_frequency_labels_keep_a_real_interval_untouched(): void
    {
        $values = ['EN' => 'Kuukausittain (1/kk)', 'FI' => 'Kuukausittain (1/kk)'];

        $this->assertSame(['Kuukausittain (1/kk)'], ContractContentSanitizer::billingFrequencyLabels($values));
    }

    public function test_shouted_words_are_normalized(): void
    {
        $cases = [
            // A run of shouted words reads as one Finnish sentence, not English title case.
            'Hehku KIINTEÄ 12 kk - 0€ KUUKAUSIMAKSU ENSIMMÄISET 3 KK!' => 'Hehku Kiinteä 12 kk - 0€ Kuukausimaksu ensimmäiset 3 KK!',
            'ILMASTOVIISAS 12 kk CO2-päästöttömästi tuotettu' => 'Ilmastoviisas 12 kk CO2-päästöttömästi tuotettu',
            // Three letters or fewer, a digit, or an allow-listed acronym: left alone.
            'Kausisähkö 12 kk ALV 0 %' => 'Kausisähkö 12 kk ALV 0 %',
            'Sähkö 24H' => 'Sähkö 24H',
            'Pörssisähkö NORDPOOL' => 'Pörssisähkö NORDPOOL',
            'Perus Sähkö 24kk' => 'Perus Sähkö 24kk',
            '' => '',
        ];

        foreach ($cases as $name => $expected) {
            $this->assertSame($expected, ContractContentSanitizer::displayName((string) $name));
        }
    }

    public function test_wrapping_quotes_are_stripped(): void
    {
        $this->assertSame(
            'Kiinteä hinta koko kaudelle.',
            ContractContentSanitizer::descriptionHtml('"Kiinteä hinta koko kaudelle."')
        );

        $this->assertSame(
            'Kiinteä hinta koko kaudelle.',
            ContractContentSanitizer::descriptionHtml('”Kiinteä hinta koko kaudelle.”')
        );
    }

    public function test_a_quoted_phrase_inside_the_text_is_not_unwrapped(): void
    {
        $html = '"Vihreä" sähkö on aina "vihreää"';

        $this->assertSame($html, ContractContentSanitizer::descriptionHtml($html));
    }

    public function test_a_bare_link_callout_is_removed_without_merging_sentences(): void
    {
        $this->assertSame(
            'Tilaa Hehku Kiinteä 24 kk. Sopimus on määräaikainen.',
            ContractContentSanitizer::descriptionHtml('Tilaa Hehku Kiinteä 24 kk TÄÄLTÄ. Sopimus on määräaikainen.')
        );
    }

    public function test_a_callout_with_arrow_decoration_is_removed(): void
    {
        $this->assertSame(
            'Voit tilata sopimuksen heti.',
            ContractContentSanitizer::descriptionHtml('Voit tilata sopimuksen TÄSTÄ >> heti.')
        );
    }

    public function test_a_callout_inside_a_real_link_is_kept(): void
    {
        $html = 'Tilaa Hehku Kiinteä 24 kk <a href="https://hehkuenergia.fi/tilauslomake/">TÄÄLTÄ</a>';

        $this->assertSame($html, ContractContentSanitizer::descriptionHtml($html));
    }

    public function test_an_anchor_without_an_href_is_unwrapped_and_its_callout_removed(): void
    {
        $this->assertSame(
            '<p>Teet sopimuksen klikkaamalla.</p><p>Vihreä sähkö.</p>',
            ContractContentSanitizer::descriptionHtml('<p>Teet sopimuksen klikkaamalla <b><a>TÄSTÄ.</b></a></p><p>Vihreä sähkö.</p>')
        );
    }

    public function test_a_description_that_is_only_a_callout_becomes_null(): void
    {
        $this->assertNull(ContractContentSanitizer::descriptionHtml('<p><b>KLIKKAA TÄSTÄ</b></p>'));
        $this->assertNull(ContractContentSanitizer::descriptionHtml('   '));
        $this->assertNull(ContractContentSanitizer::descriptionHtml(null));
    }

    /**
     * The description is printed unescaped, so executable markup must not survive.
     */
    public function test_script_and_event_handlers_are_stripped(): void
    {
        $sanitized = ContractContentSanitizer::descriptionHtml(
            '<p>Kuvaus.</p><script>alert(1)</script><img src="x" onerror="alert(1)">'
        );

        $this->assertStringNotContainsString('<script', (string) $sanitized);
        $this->assertStringNotContainsString('onerror', (string) $sanitized);
        $this->assertStringContainsString('Kuvaus.', (string) $sanitized);
    }

    public function test_plain_text_description_is_cleaned_too(): void
    {
        $this->assertSame(
            'Tämä on kuvaus. Lue lisää.',
            ContractContentSanitizer::descriptionText('”Tämä on kuvaus. Lue lisää TÄSTÄ.”')
        );

        $this->assertNull(ContractContentSanitizer::descriptionText(null));
    }
}
