<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Airport extends Model
{
    protected $fillable = ['name', 'code'];

    public function pointsDeVente(): HasMany
    {
        return $this->hasMany(PointDeVente::class);
    }
}
