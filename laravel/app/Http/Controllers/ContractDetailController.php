<?php

namespace App\Http\Controllers;

use App\Livewire\ContractDetail;
use App\Models\ElectricityContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Symfony\Component\HttpFoundation\Response;

class ContractDetailController extends Controller
{
    /**
     * Handle contract detail page with legacy UUID redirect support.
     *
     * - If contractId is a legacy UUID, redirects to canonical URL with 301
     * - Otherwise, renders the Livewire component
     */
    public function __invoke(string $contractId): Response
    {
        // Check if this looks like a legacy UUID (36 chars with hyphens)
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $contractId)) {
            $contract = ElectricityContract::where('api_id', $contractId)->first();

            if ($contract) {
                // Return 301 redirect to canonical URL
                return redirect(
                    route('contract.detail', ['contractId' => $contract->id]),
                    301
                );
            }

            // UUID not found - return 404
            abort(404);
        }

        // Not a UUID - render the Livewire full-page component
        $html = app(HandleComponents::class)->mount(ContractDetail::class, ['contractId' => $contractId]);

        return response($html);
    }
}
