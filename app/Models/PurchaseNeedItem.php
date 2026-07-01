<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseNeedItem extends Model
{
    protected $fillable = [
        'purchase_need_id', 'ingredient_id', 'ingredient_name', 'unit',
        'current_stock', 'required_quantity', 'shortfall',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:2',
            'required_quantity' => 'decimal:2',
            'shortfall' => 'decimal:2',
        ];
    }

    public function purchaseNeed(): BelongsTo
    {
        return $this->belongsTo(PurchaseNeed::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_id');
    }
}
