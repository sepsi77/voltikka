<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PageActionStripSourcePolicyTest extends TestCase
{
    public function test_each_real_public_page_template_contains_one_action_strip(): void
    {
        $templates = [
            'home-page',
            'about-page',
            'privacy-policy',
            'terms-of-service',
            'spot-price',
            'consumption-calculator',
            'bill-comparison',
            'solar-calculator',
            'heat-pump-calculator',
            'contract-detail',
            'locations-list',
            'seo-contracts-list',
            'contract-price-statistics',
            'fixed-contract-price-forecast',
            'cheapest-contracts',
            'company-list',
            'company-detail',
            'article-spot-electricity',
            'article-fixed-term-contract',
        ];

        foreach ($templates as $template) {
            $contents = file_get_contents($this->viewPath($template));

            $this->assertSame(
                1,
                substr_count($contents, '<x-page-action-strip'),
                "The {$template} template must contain exactly one page action strip.",
            );
        }
    }

    public function test_internal_contract_comparison_widget_has_no_action_strip(): void
    {
        $contents = file_get_contents($this->viewPath('contract-type-comparison'));

        $this->assertSame(0, substr_count($contents, '<x-page-action-strip'));
    }

    private function viewPath(string $template): string
    {
        return dirname(__DIR__, 2)."/resources/views/livewire/{$template}.blade.php";
    }
}
