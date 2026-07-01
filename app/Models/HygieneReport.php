<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HygieneReport extends Model
{
    protected $fillable = [
        'product_id', 'inspected_by', 'allergens_verified',
        'expiration_verified', 'status', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'allergens_verified' => 'boolean',
            'expiration_verified' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
