<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\PurchaseNeed;
use App\Models\Stock;
use App\Traits\FifoStockTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PurchaseNeedController extends Controller
{
    public function index(): JsonResponse
    {
        $needs = PurchaseNeed::with('items')
            ->latest('generated_at')
            ->paginate(10);

        return response()->json($needs);
    }

    public function show(PurchaseNeed $purchaseNeed): JsonResponse
    {
        return response()->json(
            $purchaseNeed->load('items', 'menu')
        );
    }


    public static function generateForMenu(Menu $menu): PurchaseNeed
    {
        $menu->load('items.product.ingredients.stock');
        $staffCount = $menu->staff_count ?? 50;

        $ingredientMap = []; // ingredient_id => ['qty' => total, 'days' => count, 'name' => ..., 'unit' => ...]

        foreach ($menu->items as $item) {
            $product = $item->product;
            if (! $product || !in_array($product->type, ['food', 'plat'])) {
                continue;
            }

            foreach ($product->ingredients as $ingredient) {
                $id = $ingredient->id;
                $qtyPerPerson = (float) ($ingredient->pivot->quantity ?? 0);
                $totalQty = $qtyPerPerson * $staffCount;

                if (! isset($ingredientMap[$id])) {
                    $ingredientMap[$id] = [
                        'qty' => 0,
                        'days' => 0,
                        'name' => $ingredient->name,
                        'unit' => $ingredient->pivot->unit ?? 'piece',
                    ];
                }
                $ingredientMap[$id]['qty'] += $totalQty;
                $ingredientMap[$id]['days']++;
            }
        }

        $items = [];
        foreach ($ingredientMap as $ingId => $data) {
            $stock = Stock::where('product_id', $ingId)->first();
            $currentStock = (float) ($stock->quantity ?? 0);

            $stockUnit = $stock?->unit ?? $data['unit'] ?? 'piece';
            $requiredQtyStockUnit = FifoStockTrait::convertQuantityToStockUnit($data['qty'], $data['unit'], $stockUnit);

            $shortfallStockUnit = max(0, $requiredQtyStockUnit - $currentStock);

            // BUG FIX: Only persist items that actually have a shortfall
            if ($shortfallStockUnit <= 0) {
                continue;
            }

            $items[] = [
                'ingredient_id' => $ingId,
                'ingredient_name' => $data['name'],
                'unit' => $stockUnit, // Use stock unit for consistency
                'current_stock' => $currentStock,
                'required_quantity' => $requiredQtyStockUnit,
                'shortfall' => $shortfallStockUnit,
            ];
        }

        // Store in DB
        $need = DB::transaction(function () use ($menu, $staffCount, $items) {
            $need = PurchaseNeed::create([
                'menu_id' => $menu->id,
                'week_start' => $menu->start_date,
                'staff_count' => $staffCount,
                'generated_at' => now(),
            ]);

            foreach ($items as $item) {
                $need->items()->create($item);
            }

            return $need;
        });

        return $need->load('items');
    }
}
