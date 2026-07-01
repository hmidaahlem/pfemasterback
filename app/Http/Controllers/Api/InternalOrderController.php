<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalOrder;
use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use App\Traits\FifoStockTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InternalOrderController extends Controller
{
    use FifoStockTrait;

    public function index(Request $request): JsonResponse
    {
        $query = InternalOrder::with('creator', 'assignee', 'pointDeVente', 'items.product');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Access restriction: filter orders by user role
        /** @var User $user */
        $user = Auth::user();
        if ($user->role?->name === 'SUPER_ADMIN') {
            // Super Admin has access to all orders
        } elseif ($user->role?->name === 'RESPONSABLE_FB') {
            // Responsable FB can see orders created by them or for the points of sale they manage
            $pdvIds = $user->pdvsResponsable()->pluck('id')->toArray();
            $query->where(function ($q) use ($user, $pdvIds) {
                $q->where('created_by', $user->id)
                    ->orWhereIn('pdv_id', $pdvIds);
            });
        } elseif ($user->role?->name === 'CHEF_CUISINE') {
            $query->where(function ($q) use ($user) {
                $q->where('type', 'food')
                    ->orWhere('created_by', $user->id);
            });
        } elseif ($user->role?->name === 'CHEF_MAGASIN') {
            $query->where(function ($q) use ($user) {
                $q->where('type', 'commercial')
                    ->orWhere('created_by', $user->id);
            });
        } elseif ($user->role?->name === 'RESPONSABLE_ACHAT') {
            // Responsable Achat can view orders directed to the store (type = commercial)
            $query->where('type', 'commercial');
        } else {
            // Other roles (e.g. CAISSIER) can only see orders created by them
            $query->where('created_by', $user->id);
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate(15));
    }

    public function getProductsByCategories(Request $request): JsonResponse
    {
        $request->validate([
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
            'type' => 'nullable|string',
        ]);

        $query = Product::where('is_active', true)
            ->where('approval_status', 'approved');

        if ($request->has('category_ids') && count($request->category_ids) > 0) {
            $query->whereIn('category_id', $request->category_ids);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $products = $query->select('id', 'name', 'price', 'category_id', 'type', 'image')->get();

        return response()->json([
            'data' => $products,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:food,commercial',
            'pdv_id' => 'nullable|exists:points_de_vente,id',
            'notes' => 'nullable|string',
            'delivery_date' => 'nullable|date',

            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_requested' => 'required|numeric|min:0.01',
        ]);

        // assignation automatique — all internal orders go to the store manager (Chef Magasin)
        // who controls stock and fulfills requests from all roles
        $assignedRole = 'CHEF_MAGASIN';

        $assignee = User::whereHas('role', function ($q) use ($assignedRole) {
            $q->where('name', $assignedRole);
        })->first();

        // creation commande
        $order = InternalOrder::create([
            'type' => $request->type,
            'status' => 'EN_ATTENTE', // IMPORTANT (initial state)
            'created_by' => Auth::id(),
            'assigned_to' => $assignee?->id,
            'pdv_id' => $request->pdv_id,
            'notes' => $request->notes,
            'delivery_date' => $request->delivery_date,
        ]);

        // items (produits selectionnes)
        foreach ($request->items as $item) {

            $product = Product::find($item['product_id']);

            // securite metier
            if (! $product || ! $product->is_active || $product->approval_status !== 'approved') {
                continue;
            }

            $order->items()->create([
                'product_id' => $product->id,
                'quantity_requested' => $item['quantity_requested'],
                'quantity_fulfilled' => 0,
            ]);
        }

        // Notify assignee about the new order
        if ($assignee) {
            Notification::create([
                'user_id' => $assignee->id,
                'title' => 'New Internal Order',
                'message' => "A new internal order of type {$order->type} has been created and assigned to you.",
                'type' => 'info',
                'is_read' => false,
                'data' => ['order_id' => $order->id],
            ]);
        }

        return response()->json([
            'message' => 'Commande créée avec succès',
            'order' => $order->load('items.product', 'assignee'),
        ], 201);
    }

    public function show(InternalOrder $internalOrder): JsonResponse
    {
        if (! $this->authorizeOrder($internalOrder)) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        return response()->json(
            $internalOrder->load('creator', 'assignee', 'pointDeVente', 'items.product', 'comments.user')
        );
    }

    public function updateStatus(Request $request, InternalOrder $internalOrder): JsonResponse
    {
        if (! $this->authorizeOrder($internalOrder)) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        // Prevent caissier and responsable achat from updating status
        /** @var User $user */
        $user = Auth::user();
        if ($user->isCaissier() || $user->role?->name === 'RESPONSABLE_ACHAT') {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à modifier le statut de la commande.',
            ], 403);
        }

        $request->validate([
            'status' => 'required|in:EN_ATTENTE,DISPONIBLE,PARTIELLEMENT_DISPONIBLE,NON_DISPONIBLE',
        ]);

        if (in_array($request->status, ['DISPONIBLE', 'PARTIELLEMENT_DISPONIBLE'])) {
            $totalFulfilled = $internalOrder->items()->sum('quantity_fulfilled');
            if ($totalFulfilled <= 0) {
                return response()->json([
                    'message' => 'Impossible de passer la commande en statut ' . $request->status . ' car aucune quantité n\'a été livrée. Veuillez renseigner les quantités d\'abord.',
                    'errors' => ['status' => ['Aucune quantité livrée n\'a été renseignée.']]
                ], 422);
            }
        }

        $oldStatus = $internalOrder->status;
        $internalOrder->update(['status' => $request->status]);

        // Send notifications
        $this->notifyOrderStatusChanged($internalOrder, $oldStatus);

        return response()->json([
            'message' => 'Statut de la commande mis à jour.',
            'order' => $internalOrder->fresh()->load('items.product'),
        ]);
    }

    public function fulfillItem(Request $request, InternalOrder $internalOrder, int $itemId): JsonResponse
    {
        if (! $this->authorizeOrder($internalOrder)) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        /** @var User $user */
        $user = Auth::user();
        if ($user->role?->name === 'RESPONSABLE_ACHAT') {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à modifier les quantités de la commande.',
            ], 403);
        }

        $item = $internalOrder->items()->findOrFail($itemId);

        $request->validate([
            'quantity_fulfilled' => 'required|numeric|min:0|max:'.$item->quantity_requested,
        ]);

        $oldStatus = $internalOrder->status;
        $diff = $request->quantity_fulfilled - $item->quantity_fulfilled;

        // Verify stock sufficiency before fulfilling
        if ($diff > 0 && $item->product) {
            $product = $item->product;
            if ($product->type === 'food') {
                $ingredients = $product->ingredients()->with('stock')->get();

                // Guard: food product must have a recipe defined
                if ($ingredients->isEmpty()) {
                    return response()->json([
                        'message' => "Product '{$product->name}' is of type food but has no recipe (ingredients) defined. Please define the recipe before fulfilling this order.",
                        'hint' => 'Define the ingredients of this food product via the recipe management section.',
                    ], 422);
                }

                $insufficientIngredients = [];
                foreach ($ingredients as $ingredient) {
                    $rawRequired = $ingredient->pivot->quantity * $diff;
                    $requiredQty = $this->convertQuantityToStockUnit($rawRequired, $ingredient->pivot->unit, $ingredient->stock?->unit);
                    $availableQty = $ingredient->stock ? $ingredient->stock->quantity : 0;
                    if ($availableQty < $requiredQty) {
                        $unit = $ingredient->stock?->unit ?? $ingredient->pivot->unit ?? 'piece';
                        $insufficientIngredients[] = [
                            'name' => $ingredient->name,
                            'required' => round($requiredQty, 3),
                            'available' => round($availableQty, 3),
                            'unit' => $unit,
                        ];
                    }
                }

                if (count($insufficientIngredients) > 0) {
                    $details = collect($insufficientIngredients)->map(fn($i) =>
                        "{$i['name']} : Insufficient stock — available {$i['available']} {$i['unit']}, required {$i['required']} {$i['unit']}"
                    )->implode("\n");
                    return response()->json([
                        'message' => 'Insufficient raw materials to fulfill this order.',
                        'stock_issues' => $insufficientIngredients,
                        'details' => $details,
                    ], 422);
                }
            } else {
                $availableStock = $product->stock ? $product->stock->quantity : 0;
                if ($diff > $availableStock) {
                    return response()->json([
                        'message' => 'Stock insuffisant pour ce produit. Quantité maximale disponible : ' . $availableStock,
                    ], 422);
                }
            }
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($item, $request, $diff, $internalOrder) {
            $item->update(['quantity_fulfilled' => $request->quantity_fulfilled]);

            // Deduct from stock if quantity increased
            if ($diff > 0 && $item->product) {
                $product = $item->product;
                if ($product->type === 'food') {
                    $ingredients = $product->ingredients()->with('stock')->get();
                    foreach ($ingredients as $ingredient) {
                        $rawRequired = $ingredient->pivot->quantity * $diff;
                        $requiredQty = $this->convertQuantityToStockUnit($rawRequired, $ingredient->pivot->unit, $ingredient->stock?->unit);
                        if ($requiredQty > 0 && $ingredient->stock) {
                            $this->fifoDeduction(
                                $ingredient->stock,
                                $requiredQty,
                                "Fulfill Order #{$internalOrder->id} ({$product->name})"
                            );
                        }
                    }
                } else {
                    if ($product->stock) {
                        $this->fifoDeduction($product->stock, $diff, 'Internal Order #'.$internalOrder->id);
                    }
                }
            }
        });

        // Auto-update order status based on fulfillment
        $order = $internalOrder->fresh()->load('items');
        $totalRequested = $order->items->sum('quantity_requested');
        $totalFulfilled = $order->items->sum('quantity_fulfilled');

        $newStatus = 'PARTIELLEMENT_DISPONIBLE';
        if ($totalFulfilled == 0) {
            $newStatus = 'NON_DISPONIBLE';
        } elseif ($totalFulfilled >= $totalRequested) {
            $newStatus = 'DISPONIBLE';
        }

        $order->update(['status' => $newStatus]);

        if ($oldStatus !== $newStatus) {
            $this->notifyOrderStatusChanged($order, $oldStatus);
        }

        return response()->json([
            'message' => 'Quantité mise à jour.',
            'order' => $order->load('items.product'),
        ]);
    }

    private function notifyOrderStatusChanged(InternalOrder $order, string $oldStatus): void
    {
        $message = "La commande interne #{$order->id} a changé de statut : {$oldStatus} → {$order->status}.";
        $data = ['order_id' => $order->id];

        // Notify all RESPONSABLE_FB users by role
        $fbUsers = User::whereHas('role', fn ($q) => $q->where('name', 'RESPONSABLE_FB'))->get();
        foreach ($fbUsers as $fbUser) {
            if ($fbUser->id !== Auth::id()) {
                Notification::create([
                    'user_id' => $fbUser->id,
                    'title' => 'Statut de commande mis à jour',
                    'message' => $message,
                    'type' => 'info',
                    'is_read' => false,
                    'data' => $data,
                ]);
            }
        }

        // Notify assignee (Chef Cuisine / Chef Magasin) if not current user
        if ($order->assigned_to && $order->assigned_to !== Auth::id()) {
            Notification::create([
                'user_id' => $order->assigned_to,
                'title' => 'Statut de commande mis à jour',
                'message' => $message,
                'type' => 'info',
                'is_read' => false,
                'data' => $data,
            ]);
        }
    }

    public function destroy(InternalOrder $internalOrder): JsonResponse
    {
        if (! $this->authorizeOrder($internalOrder)) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        $internalOrder->delete();

        return response()->json(['message' => 'Commande interne supprimée.']);
    }

    private function authorizeOrder(InternalOrder $order): bool
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user) {
            return false;
        }
        if ($user->role?->name === 'SUPER_ADMIN') {
            return true;
        }
        if ($order->created_by === $user->id) {
            return true;
        }
        if ($order->assigned_to === $user->id) {
            return true;
        }
        if ($user->role?->name === 'RESPONSABLE_FB') {
            $pdvIds = $user->pdvsResponsable()->pluck('id')->toArray();
            if (in_array($order->pdv_id, $pdvIds)) {
                return true;
            }
        }
        if ($user->role?->name === 'CHEF_MAGASIN') {
            return $order->type === 'commercial';
        }
        if ($user->role?->name === 'CHEF_CUISINE') {
            return $order->type === 'food';
        }
        if ($user->role?->name === 'RESPONSABLE_ACHAT') {
            return $order->type === 'commercial';
        }

        return false;
    }
}
