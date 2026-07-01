<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointDeVente extends Model
{
    protected $table = 'points_de_vente';

    protected $fillable = [
        'name',
        'airport_id',
        'is_active',
        'responsable_fb_id',
        'location',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'pdv_id');
    }

    public function responsableFb()
    {
        return $this->belongsTo(User::class, 'responsable_fb_id');
    }

    public function plannings(): HasMany
    {
        return $this->hasMany(Planning::class, 'pdv_id');
    }
}
