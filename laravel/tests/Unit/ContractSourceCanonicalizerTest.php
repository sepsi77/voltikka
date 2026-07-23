<?php

namespace Tests\Unit;

use App\Services\ContractInterpretation\ContractSourceCanonicalizer;
use PHPUnit\Framework\TestCase;

class ContractSourceCanonicalizerTest extends TestCase
{
    public function test_fingerprint_ignores_object_key_order_and_harmless_whitespace(): void
    {
        $canonicalizer = new ContractSourceCanonicalizer;

        $first = [
            'Name' => "  Test   contract\n",
            'Details' => [
                'PricingModel' => 'FixedPrice',
                'PriceComponents' => [
                    ['Type' => 'Monthly', 'Price' => 4.9],
                    ['Type' => 'General', 'Price' => 8.5],
                ],
            ],
        ];
        $second = [
            'Details' => [
                'PriceComponents' => [
                    ['Price' => 4.9, 'Type' => 'Monthly'],
                    ['Price' => 8.5, 'Type' => 'General'],
                ],
                'PricingModel' => 'FixedPrice',
            ],
            'Name' => 'Test contract',
        ];

        $this->assertSame(
            $canonicalizer->fingerprint($first),
            $canonicalizer->fingerprint($second)
        );
    }

    public function test_fingerprint_ignores_shared_spot_futures_market_data(): void
    {
        $canonicalizer = new ContractSourceCanonicalizer;
        $first = ['Id' => 'contract-1', 'Details' => ['SpotFutures' => 4.25]];
        $second = ['Id' => 'contract-1', 'Details' => ['SpotFutures' => 5.50]];

        $this->assertSame(
            $canonicalizer->fingerprint($first),
            $canonicalizer->fingerprint($second)
        );
    }

    public function test_fingerprint_preserves_list_order(): void
    {
        $canonicalizer = new ContractSourceCanonicalizer;
        $first = ['Values' => ['first', 'second']];
        $second = ['Values' => ['second', 'first']];

        $this->assertNotSame(
            $canonicalizer->fingerprint($first),
            $canonicalizer->fingerprint($second)
        );
    }

    public function test_fingerprint_changes_when_source_meaning_changes(): void
    {
        $canonicalizer = new ContractSourceCanonicalizer;
        $payload = [
            'Id' => 'contract-1',
            'Details' => [
                'Pricing' => [
                    'PriceComponents' => [
                        ['PriceComponentType' => 'General', 'OriginalPayment' => ['Price' => 8.5]],
                    ],
                ],
            ],
        ];
        $changedPayload = $payload;
        $changedPayload['Details']['Pricing']['PriceComponents'][0]['OriginalPayment']['Price'] = 9.5;

        $this->assertNotSame(
            $canonicalizer->fingerprint($payload),
            $canonicalizer->fingerprint($changedPayload)
        );
    }
}
