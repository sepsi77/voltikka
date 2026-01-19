<?php

namespace App\Console\Commands;

use App\Models\ActiveContract;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\OpenAiService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateDescriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'descriptions:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate short descriptions for electricity contracts using OpenAI API';

    private OpenAiService $openAiService;

    public function __construct(OpenAiService $openAiService)
    {
        parent::__construct();
        $this->openAiService = $openAiService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Generating contract descriptions using OpenAI...');

        // Get the latest price date
        $latestPriceDate = PriceComponent::max('price_date');

        if (!$latestPriceDate) {
            $this->warn('No price components found in database.');
            return Command::SUCCESS;
        }

        // Get active contracts without descriptions
        $contracts = $this->getContractsWithoutDescriptions($latestPriceDate);

        if ($contracts->isEmpty()) {
            $this->info('No contracts need descriptions generated.');
            return Command::SUCCESS;
        }

        $this->info("Found {$contracts->count()} contracts without descriptions.");

        $successCount = 0;
        $errorCount = 0;

        foreach ($contracts as $contractData) {
            $contractId = $contractData['contract']->id;
            $contractName = $contractData['contract']->name;

            $this->info("Processing: {$contractName}");

            try {
                $prompt = $this->openAiService->buildContractPrompt([
                    'company_name' => $contractData['company']->name,
                    'contract_name' => $contractData['contract']->name,
                    'contract_type' => $contractData['contract']->contract_type,
                    'metering' => $contractData['contract']->metering,
                    'price_components' => $contractData['price_components'],
                    'electricity_source' => $contractData['electricity_source'],
                    'consumption_limit' => $contractData['contract']->consumption_limitation_max_x_kwh_per_y,
                ]);

                $description = $this->openAiService->generateDescription($prompt);

                // Update the contract with the generated description
                ElectricityContract::where('id', $contractId)
                    ->update(['short_description' => $description]);

                $this->info("  ✓ Description generated successfully.");
                $successCount++;
            } catch (RequestException $e) {
                $this->error("  ✗ OpenAI API error: {$e->getMessage()}");
                Log::error('Failed to generate description for contract', [
                    'contract_id' => $contractId,
                    'error' => $e->getMessage(),
                ]);
                $errorCount++;
            } catch (\Exception $e) {
                $this->error("  ✗ Error: {$e->getMessage()}");
                Log::error('Unexpected error generating description', [
                    'contract_id' => $contractId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $errorCount++;
            }
        }

        $this->newLine();
        $this->info("Description generation complete.");
        $this->info("  Success: {$successCount}");
        if ($errorCount > 0) {
            $this->warn("  Errors: {$errorCount}");
        }

        return Command::SUCCESS;
    }

    /**
     * Get active contracts without descriptions, including related data.
     *
     * @param string $latestPriceDate
     * @return \Illuminate\Support\Collection
     */
    private function getContractsWithoutDescriptions(string $latestPriceDate)
    {
        // Get active contract IDs
        $activeContractIds = ActiveContract::pluck('id');

        // Get contracts without descriptions
        $contracts = ElectricityContract::whereIn('id', $activeContractIds)
            ->whereNull('short_description')
            ->with(['company', 'electricitySource'])
            ->get();

        // Fetch price components for the latest date
        $priceComponents = PriceComponent::where('price_date', $latestPriceDate)
            ->whereIn('electricity_contract_id', $contracts->pluck('id'))
            ->get()
            ->groupBy('electricity_contract_id');

        // Build the result collection
        return $contracts->map(function ($contract) use ($priceComponents) {
            $components = $priceComponents->get($contract->id, collect());

            return [
                'company' => $contract->company,
                'contract' => $contract,
                'electricity_source' => $contract->electricitySource
                    ? $this->formatElectricitySource($contract->electricitySource)
                    : [],
                'price_components' => $components->map(function ($component) {
                    return [
                        'type' => $component->price_component_type,
                        'price' => $component->price,
                        'unit' => $component->payment_unit,
                    ];
                })->toArray(),
            ];
        });
    }

    /**
     * Format electricity source model to array for prompt building.
     *
     * @param \App\Models\ElectricitySource $source
     * @return array
     */
    private function formatElectricitySource($source): array
    {
        $result = [];

        $fields = [
            'renewable_total',
            'renewable_biomass',
            'renewable_solar',
            'renewable_wind',
            'renewable_general',
            'renewable_hydro',
            'fossil_total',
            'fossil_oil',
            'fossil_coal',
            'fossil_natural_gas',
            'fossil_peat',
            'nuclear_total',
            'nuclear_general',
        ];

        foreach ($fields as $field) {
            if ($source->$field !== null && $source->$field > 0) {
                $result[$field] = $source->$field;
            }
        }

        return $result;
    }
}
