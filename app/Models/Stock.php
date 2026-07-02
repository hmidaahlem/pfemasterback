<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stock extends Model
{
    protected $fillable = ['product_id', 'quantity', 'min_threshold', 'unit'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'min_threshold' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isLow(): bool
    {
        return $this->quantity <= $this->min_threshold;
    }

    protected static function booted()
    {
        static::saved(function (Stock $stock) {
            // Find all FOOD and PLAT products that use this product as an ingredient
            $foodProducts = Product::whereIn('type', ['food', 'plat'])
                ->whereHas('ingredients', function ($query) use ($stock) {
                    $query->where('product_recipe.ingredient_id', $stock->product_id);
                })->get();

            foreach ($foodProducts as $food) {
                // Determine if all ingredients are available
                $allAvailable = true;
                $batchSize = $food->quantity_per_batch ?? 1;

                foreach ($food->ingredients as $ingredient) {
                    $requiredQty = $ingredient->pivot->quantity * $batchSize;
                    $ingredientStock = $ingredient->stock ? $ingredient->stock->quantity : 0;
                    
                    if ($ingredientStock < $requiredQty) {
                        $allAvailable = false;
                        break;
                    }
                }

                if ($allAvailable) {
                    if ($food->usage_status === 'OUT_OF_STOCK') {
                        $food->updateQuietly([
                            'usage_status' => 'IN_USE',
                            'is_active' => true
                        ]);
                    }
                } else {
                    if ($food->usage_status !== 'OUT_OF_STOCK') {
                        $food->updateQuietly([
                            'usage_status' => 'OUT_OF_STOCK',
                            'is_active' => false
                        ]);
                    }
                }
            }
        });
    }
}
