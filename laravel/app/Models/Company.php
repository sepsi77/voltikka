<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'companies';

    /**
     * The primary key associated with the table.
     */
    protected $primaryKey = 'name';

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
        'name',
        'name_slug',
        'company_url',
        'street_address',
        'postal_code',
        'postal_name',
        'logo_url',
        'local_logo_path',
    ];

    /**
     * Boot the model and register event listeners.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate name_slug before creating or updating
        static::saving(function (Company $company) {
            $company->name_slug = static::generateSlug($company->name);
        });
    }

    /**
     * Generate a Finnish-compatible slug from a name.
     * Replaces Finnish characters (ä, ö, å) with ASCII equivalents.
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
     * Get the electricity contracts for the company.
     */
    public function electricityContracts(): HasMany
    {
        return $this->hasMany(ElectricityContract::class, 'company_name', 'name');
    }

    /**
     * Check if the company is headquartered in the given municipality.
     * Matches by postal_name (city name in the company's address).
     */
    public function isHeadquarteredIn(string $municipalityName): bool
    {
        return mb_strtolower($this->postal_name ?? '') === mb_strtolower($municipalityName);
    }

    /**
     * Get the logo URL, preferring local storage over external URL.
     */
    protected function logoUrlResolved(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLogoUrl(),
        );
    }

    /**
     * Get the locally controlled logo path, preferring an optimized WebP file.
     */
    public function getLocalLogoPath(): ?string
    {
        $localPath = trim((string) $this->local_logo_path);

        if ($localPath === '') {
            return null;
        }

        $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
        if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true)) {
            $webpPath = substr($localPath, 0, -strlen($extension)).'webp';

            if (Storage::disk('public')->exists($webpPath)) {
                return $webpPath;
            }
        }

        return Storage::disk('public')->exists($localPath) ? $localPath : null;
    }

    /**
     * Get the public URL for a locally controlled logo.
     */
    public function getLocalLogoUrl(): ?string
    {
        $path = $this->getLocalLogoPath();

        return $path === null ? null : Storage::disk('public')->url($path);
    }

    /**
     * Get the logo URL for display, with an external URL as the final fallback.
     */
    public function getLogoUrl(): ?string
    {
        if ($localUrl = $this->getLocalLogoUrl()) {
            return $localUrl;
        }

        $externalUrl = trim((string) $this->logo_url);

        return $externalUrl === '' ? null : $externalUrl;
    }
}
