<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Postcode extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'postcodes';

    /**
     * The primary key associated with the table.
     */
    protected $primaryKey = 'postcode';

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
        // Primary key
        'postcode',
        // Finnish name fields
        'postcode_fi_name',
        'postcode_fi_name_slug',
        'postcode_abbr_fi',
        // Swedish name fields
        'postcode_sv_name',
        'postcode_sv_name_slug',
        'postcode_abbr_sv',
        // Area fields
        'type_code',
        'ad_area_code',
        'ad_area_fi',
        'ad_area_fi_slug',
        'ad_area_sv',
        'ad_area_sv_slug',
        // Municipality fields
        'municipal_code',
        'municipal_name_fi',
        'municipal_name_fi_slug',
        'municipal_name_sv',
        'municipal_name_sv_slug',
        'municipal_language_ratio_code',
    ];

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
     * Get the contracts available in this postcode.
     */
    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(
            ElectricityContract::class,
            'contract_postcode',
            'postcode',
            'contract_id',
            'postcode',
            'id'
        );
    }

    /**
     * Scope a query to only include postcodes in a specific municipality.
     */
    public function scopeInMunicipality(Builder $query, string $municipalNameFi): Builder
    {
        return $query->where('municipal_name_fi', $municipalNameFi);
    }

    /**
     * Scope a query to search postcodes by Finnish or Swedish name.
     * Uses case-insensitive matching (ILIKE for PostgreSQL, LIKE for SQLite).
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        // SQLite doesn't support ILIKE, so we use LIKE which is case-insensitive by default
        $driver = $query->getConnection()->getDriverName();
        $likeOperator = $driver === 'pgsql' ? 'ilike' : 'like';

        return $query->where(function (Builder $query) use ($search, $likeOperator) {
            $query->where('postcode', 'like', "{$search}%")
                  ->orWhere('postcode_fi_name', $likeOperator, "%{$search}%")
                  ->orWhere('postcode_sv_name', $likeOperator, "%{$search}%")
                  ->orWhere('municipal_name_fi', $likeOperator, "%{$search}%")
                  ->orWhere('municipal_name_sv', $likeOperator, "%{$search}%");
        });
    }
}
