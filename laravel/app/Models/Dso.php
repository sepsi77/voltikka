<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Dso extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'dsos';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'name_slug',
    ];

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
     * Boot the model and register event listeners.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Dso $dso) {
            $dso->name_slug = static::generateSlug($dso->name);
        });
    }

    /**
     * Get the contracts available in this DSO's service area.
     */
    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(
            ElectricityContract::class,
            'contract_dso',
            'dso_id',
            'contract_id',
            'id',
            'id'
        );
    }
}
