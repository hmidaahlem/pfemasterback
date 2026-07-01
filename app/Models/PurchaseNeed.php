<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseNeed extends Model
{
    protected $fillable = ['menu_id', 'week_start', 'staff_count', 'generated_at'];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'generated_at' => 'datetime',
            'staff_count' => 'integer',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseNeedItem::class);
    }
}
