<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiService
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const MODEL = 'gpt-4';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 5000;

    /**
     * Generate a contract description using OpenAI API.
     *
     * @param string $prompt The prompt to send to the API
     * @return string The generated description
     * @throws RequestException
     */
    public function generateDescription(string $prompt): string
    {
        $apiKey = config('services.openai.api_key');

        $response = Http::retry(self::MAX_RETRIES, self::RETRY_DELAY_MS, function ($exception, $request) {
            return $exception instanceof RequestException
                && ($exception->response?->serverError() || $exception->response === null);
        })
            ->withToken($apiKey)
            ->post(self::API_URL, [
                'model' => self::MODEL,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Failed to generate description from OpenAI', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RequestException($response);
        }

        return $response->json('choices.0.message.content') ?? '';
    }

    /**
     * Build a prompt for generating a contract description.
     *
     * @param array $contractData Contract data including:
     *   - company_name: string
     *   - contract_name: string
     *   - contract_type: string (Fixed, OpenEnded, Spot)
     *   - metering: string (General, Time, Seasonal)
     *   - price_components: array of ['type' => string, 'price' => float, 'unit' => string]
     *   - electricity_source: array of source percentages
     *   - consumption_limit: int|null
     * @return string The formatted prompt
     */
    public function buildContractPrompt(array $contractData): string
    {
        $companyName = $contractData['company_name'];
        $contractName = $contractData['contract_name'];
        $contractType = $contractData['contract_type'];
        $metering = $contractData['metering'];
        $priceComponents = $contractData['price_components'];
        $electricitySource = $contractData['electricity_source'] ?? [];
        $consumptionLimit = $contractData['consumption_limit'] ?? null;

        // Build electricity source string
        $sourceString = $this->buildElectricitySourceString($electricitySource);

        // Build pricing string
        $pricingString = $this->buildPricingString($contractType, $priceComponents);

        // Build consumption limit string
        $limitString = '';
        if ($consumptionLimit && $consumptionLimit < 50000) {
            $limitString = "Consumption limitation: {$consumptionLimit} kWh per year";
        }

        return <<<PROMPT
Please act as a copywriter. Your task is to generate brief descriptions for electricity contracts based on structured data given below.

Please keep the description neutral and factual, even if source material contains marketing language. Exclude all links and HTML elements.

Your response should only contain the contract description and nothing else. Please write the description in Finnish. Limit the description to 2 sentences.

Your description should contain details like:

- Contract and company name
- Pricing, note that in Spot priced contracts only marginaali (margin) per kWh is mentioned. The price consumer pays includes the current spot price in Nord Pool Spot market, margin and VAT.
- Electricity sources (if specified)
- Consumption limit (if specified)

SOURCE MATERIAL

Company: {$companyName}
Contract name: {$contractName}
Contract type: {$contractType}
Metering: {$metering}
Pricing: {$pricingString}
{$sourceString}
{$limitString}

TERMS to use in descriptions
Contract types:
OpenEnded = Toistaiseksi voimassaoleva sähkösopimus
Fixed = Määräaikainen sähkösopimus)
Spot = Pörssisähkösopimus)

Metering:
Time = Aikasähkö
Seasonal = Kausisähkö
General = Yleissähkö

Pricing:
Contract where CentPerKiwattHour = 0 are called kiinteähintainen (fixed price with no variable per kWh charges)
Monthly = Perusmaksu

EXAMPLES
KSS Energia Oy:n KSS Valinta 24kk -sopimus on määräaikainen yleissähkösopimus, jossa kuukausimaksu on 4,39 €/kk ja sähkön hinta 10,32 snt/kWh. Sähkö tuotetaan 100 % tuulienergiasta.
Korpelan Energia Oy:n Korpela Virta 2v -sopimus on määräaikainen yleissähkösopimus, jossa sähkön hinta on 13,02 snt/kWh ja kuukausihinta 3,9 €/kk.
KSS Energia Oy:n KSS Onni 12 kk yleissähkö on määräaikainen sähkösopimus, jossa sähkön hinta on 14,02 snt/kWh ja kuukausimaksu 4,39 €/kk. Sähkö tuotetaan 100% tuulivoimalla.
Vaasan Sähkö Oy:n Tuulisähkö-sopimus on toistaiseksi voimassa oleva kausisähkösopimus, jossa sähkön hinta on 17,35 snt/kWh talvipäivisin ja 14,29 snt/kWh muina aikoina, sekä kuukausimaksu 3,5 €/kk. Sähkö tuotetaan 100-prosenttisesti tuulivoimalla.
KSS Energia Oy:n KSS Vapaus -sopimus on toistaiseksi voimassaoleva yleissähkösopimus, jossa sähkön hinta on 17,0 senttiä/kWh ja kuukausimaksu 4,39 euroa.
Vaasan Sähkö Oy:n Pörssisähkö Tuuli on pörssisähkösopimus, jonka hinta muodostuu Nord Pool Spot -markkinoiden sähkön spot-hinnasta, 0,86 sentin kWh-marginaalista ja arvonlisäverosta. Sähkön lähde on 100 % uusiutuvaa tuulienergiaa.
Nurmijärven Sähkö Oy:n Pörssisähkö-sopimus on kausisähköä, jossa hinta koostuu Nord Pool Spot -markkinoiden sähkön spot-hinnasta, 0,49 sentin kWh marginaalista ja arvonlisäverosta. Sähkön lähteet ovat 44 % fossiiliset polttoaineet, 45,9 % ydinvoima ja 10,1 % muut.
Turku Energia Oy:n Louna Vehreä -sopimus on toistaiseksi voimassaoleva aikasähkösopimus, jossa yön aikainen sähkön hinta on 16,6 snt/kWh ja päivän aikainen 20,3 snt/kWh, sekä kuukausihinta 4,78 €/kk. Sähkö tuotetaan 100 % uusiutuvista lähteistä.
KSS Energia Oy:n KSS IISI M -sopimus on toistaiseksi voimassaoleva, kiinteähintainen sähkösopimus hintaan 69,99 €/kk. Sopimuksessa on vuosittainen kulutusraja 5000 kWh.

Description:
PROMPT;
    }

    /**
     * Build electricity source string from source data.
     */
    private function buildElectricitySourceString(array $electricitySource): string
    {
        if (empty($electricitySource)) {
            return '';
        }

        // Filter out zero values and id fields
        $sources = [];
        $totalSpecified = 0;

        foreach ($electricitySource as $key => $value) {
            if (str_contains($key, 'id') || str_contains($key, '_id')) {
                continue;
            }

            if ($value > 0) {
                $sources[$key] = $value;
                $totalSpecified += $value;
            }
        }

        if (empty($sources)) {
            return '';
        }

        if ($totalSpecified < 100) {
            $sources['unspecified'] = 100 - $totalSpecified;
        }

        return 'Electricity source: ' . json_encode($sources);
    }

    /**
     * Build pricing string from price components.
     */
    private function buildPricingString(string $contractType, array $priceComponents): string
    {
        $pricing = [];

        if ($contractType === 'Spot') {
            foreach ($priceComponents as $component) {
                $type = $component['type'];
                $price = $component['price'];
                $unit = $component['unit'];

                if ($type !== 'Monthly') {
                    $pricing['Spot price margin'] = "{$price} {$unit}";
                } else {
                    $pricing[$type] = "{$price} {$unit}";
                }
            }
        } else {
            foreach ($priceComponents as $component) {
                $type = $component['type'];
                $price = $component['price'];
                $unit = $component['unit'];
                $pricing[$type] = "{$price} {$unit}";
            }
        }

        return json_encode($pricing);
    }
}
