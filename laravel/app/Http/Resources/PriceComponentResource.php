<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceComponentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'price_component_type' => $this->price_component_type,
            'price' => $this->price,
            'payment_unit' => $this->payment_unit,
            'price_date' => $this->price_date?->toDateString(),
            'has_discount' => $this->has_discount,
            'discount_value' => $this->when($this->has_discount, $this->discount_value),
            'discount_type' => $this->when($this->has_discount, $this->discount_type),
        ];
    }
}
