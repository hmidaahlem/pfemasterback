<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class CheckIngredientStock extends Command
{
    protected $signature = 'stock:check-ingredients';

    protected $description = 'Check ingredient stock levels and auto-activate/deactivate FOOD products';

    public function handle(): int
    {
        $foodProducts = Product::where('type', 'food')
            ->where('approval_status', 'approved')
            ->with('ingredients.stock')
            ->get();

        $updated = 0;

        foreach ($foodProducts as $product) {
            $shouldBeActive = true;
            foreach ($product->ingredients as $ingredient) {
                if (! $ingredient->stock || $ingredient->stock->quantity <= 0) {
                    $shouldBeActive = false;
                    break;
                }
            }

            if ($product->is_active !== $shouldBeActive) {
                $product->update([
                    'is_active' => $shouldBeActive,
                    'usage_status' => $shouldBeActive ? 'IN_USE' : 'OUT_OF_STOCK'
                ]);
                $updated++;
                $this->info(
                    $shouldBeActive
                        ? "Activé: {$product->name} (stock replenished)"
                        : "Désactivé: {$product->name} (all ingredients at zero)"
                );
            }
        }

        $this->info("Vérification terminée. {$updated} produit(s) mis à jour.");

        return Command::SUCCESS;
    }
}
