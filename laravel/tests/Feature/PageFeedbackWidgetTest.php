<?php

namespace Tests\Feature;

use App\Filament\Resources\PageFeedback\PageFeedbackResource;
use App\Models\PageFeedback;
use App\Models\User;
use App\Services\Analytics\PageFeedbackCatalog;
use App\Services\Analytics\PageFeedbackContextSigner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Tests\TestCase;

class PageFeedbackWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_widget_renders_hidden_with_its_reasons_goals_and_a_signed_context(): void
    {
        $html = Blade::render('<x-page-feedback page-type="listing" :ledger="true" />');

        $this->assertStringContainsString('data-page-feedback', $html);
        $this->assertMatchesRegularExpression('/<section[^>]*\bhidden\b/', $html);
        $this->assertStringContainsString('wire:ignore', $html);
        $this->assertStringContainsString('vk-ledger-sep', $html);
        $this->assertSame(2, substr_count($html, 'data-feedback-vote='));

        foreach ([...PageFeedbackCatalog::reasonsFor('listing'), ...PageFeedbackCatalog::goalsFor('listing')] as $label) {
            $this->assertStringContainsString(e($label), $html);
        }

        // No option is checked at the start, and "Jokin muu" stays last in each list.
        $this->assertStringNotContainsString('checked', $html);
        $this->assertSame(2, substr_count($html, 'data-feedback-fixed-last'));

        preg_match('/data-feedback-context="([^"]+)"/', $html, $context);
        $this->assertSame(
            ['page_type' => 'listing', 'subject_id' => null],
            app(PageFeedbackContextSigner::class)->verify(html_entity_decode($context[1])),
        );
    }

    public function test_solar_calculator_widget_offers_no_contract_choices(): void
    {
        $html = Blade::render('<x-page-feedback page-type="solar_calculator" />');

        $this->assertStringContainsString('Osoitettani ei löytynyt', $html);
        $this->assertStringNotContainsString('sopimus', Str::lower(strip_tags($html)));
    }

    public function test_not_found_widget_opens_at_the_goal_step_without_a_vote(): void
    {
        $html = Blade::render('<x-page-feedback page-type="not_found" />');

        $this->assertStringContainsString('data-feedback-start="goal"', $html);
        $this->assertStringNotContainsString('data-feedback-vote=', $html);
        $this->assertStringContainsString('Mitä olit etsimässä?', $html);
        $this->assertMatchesRegularExpression('/data-feedback-step="goal"\s*>/', $html);
    }

    public function test_every_page_type_offers_five_goals_and_ends_lists_with_other(): void
    {
        foreach (PageFeedbackCatalog::pageTypes() as $pageType) {
            $goals = PageFeedbackCatalog::goalsFor($pageType);
            $this->assertCount(6, $goals, $pageType);
            $this->assertSame(PageFeedbackCatalog::OTHER, array_key_last($goals), $pageType);

            $reasons = PageFeedbackCatalog::reasonsFor($pageType);
            $this->assertSame(PageFeedbackCatalog::hasVote($pageType), $reasons !== [], $pageType);

            if ($reasons !== []) {
                $this->assertSame(PageFeedbackCatalog::OTHER, array_key_last($reasons), $pageType);
            }
        }
    }

    /** Every public page asks the question once; the legal pages do not ask. */
    public function test_public_page_templates_render_the_widget(): void
    {
        $views = resource_path('views');
        $expected = [
            'livewire/home-page' => 'home',
            'livewire/seo-contracts-list' => 'listing',
            'livewire/cheapest-contracts' => 'listing',
            'livewire/contract-detail' => 'contract_detail',
            'livewire/company-detail' => 'company_detail',
            'livewire/company-list' => 'company_list',
            'livewire/spot-price' => 'spot_price',
            'livewire/contract-price-statistics' => 'statistics',
            'livewire/fixed-contract-price-forecast' => 'forecast',
            'livewire/consumption-calculator' => 'consumption_calculator',
            'livewire/bill-comparison' => 'bill_comparison',
            'livewire/solar-calculator' => 'solar_calculator',
            'livewire/heat-pump-calculator' => 'heat_pump_calculator',
            'livewire/article-spot-electricity' => 'article',
            'livewire/article-fixed-term-contract' => 'article',
            'livewire/about-page' => 'about',
            'livewire/locations-list' => 'locations',
            'components/site/longread-404' => 'not_found',
        ];

        foreach ($expected as $template => $pageType) {
            $contents = file_get_contents("{$views}/{$template}.blade.php");
            preg_match_all('/<x-page-feedback page-type="([a-z_]+)"/', $contents, $matches);

            $this->assertNotEmpty($matches[1], $template);
            $this->assertSame([$pageType], array_values(array_unique($matches[1])), $template);
        }

        foreach (['livewire/privacy-policy', 'livewire/terms-of-service'] as $template) {
            $this->assertStringNotContainsString('<x-page-feedback', file_get_contents("{$views}/{$template}.blade.php"));
        }
    }

    public function test_admin_resource_is_read_only_and_exports_all_rows(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        PageFeedback::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'occurred_at' => now(),
            'page_type' => 'listing',
            'page_path' => '/sahkosopimus',
            'vote' => 'down',
            'reason_code' => 'missing_filter',
            'reason_position' => 2,
            'reason_text' => 'Halusin vihreää sähköä',
            'device' => 'desktop',
            'session_source' => 'direct',
            'session_medium' => '(none)',
            'landing_path' => '/sahkosopimus',
        ]);

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('filament.admin.page-feedback.export'))
            ->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->get(PageFeedbackResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Tarvitsemani rajaus puuttui')
            ->assertSee('Halusin vihreää sähköä');

        $this->assertSame(['index'], array_keys(PageFeedbackResource::getPages()));
        $this->assertFalse(PageFeedbackResource::canCreate());
        $this->assertFalse(PageFeedbackResource::canDeleteAny());

        $csv = $this->actingAs($admin)->get(route('filament.admin.page-feedback.export'))->streamedContent();
        $this->assertStringContainsString('reason_code', $csv);
        $this->assertStringContainsString('Halusin vihreää sähköä', $csv);
    }
}
