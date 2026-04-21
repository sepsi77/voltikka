<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractPercentile extends Model
{
    protected $fillable = ['component', 'p15', 'p85', 'count', 'calculated_at'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'p15' => 'float',
            'p85' => 'float',
            'count' => 'integer',
            'calculated_at' => 'datetime',
        ];
    }
}