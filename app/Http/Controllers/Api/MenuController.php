<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Notification;
use App\Models\Stock;
use App\Models\User;
use App\Traits\FifoStockTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MenuController extends Controller
{
    use FifoStockTrait;

    public function index(): JsonResponse
    {
        return response()->json(
            Menu::with('items.product', 'creator')
                ->orderBy('start_date', 'desc')
                ->paginate(10)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'staff_count' => 'sometimes|integer|min:1',
            'items' => 'sometimes|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.day_of_week' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'items.*.meal_type' => 'sometimes|in:breakfast,snack,lunch,dinner',
        ]);

        // Check overlapping dates — block if another menu covers this week
        $overlap = Menu::where(function ($q) use ($request) {
            $q->whereBetween('start_date', [$request->start_date, $request->end_date])
                ->orWhereBetween('end_date', [$request->start_date, $request->end_date])
                ->orWhere(function ($q2) use ($request) {
                    $q2->where('start_date', '<=', $request->start_date)
                        ->where('end_date', '>=', $request->end_date);
                });
        })->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'Un menu existe déjà sur cette période. Veuillez choisir une autre semaine.',
            ], 422);
        }

        $menu = Menu::create([
            'name' => $request->name,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'staff_count' => $request->staff_count,
            'status' => 'BROUILLON',
            'created_by' => auth()->id(),
        ]);

        if ($request->has('items')) {
            foreach ($request->items as $item) {
                $menu->items()->create($item);
            }
        }

        // Generate purchase needs prediction (Feature 6)
        $purchaseNeed = PurchaseNeedController::generateForMenu($menu);

        $shortfallCount = $purchaseNeed->items->where('shortfall', '>', 0)->count();

        return response()->json([
            'message' => 'Menu créé (brouillon).',
            'menu' => $menu->load('items.product'),
            'purchase_need' => [
                'id' => $purchaseNeed->id,
                'items_requiring_restock' => $shortfallCount,
            ],
        ], 201);
    }

    /**
     * Submit a BROUILLON menu for stock validation.
     */
    public function submit(Request $request, Menu $menu): JsonResponse
    {
        if ($menu->status !== 'BROUILLON') {
            return response()->json([
                'message' => 'Seuls les menus au statut BROUILLON peuvent être soumis pour validation.',
            ], 422);
        }

        $menu->load('items.product.ingredients.stock', 'items.product.stock');
        $staffCount = $menu->staff_count ?? 50;

        // Validate stock for each item
        $insufficientItems = [];
        $allSufficient = true;

        foreach ($menu->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            if (in_array($product->type, ['food', 'plat']) && $product->ingredients->isNotEmpty()) {
                foreach ($product->ingredients as $ingredient) {
                    $rawRequired = $ingredient->pivot->quantity * $staffCount;
                    $requiredQty = $this->convertQuantityToStockUnit($rawRequired, $ingredient->pivot->unit, $ingredient->stock?->unit);
                    $availableQty = $ingredient->stock?->quantity ?? 0;
                    if ($availableQty < $requiredQty) {
                        $allSufficient = false;
                        $insufficientItems[] = [
                            'product' => $product->name.' > '.$ingredient->name,
                            'required' => $requiredQty,
                            'available' => $availableQty,
                            'unit' => $ingredient->stock?->unit ?? $ingredient->pivot->unit ?? 'piece',
                        ];
                    }
                }
            } elseif ($product->stock) {
                $requiredQty = $item->quantity ?? 1;
                $availableQty = $product->stock->quantity;
                if ($availableQty < $requiredQty) {
                    $allSufficient = false;
                    $insufficientItems[] = [
                        'product' => $product->name,
                        'required' => $requiredQty,
                        'available' => $availableQty,
                        'unit' => $product->stock->unit ?? 'piece',
                    ];
                }
            }
        }

        // Generate purchase needs prediction (Feature 6)
        $purchaseNeed = PurchaseNeedController::generateForMenu($menu);
        $shortfallCount = $purchaseNeed->items->where('shortfall', '>', 0)->count();

        if ($allSufficient) {
            // VALIDE — deduct stock via FIFO
            DB::transaction(function () use ($menu, $staffCount) {
                $menu->update(['status' => 'VALIDE', 'is_active' => true, 'comment' => null]);

                foreach ($menu->items as $item) {
                    $product = $item->product;
                    if (! $product) {
                        continue;
                    }

                    if (in_array($product->type, ['food', 'plat']) && $product->ingredients->isNotEmpty()) {
                        foreach ($product->ingredients as $ingredient) {
                            $rawRequired = $ingredient->pivot->quantity * $staffCount;
                            $ingredientQty = $this->convertQuantityToStockUnit($rawRequired, $ingredient->pivot->unit, $ingredient->stock?->unit);
                            if ($ingredient->stock && $ingredientQty > 0) {
                                $this->fifoDeduction($ingredient->stock, $ingredientQty, 'Menu: '.$menu->name);
                            }
                        }
                    } elseif ($product->stock) {
                        $requiredQty = $item->quantity ?? 1;
                        $this->fifoDeduction($product->stock, $requiredQty, 'Menu: '.$menu->name);
                    }
                }
            });

            return response()->json([
                'message' => 'Menu validé. Stock déduit selon la méthode FIFO.',
                'menu' => $menu->fresh()->load('items.product'),
                'purchase_need' => [
                    'id' => $purchaseNeed->id,
                    'items_requiring_restock' => $shortfallCount,
                ],
            ]);
        }

        // REFUSE — build auto-comment, notify roles
        $details = collect($insufficientItems)->map(fn ($i) => "- {$i['product']}: requis {$i['required']} {$i['unit']}, disponible {$i['available']} {$i['unit']}"
        )->implode("\n");

        $commentText = "[AUTO] Stock insuffisant pour le menu \"{$menu->name}\":\n{$details}";

        DB::transaction(function () use ($menu, $commentText) {
            $menu->update(['status' => 'REFUSE', 'is_active' => false, 'comment' => $commentText]);

            // Create auto-comment on the menu
            $menu->comments()->create([
                'user_id' => auth()->id(),
                'body' => $commentText,
            ]);
        });

        // Notify Chef Magasin (action needed)
        $chefMagasinUsers = User::whereHas('role', fn ($q) => $q->where('name', 'CHEF_MAGASIN'))->get();
        foreach ($chefMagasinUsers as $user) {
            Notification::create([
                'user_id' => $user->id,
                'title' => 'Menu Refusé — Stock Insuffisant',
                'message' => "Le menu \"{$menu->name}\" a été refusé. Des ingrédients manquent en stock.",
                'type' => 'warning',
                'is_read' => false,
                'data' => ['menu_id' => $menu->id, 'insufficient_items' => $insufficientItems],
            ]);
        }

        // Notify Responsable F&B (info)
        $fbUsers = User::whereHas('role', fn ($q) => $q->where('name', 'RESPONSABLE_FB'))->get();
        foreach ($fbUsers as $user) {
            Notification::create([
                'user_id' => $user->id,
                'title' => 'Menu Hebdomadaire Refusé',
                'message' => "Le menu \"{$menu->name}\" n'a pu être validé par manque de stock.",
                'type' => 'info',
                'is_read' => false,
                'data' => ['menu_id' => $menu->id],
            ]);
        }

        return response()->json([
            'message' => 'Menu refusé — stock insuffisant.',
            'menu' => $menu->fresh()->load('items.product'),
            'insufficient_items' => $insufficientItems,
            'purchase_need' => [
                'id' => $purchaseNeed->id,
                'items_requiring_restock' => $shortfallCount,
            ],
        ], 422);
    }

    public function show(Menu $menu): JsonResponse
    {
        return response()->json($menu->load('items.product', 'creator'));
    }

    public function update(Request $request, Menu $menu): JsonResponse
    {
        // Only allow editing BROUILLON menus
        if ($menu->status !== 'BROUILLON') {
            return response()->json([
                'message' => 'Seuls les menus au statut BROUILLON peuvent être modifiés.',
            ], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
            'staff_count' => 'sometimes|integer|min:1',
            'items' => 'sometimes|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.day_of_week' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'items.*.meal_type' => 'sometimes|in:breakfast,snack,lunch,dinner',
        ]);

        // Check overlapping dates if dates changed
        if ($request->has('start_date') || $request->has('end_date')) {
            $ws = $request->start_date ?? $menu->start_date->toDateString();
            $we = $request->end_date ?? $menu->end_date->toDateString();
            $overlap = Menu::where('id', '!=', $menu->id)
                ->where(function ($q) use ($ws, $we) {
                    $q->whereBetween('start_date', [$ws, $we])
                        ->orWhereBetween('end_date', [$ws, $we])
                        ->orWhere(function ($q2) use ($ws, $we) {
                            $q2->where('start_date', '<=', $ws)
                                ->where('end_date', '>=', $we);
                        });
                })->exists();

            if ($overlap) {
                return response()->json([
                    'message' => 'Un menu existe déjà sur cette période.',
                ], 422);
            }
        }

        DB::transaction(function () use ($menu, $request) {
            $data = $request->only(['name', 'start_date', 'end_date', 'staff_count']);
            if (! empty($data)) {
                $menu->update($data);
            }

            if ($request->has('items')) {
                $menu->items()->delete();
                foreach ($request->items as $item) {
                    $menu->items()->create($item);
                }
            }
        });

        // Generate purchase needs prediction (Feature 6)
        $purchaseNeed = PurchaseNeedController::generateForMenu($menu);
        $shortfallCount = $purchaseNeed->items->where('shortfall', '>', 0)->count();

        return response()->json([
            'message' => 'Menu mis à jour.',
            'menu' => $menu->fresh()->load('items.product'),
            'purchase_need' => [
                'id' => $purchaseNeed->id,
                'items_requiring_restock' => $shortfallCount,
            ],
        ]);
    }

    public function destroy(Menu $menu): JsonResponse
    {
        $menu->delete();

        return response()->json(['message' => 'Menu supprimé.']);
    }

    /**
     * Clone a past week's menu into a new BROUILLON for the target week.
     * Runs stock re-validation immediately and returns the result.
     */
    public function clone(Request $request, Menu $menu): JsonResponse
    {
        $request->validate([
            'target_week_start' => 'required|date',
            'target_week_end' => 'required|date|after_or_equal:target_week_start',
            'staff_count' => 'sometimes|integer|min:1',
        ]);

        $targetWs = $request->target_week_start;
        $targetWe = $request->target_week_end;

        // Check overlap for target week
        $overlap = Menu::where(function ($q) use ($targetWs, $targetWe) {
            $q->whereBetween('start_date', [$targetWs, $targetWe])
                ->orWhereBetween('end_date', [$targetWs, $targetWe])
                ->orWhere(function ($q2) use ($targetWs, $targetWe) {
                    $q2->where('start_date', '<=', $targetWs)
                        ->where('end_date', '>=', $targetWe);
                });
        })->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'Un menu existe déjà sur la période cible.',
            ], 422);
        }

        $menu->load('items');

        // Create cloned menu as BROUILLON
        $cloned = Menu::create([
            'name' => $menu->name.' (clone)',
            'start_date' => $targetWs,
            'end_date' => $targetWe,
            'staff_count' => $request->staff_count ?? $menu->staff_count,
            'status' => 'BROUILLON',
            'created_by' => auth()->id(),
        ]);

        // Clone items
        foreach ($menu->items as $item) {
            $cloned->items()->create([
                'product_id' => $item->product_id,
                'day_of_week' => $item->day_of_week,
                'meal_type' => $item->meal_type,
            ]);
        }

        $cloned->load('items.product.ingredients.stock', 'items.product.stock');
        $staffCount = $cloned->staff_count ?? 50;

        // Run stock validation
        $insufficientItems = [];
        $allSufficient = true;

        foreach ($cloned->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            if (in_array($product->type, ['food', 'plat']) && $product->ingredients->isNotEmpty()) {
                foreach ($product->ingredients as $ingredient) {
                    $requiredQty = $ingredient->pivot->quantity * $staffCount;
                    $availableQty = $ingredient->stock?->quantity ?? 0;
                    if ($availableQty < $requiredQty) {
                        $allSufficient = false;
                        $insufficientItems[] = [
                            'product' => $product->name.' > '.$ingredient->name,
                            'required' => $requiredQty,
                            'available' => $availableQty,
                            'unit' => $ingredient->pivot->unit ?? 'piece',
                        ];
                    }
                }
            } elseif ($product->stock) {
                $requiredQty = $item->quantity ?? 1;
                $availableQty = $product->stock->quantity;
                if ($availableQty < $requiredQty) {
                    $allSufficient = false;
                    $insufficientItems[] = [
                        'product' => $product->name,
                        'required' => $requiredQty,
                        'available' => $availableQty,
                        'unit' => $product->stock->unit ?? 'piece',
                    ];
                }
            }
        }

        return response()->json([
            'message' => $allSufficient
                ? 'Menu cloné avec succès. Tous les ingrédients sont en stock suffisant.'
                : 'Menu cloné, mais certains ingrédients sont insuffisants.',
            'menu' => $cloned->fresh()->load('items.product'),
            'valid' => $allSufficient,
            'insufficient_items' => $insufficientItems,
        ]);
    }

    public function currentWeek(): JsonResponse
    {
        $menu = Menu::with('items.product')
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->first();

        return response()->json($menu);
    }
}
