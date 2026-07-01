<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalOrderItem extends Model
{
    protected $fillable = ['internal_order_id', 'product_id', 'quantity_requested', 'quantity_fulfilled'];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:2',
            'quantity_fulfilled' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(InternalOrder::class, 'internal_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
