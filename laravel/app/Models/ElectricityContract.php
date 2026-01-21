<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ElectricityContract extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'electricity_contracts';

    /**
     * The primary key associated with the table.
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model's ID is auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the primary key.
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model should be timestamped.
     * The legacy table doesn't have timestamps.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'api_id',
        'company_name',
        'name',
        'name_slug',
        'contract_type',
        'spot_price_selection',
        'fixed_time_range',
        'metering',
        'pricing_model',
        'target_group',
        'short_description',
        'long_description',
        'pricing_name',
        'pricing_has_discounts',
        'consumption_control',
        'consumption_limitation_min_x_kwh_per_y',
        'consumption_limitation_max_x_kwh_per_y',
        'pre_billing',
        'available_for_existing_users',
        'delivery_responsibility_product',
        'order_link',
        'product_link',
        'billing_frequency',
        'time_period_definitions',
        'transparency_index',
        'extra_information_default',
        'extra_information_fi',
        'extra_information_en',
        'extra_information_sv',
        'availability_is_national',
        'microproduction_buys',
        'microproduction_default',
        'microproduction_fi',
        'microproduction_sv',
        'microproduction_en',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pricing_has_discounts' => 'boolean',
            'consumption_control' => 'boolean',
            'consumption_limitation_min_x_kwh_per_y' => 'float',
            'consumption_limitation_max_x_kwh_per_y' => 'float',
            'pre_billing' => 'boolean',
            'available_for_existing_users' => 'boolean',
            'delivery_responsibility_product' => 'boolean',
            'billing_frequency' => 'array',
            'time_period_definitions' => 'array',
            'transparency_index' => 'array',
            'availability_is_national' => 'boolean',
            'microproduction_buys' => 'boolean',
        ];
    }

    /**
     * Boot the model and register event listeners.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (ElectricityContract $contract) {
            // Auto-generate name_slug
            $contract->name_slug = static::generateSlug($contract->name);

            // Auto-generate api_id if not provided (for tests and legacy compatibility)
            if (empty($contract->api_id)) {
                $contract->api_id = (string) Str::uuid();
            }
        });
    }

    /**
     * Generate a Finnish-compatible slug from a name.
     * Replaces Finnish characters (ä, ö, å) with ASCII equivalents.
     * Uses the same logic as Company::generateSlug for consistency.
     */
    public static function generateSlug(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = str_replace(['ä', 'ö', 'å'], ['a', 'o', 'a'], $slug);
        // Remove any character that isn't alphanumeric, whitespace, or hyphen
        $slug = preg_replace('/[^\w\s-]/u', '', $slug);
        // Replace underscores and whitespace with hyphens
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        // Remove consecutive hyphens
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Generate a custom contract ID.
     * Format: {random}-{company-slug}-{contract-slug}
     * Example: x7k9m2-fortum-tuntisahko
     */
    public static function generateId(string $companyName, string $contractName): string
    {
        $random = strtolower(Str::random(6));
        $companySlug = static::generateSlug($companyName);
        $contractSlug = static::generateSlug($contractName);

        return "{$random}-{$companySlug}-{$contractSlug}";
    }

    /**
     * Get the company that owns the contract.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_name', 'name');
    }

    /**
     * Get the price components for the contract.
     */
    public function priceComponents(): HasMany
    {
        return $this->hasMany(PriceComponent::class, 'electricity_contract_id', 'id');
    }

    /**
     * Get the electricity source for the contract.
     */
    public function electricitySource(): HasOne
    {
        return $this->hasOne(ElectricitySource::class, 'contract_id', 'id');
    }

    /**
     * Get the postcodes where this contract is available.
     */
    public function availabilityPostcodes(): BelongsToMany
    {
        return $this->belongsToMany(
            Postcode::class,
            'contract_postcode',
            'contract_id',
            'postcode',
            'id',
            'postcode'
        );
    }

    /**
     * Check if a given consumption value falls within the contract's allowed range.
     *
     * If either limit is null, treat it as no restriction on that side.
     *
     * @param int|float $consumption The consumption value in kWh/year to check
     * @return bool True if the consumption is within the allowed range
     */
    public function isConsumptionInRange(int|float $consumption): bool
    {
        $min = $this->consumption_limitation_min_x_kwh_per_y;
        $max = $this->consumption_limitation_max_x_kwh_per_y;

        if ($min !== null && $consumption < $min) {
            return false;
        }

        if ($max !== null && $consumption > $max) {
            return false;
        }

        return true;
    }

    /**
     * Check if this contract has any consumption limitations.
     *
     * @return bool True if either min or max consumption limit is set
     */
    public function hasConsumptionLimits(): bool
    {
        return $this->consumption_limitation_min_x_kwh_per_y !== null
            || $this->consumption_limitation_max_x_kwh_per_y !== null;
    }
}
