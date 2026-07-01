<?php

namespace App\Traits;

use App\Models\Notification;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;

trait FifoStockTrait
{
    /**
     * Deduct quantity from a stock using FIFO strategy and record the movement.
     */
    protected function fifoDeduction(Stock $stock, float $quantityNeeded, ?string $reason = null): void
    {
        if ($stock->quantity < $quantityNeeded) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'message' => "Stock insuffisant pour {$stock->product?->name}. Disponible : {$stock->quantity}, requis : {$quantityNeeded}",
                ], 422)
            );
        }

        $batches = StockMovement::where('stock_id', $stock->id)
            ->where('type', 'in')
            ->where('quantity', '>', 0)
            ->orderByRaw('ISNULL(expiration_date) ASC')
            ->orderBy('expiration_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        $remaining = $quantityNeeded;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            if ($batch->quantity >= $remaining) {
                $batch->decrement('quantity', $remaining);
                $remaining = 0;
            } else {
                $remaining -= $batch->quantity;
                $batch->update(['quantity' => 0]);
            }
        }

        // Record the out movement
        $stock->movements()->create([
            'type' => 'out',
            'quantity' => $quantityNeeded,
            'reason' => $reason ?? 'FIFO deduction',
            'user_id' => auth()->id(),
        ]);

        $stock->decrement('quantity', $quantityNeeded);

        // Check and trigger low stock notification
        $this->notifyLowStockIfNecessary($stock);
    }

    /**
     * Check if stock quantity is below min_threshold and notify users.
     */
    protected function notifyLowStockIfNecessary(Stock $stock): void
    {
        if ($stock->quantity <= $stock->min_threshold) {
            $productName = $stock->product?->name ?? 'Produit';
            $users = User::whereHas('role', fn ($q) => $q->whereIn('name', ['CHEF_MAGASIN', 'RESPONSABLE_ACHAT']))->get();

            foreach ($users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Alerte stock bas',
                    'message' => "Le stock de \"{$productName}\" est bas ({$stock->quantity} unités restantes).",
                    'type' => 'warning',
                    'is_read' => false,
                    'data' => ['stock_id' => $stock->id, 'product_id' => $stock->product_id],
                ]);
            }
        }
    }

    /**
     * Normalize unit synonyms to a canonical unit.
     */
    public static function normalizeUnit(?string $unit): string
    {
        $unit = strtolower(trim($unit ?? ''));
        if (empty($unit)) {
            return 'piece';
        }

        // Grams synonyms
        if (in_array($unit, ['g', 'gram', 'grams', 'gramme', 'grammes', 'جرام', 'غ', 'غرام'])) {
            return 'g';
        }

        // Kilograms synonyms
        if (in_array($unit, ['kg', 'kilo', 'kilos', 'kilogram', 'kilograms', 'kilogramme', 'kilogrammes', 'كيلو', 'كغ', 'كيلوجرام'])) {
            return 'kg';
        }

        // Millilitres synonyms
        if (in_array($unit, ['ml', 'milliliter', 'milliliters', 'millilitre', 'millilitres', 'مل', 'مليلتر'])) {
            return 'ml';
        }

        // Litres synonyms
        if (in_array($unit, ['l', 'liter', 'liters', 'litre', 'litres', 'لتر', 'ل'])) {
            return 'liter';
        }

        // Pieces synonyms
        if (in_array($unit, ['piece', 'pieces', 'pc', 'pcs', 'unit', 'units', 'unité', 'unite', 'unités', 'unites', 'u', 'قطعة', 'حبة', 'كعب'])) {
            return 'piece';
        }

        return $unit;
    }

    /**
     * Convert quantity from recipe unit to stock unit.
     */
    public static function convertQuantityToStockUnit(float $quantity, ?string $recipeUnit, ?string $stockUnit): float
    {
        $recipeNorm = self::normalizeUnit($recipeUnit);
        $stockNorm = self::normalizeUnit($stockUnit);

        if ($recipeNorm === $stockNorm) {
            return $quantity;
        }

        // Weight conversion (g to kg / kg to g)
        if ($recipeNorm === 'g' && $stockNorm === 'kg') {
            return $quantity / 1000;
        }
        if ($recipeNorm === 'kg' && $stockNorm === 'g') {
            return $quantity * 1000;
        }

        // Volume conversion (ml to liter / liter to ml)
        if ($recipeNorm === 'ml' && $stockNorm === 'liter') {
            return $quantity / 1000;
        }
        if ($recipeNorm === 'liter' && $stockNorm === 'ml') {
            return $quantity * 1000;
        }

        return $quantity;
    }
}
