<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = ['stock_id', 'type', 'quantity', 'reason', 'expiration_date', 'user_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'expiration_date' => 'date',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
