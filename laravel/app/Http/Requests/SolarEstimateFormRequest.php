<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SolarEstimateFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'system_kwp' => ['nullable', 'numeric', 'between:0.1,100'],
            'roof_tilt_deg' => ['nullable', 'integer', 'between:0,90'],
            'roof_aspect_deg' => ['nullable', 'integer', 'between:-180,180'],
            'shading_level' => ['nullable', 'in:none,some,heavy'],
        ];
    }
}
