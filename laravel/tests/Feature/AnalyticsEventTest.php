<?php

namespace Tests\Feature;

use App\Models\ContractOrderClick;
use App\Services\Analytics\ContractOrderClickContextSigner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsEventTest extends TestCase
{
    use RefreshDatabase;

    private ContractOrderClickContextSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-05 12:00:00 UTC');
        $this->signer = app(ContractOrderClickContextSigner::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_valid_signed_contract_order_click_is_inserted(): void
    {
        $response = $this->postJson(route('analytics.events.store'), $this->validEnvelope([
            'placement' => 'sticky',
        ]));

        $response->assertNoContent();

        $click = ContractOrderClick::query()->sole();
        $this->assertSame('contract-123', $click->contract_id);
        $this->assertSame('Vakaa 12 kk', $click->contract_name);
        $this->assertSame('Test Energia Oy', $click->company_name);
        $this->assertSame('641.25', $click->annual_price_eur);
        $this->assertSame(7200, $click->consumption_kwh);
        $this->assertSame(7, $click->price_rank);
        $this->assertSame(315, $click->rank_total);
        $this->assertSame(8000, $click->rank_consumption_kwh);
        $this->assertTrue($click->is_estimate);
        $this->assertSame('canonical', $click->pricing_basis);
        $this->assertSame('sticky', $click->cta_location);
        $this->assertSame('google', $click->session_source);
        $this->assertSame('organic', $click->session_medium);
        $this->assertSame('summer', $click->session_campaign);
        $this->assertSame('/sahkosopimus', $click->landing_path);
        $this->assertSame('/sahkosopimus/sopimus/contract-123', $click->page_path);
        $this->assertSame('UTC', $click->occurred_at->timezoneName);
        $this->assertTrue($click->occurred_at->equalTo(CarbonImmutable::now('UTC')));
    }

    public function test_duplicate_event_uuid_is_idempotent(): void
    {
        $uuid = (string) Str::uuid();
        $envelope = $this->validEnvelope(['event_uuid' => $uuid]);

        $this->postJson(route('analytics.events.store'), $envelope)->assertNoContent();
        $this->postJson(route('analytics.events.store'), $envelope)->assertNoContent();

        $this->assertSame(1, ContractOrderClick::query()->where('event_uuid', $uuid)->count());
    }

    public function test_hero_and_sticky_are_the_only_valid_placements(): void
    {
        foreach (['hero', 'sticky'] as $placement) {
            $this->postJson(route('analytics.events.store'), $this->validEnvelope([
                'event_uuid' => (string) Str::uuid(),
                'placement' => $placement,
            ]))->assertNoContent();
        }

        $this->postJson(route('analytics.events.store'), $this->validEnvelope([
            'event_uuid' => (string) Str::uuid(),
            'placement' => 'footer',
        ]))->assertUnprocessable()->assertJsonValidationErrors('placement');

        $this->assertSame(['hero', 'sticky'], ContractOrderClick::query()
            ->orderBy('id')
            ->pluck('cta_location')
            ->all());
    }

    public function test_unavailable_price_and_rank_facts_stay_null(): void
    {
        $context = $this->signer->sign(
            contractId: 'contract-null',
            contractName: 'Hinta puuttuu',
            companyName: 'Test Energia Oy',
            annualPriceEur: null,
            consumptionKwh: 5000,
            priceRank: null,
            rankTotal: null,
            rankConsumptionKwh: null,
            isEstimate: false,
            pricingBasis: null,
        );

        $this->postJson(route('analytics.events.store'), $this->validEnvelope([
            'context' => $context,
        ]))->assertNoContent();

        $click = ContractOrderClick::query()->sole();
        $this->assertNull($click->annual_price_eur);
        $this->assertNull($click->price_rank);
        $this->assertNull($click->rank_total);
        $this->assertNull($click->rank_consumption_kwh);
        $this->assertNull($click->pricing_basis);
    }

    public function test_modified_malformed_and_expired_contexts_are_rejected(): void
    {
        $valid = $this->signedContext();
        $parts = explode('.', $valid);
        $parts[0][5] = $parts[0][5] === 'a' ? 'b' : 'a';

        foreach ([$parts[0].'.'.$parts[1], 'not-a-token'] as $context) {
            $this->postJson(route('analytics.events.store'), $this->validEnvelope([
                'event_uuid' => (string) Str::uuid(),
                'context' => $context,
            ]))->assertUnprocessable()->assertJsonValidationErrors('context');
        }

        $expired = $this->signer->sign(
            contractId: 'contract-123',
            contractName: 'Vakaa 12 kk',
            companyName: 'Test Energia Oy',
            annualPriceEur: 641.25,
            consumptionKwh: 7200,
            priceRank: 7,
            rankTotal: 315,
            rankConsumptionKwh: 8000,
            isEstimate: true,
            pricingBasis: 'canonical',
            issuedAt: CarbonImmutable::now('UTC')->subHours(97),
        );

        $this->postJson(route('analytics.events.store'), $this->validEnvelope([
            'event_uuid' => (string) Str::uuid(),
            'context' => $expired,
        ]))->assertUnprocessable()->assertJsonValidationErrors('context');

        $this->assertDatabaseCount('contract_order_clicks', 0);
    }

    public function test_context_version_and_exact_schema_are_verified(): void
    {
        $base = $this->rawContextPayload();

        $wrongVersion = $base;
        $wrongVersion['version'] = 2;
        $this->postJson(route('analytics.events.store'), $this->validEnvelope([
            'context' => $this->signRawContext($wrongVersion),
        ]))->assertUnprocessable()->assertJsonValidationErrors('context');

        $extraField = $base;
        $extraField['browser_price'] = 1;
        $this->postJson(route('analytics.events.store'), $this->validEnvelope([
            'event_uuid' => (string) Str::uuid(),
            'context' => $this->signRawContext($extraField),
        ]))->assertUnprocessable()->assertJsonValidationErrors('context');

        $this->assertDatabaseCount('contract_order_clicks', 0);
    }

    public function test_attribution_and_paths_are_normalized_and_limited(): void
    {
        $longSource = "  NEWS\nLETTER ".str_repeat('X', 200);
        $longCampaign = str_repeat(' Campaign ', 30);

        $this->postJson(route('analytics.events.store'), $this->validEnvelope([
            'attribution' => [
                'source' => $longSource,
                'medium' => "  Paid\tSocial  ",
                'campaign' => $longCampaign,
                'landing_path' => '/start/path?secret=yes#fragment',
            ],
            'page_path' => '/sahkosopimus/sopimus/contract-123?kulutus=7200#cta',
        ]))->assertNoContent();

        $click = ContractOrderClick::query()->sole();
        $this->assertSame(100, mb_strlen($click->session_source));
        $this->assertStringStartsWith('news letter ', $click->session_source);
        $this->assertSame('paid social', $click->session_medium);
        $this->assertSame(150, mb_strlen($click->session_campaign));
        $this->assertSame('/start/path', $click->landing_path);
        $this->assertSame('/sahkosopimus/sopimus/contract-123', $click->page_path);
    }

    public function test_unknown_event_and_malformed_envelope_are_rejected_before_persistence(): void
    {
        $this->postJson(route('analytics.events.store'), [
            'event_name' => 'page_view',
            'event_uuid' => (string) Str::uuid(),
            'context' => 'anything',
            'attribution' => [],
            'page_path' => '/',
            'placement' => 'hero',
        ])->assertUnprocessable()->assertJsonValidationErrors('event_name');

        $this->postJson(route('analytics.events.store'), [
            'event_name' => 'contract_order_click',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'event_uuid', 'context', 'attribution', 'page_path', 'placement',
        ]);

        $this->assertDatabaseCount('contract_order_clicks', 0);
    }

    public function test_endpoint_is_stateless_and_does_not_require_csrf(): void
    {
        $route = Route::getRoutes()->getByName('analytics.events.store');
        $middleware = $route->gatherMiddleware();

        $this->assertNotContains(StartSession::class, $middleware);
        $this->assertNotContains(VerifyCsrfToken::class, $middleware);
        $this->assertContains('throttle:analytics-events', $middleware);

        $this->postJson('/api/analytics/events', $this->validEnvelope())
            ->assertNoContent()
            ->assertCookieMissing(config('session.cookie'));
    }

    /** @param array<string, mixed> $overrides */
    private function validEnvelope(array $overrides = []): array
    {
        return array_replace([
            'event_name' => 'contract_order_click',
            'event_uuid' => (string) Str::uuid(),
            'context' => $this->signedContext(),
            'attribution' => [
                'source' => 'google',
                'medium' => 'organic',
                'campaign' => 'summer',
                'landing_path' => '/sahkosopimus',
            ],
            'page_path' => '/sahkosopimus/sopimus/contract-123',
            'placement' => 'hero',
        ], $overrides);
    }

    private function signedContext(): string
    {
        return $this->signer->sign(
            contractId: 'contract-123',
            contractName: 'Vakaa 12 kk',
            companyName: 'Test Energia Oy',
            annualPriceEur: 641.25,
            consumptionKwh: 7200,
            priceRank: 7,
            rankTotal: 315,
            rankConsumptionKwh: 8000,
            isEstimate: true,
            pricingBasis: 'canonical',
        );
    }

    /** @return array<string, mixed> */
    private function rawContextPayload(): array
    {
        $issuedAt = CarbonImmutable::now('UTC')->timestamp;

        return [
            'version' => ContractOrderClickContextSigner::VERSION,
            'contract_id' => 'contract-123',
            'contract_name' => 'Vakaa 12 kk',
            'company_name' => 'Test Energia Oy',
            'annual_price_eur' => 641.25,
            'consumption_kwh' => 7200,
            'price_rank' => 7,
            'rank_total' => 315,
            'rank_consumption_kwh' => 8000,
            'is_estimate' => true,
            'pricing_basis' => 'canonical',
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + ContractOrderClickContextSigner::LIFETIME_HOURS * 3600,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function signRawContext(array $payload): string
    {
        $applicationKey = (string) config('app.key');
        if (str_starts_with($applicationKey, 'base64:')) {
            $applicationKey = (string) base64_decode(substr($applicationKey, 7), true);
        }

        $signingKey = hash_hmac(
            'sha256',
            'voltikka-contract-order-click-context-v1',
            $applicationKey,
            true,
        );
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
        $signature = hash_hmac('sha256', $json, $signingKey, true);

        return $this->base64Url($json).'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
