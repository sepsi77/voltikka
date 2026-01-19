<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_slug' => $this->name_slug,
            'contract_type' => $this->contract_type,
            'metering' => $this->metering,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'order_link' => $this->order_link,
            'product_link' => $this->product_link,
            'pricing_has_discounts' => $this->pricing_has_discounts,
            'availability_is_national' => $this->availability_is_national,
            'company' => new CompanyResource($this->whenLoaded('company')),
            'price_components' => PriceComponentResource::collection($this->whenLoaded('priceComponents')),
            'electricity_source' => new ElectricitySourceResource($this->whenLoaded('electricitySource')),
            'calculated_cost' => $this->when(
                isset($this->calculated_cost),
                fn () => $this->calculated_cost
            ),
        ];
    }
}
