<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Municipality extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'municipalities';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'slug',
        'name',
        'name_locative',
        'name_genitive',
        'region_code',
        'region_name',
        'center_latitude',
        'center_longitude',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'center_latitude' => 'float',
            'center_longitude' => 'float',
        ];
    }

    /**
     * Generate a Finnish-compatible slug from a name.
     */
    public static function generateSlug(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = str_replace(['ä', 'ö', 'å'], ['a', 'o', 'a'], $slug);
        $slug = preg_replace('/[^\w\s-]/u', '', $slug);
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Get the postcodes in this municipality.
     */
    public function postcodes(): HasMany
    {
        return $this->hasMany(Postcode::class, 'municipal_code', 'code');
    }

    /**
     * Scope a query to find a municipality by slug.
     */
    public function scopeBySlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }

    /**
     * Get companies headquartered in this municipality.
     * Matches companies whose postal_name matches the municipality name.
     */
    public function getLocalCompanies(): \Illuminate\Database\Eloquent\Collection
    {
        return Company::where('postal_name', $this->name)->get();
    }

    /**
     * Check if the municipality has center coordinates.
     */
    public function hasCoordinates(): bool
    {
        return $this->center_latitude !== null && $this->center_longitude !== null;
    }
}
