<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Traits\FifoStockTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    use FifoStockTrait;

    public function index(Request $request): JsonResponse
    {
        $query = Stock::with('product.category');

        $user = auth()->user();
        $role = $user->role?->name;

        // Stock should only show approved products
        $query->whereHas('product', fn ($q) => $q->where('approval_status', 'approved'));

        // Chef Magasin: only see stocks of COMMERCIAL and MATIERE_PREMIERE products
        if ($role === 'CHEF_MAGASIN') {
            $query->whereHas('product', fn ($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']));
        }

        if ($role === 'CHEF_CUISINE') {
            $query->whereHas('product', fn ($q) => $q->where('type', 'matiere_premiere'));
        }

        if ($role === 'RESPONSABLE_FB') {
            $query->whereHas('product', fn ($q) => $q->whereIn('type', ['commercial', 'food']));
        }

        if ($request->boolean('low_stock')) {
            $query->whereColumn('quantity', '<=', 'min_threshold');
        }

        $paginated = $query->paginate(15);

        // Calculate daily stats (filtered by role if necessary)
        $dailyInputsQuery = StockMovement::where('type', 'in')->whereDate('created_at', today());
        $dailyOutputsQuery = StockMovement::where('type', 'out')->whereDate('created_at', today());
        $totalStockQuery = Stock::whereHas('product', fn ($q) => $q->where('approval_status', 'approved'));
        $criticalCountQuery = Stock::whereHas('product', fn ($q) => $q->where('approval_status', 'approved'))
            ->whereColumn('quantity', '<=', 'min_threshold');

        if ($role === 'CHEF_MAGASIN') {
            $dailyInputsQuery->whereHas('stock.product', fn ($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']));
            $dailyOutputsQuery->whereHas('stock.product', fn ($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']));
            $totalStockQuery->whereHas('product', fn ($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']));
            $criticalCountQuery->whereHas('product', fn ($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']));
        } elseif ($role === 'CHEF_CUISINE') {
            $dailyInputsQuery->whereHas('stock.product', fn ($q) => $q->where('type', 'matiere_premiere'));
            $dailyOutputsQuery->whereHas('stock.product', fn ($q) => $q->where('type', 'matiere_premiere'));
            $totalStockQuery->whereHas('product', fn ($q) => $q->where('type', 'matiere_premiere'));
            $criticalCountQuery->whereHas('product', fn ($q) => $q->where('type', 'matiere_premiere'));
        } elseif ($role === 'RESPONSABLE_FB') {
            $dailyInputsQuery->whereHas('stock.product', fn ($q) => $q->whereIn('type', ['commercial', 'food']));
            $dailyOutputsQuery->whereHas('stock.product', fn ($q) => $q->whereIn('type', ['commercial', 'food']));
            $totalStockQuery->whereHas('product', fn ($q) => $q->whereIn('type', ['commercial', 'food']));
            $criticalCountQuery->whereHas('product', fn ($q) => $q->whereIn('type', ['commercial', 'food']));
        }

        $dailyInputs = $dailyInputsQuery->sum('quantity');
        $dailyOutputs = $dailyOutputsQuery->sum('quantity');
        $totalStock = $totalStockQuery->sum('quantity');
        $criticalCount = $criticalCountQuery->count();

        return response()->json([
            'data' => $paginated->items(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'kpis' => [
                'total_stock' => $totalStock,
                'daily_inputs' => $dailyInputs,
                'daily_outputs' => $dailyOutputs,
                'critical_count' => $criticalCount,
            ],
        ]);
    }

    public function show(Stock $stock): JsonResponse
    {
        return response()->json(
            $stock->load('product.category', 'movements.user')
        );
    }

    public function addMovement(Request $request, Stock $stock): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:in,out,adjustment',
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
            'expiration_date' =>'nullable|date',
        ]);

        $product = $stock->product;

        if ($product && $product->type === 'food') {
            if ($request->type === 'in') {
                return response()->json([
                    'message' => 'Stock movement "in" is not allowed for food products. Food is prepared on-demand via internal orders (recipe x quantity).',
                    'hint' => 'Create an internal order of type food to trigger automatic ingredient deduction.',
                ], 422);
            }

            if ($request->type === 'out') {
                return response()->json([
                    'message' => 'Stock movement "out" is not allowed for food products. Deduction is handled automatically when an internal order is fulfilled.',
                    'hint' => 'Food stock is managed exclusively via internal orders.',
                ], 422);
            }
            // Allow 'adjustment' only — for admin manual corrections
        }

        if ($request->type === 'out' && $request->quantity > $stock->quantity) {
            return response()->json([
                'message' => 'Stock insuffisant. La quantité disponible est de '.$stock->quantity.' '.($stock->unit ?? 'unité(s)').'.',
            ], 422);
        }

        DB::transaction(function () use ($stock, $request) {
            if ($request->type === 'out') {
                // FIFO: deduct from oldest/soonest-expiring batches first
                $this->fifoDeduction($stock, (float) $request->quantity, $request->reason);
            } else {
                $stock->movements()->create([
                    'type' => $request->type,
                    'quantity' => $request->quantity,
                    'reason' => $request->reason,
                    'expiration_date' => $request->expiration_date,
                    'user_id' => auth()->id(),
                ]);

                match ($request->type) {
                    'in' => $stock->increment('quantity', $request->quantity),
                    'adjustment' => $stock->update(['quantity' => $request->quantity]),
                    default => null,
                };
            }
        });

        $stock->refresh();

        // Alert if stock falls below threshold
        $this->notifyLowStockIfNecessary($stock);

        // Alert for near-expiration products
        $this->checkExpirationAlerts($stock);

        return response()->json([
            'message' => 'Mouvement de stock enregistré.',
            'stock' => $stock->fresh()->load('product'),
        ]);
    }

    public function movements(Stock $stock): JsonResponse
    {
        $movements = $stock->movements()
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($movements);
    }

    public function updateThreshold(Request $request, Stock $stock): JsonResponse
    {
        $request->validate([
            'min_threshold' => 'required|numeric|min:0',
        ]);

        $stock->update(['min_threshold' => $request->min_threshold]);

        return response()->json([
            'message' => 'Seuil mis à jour.',
            'stock' => $stock,
        ]);
    }

    public function lowStockAlerts(): JsonResponse
    {
        $user = auth()->user();
        $role = $user->role?->name;

        $query = Stock::with('product')
            ->whereHas('product', fn ($q) => $q->where('approval_status', 'approved'))
            ->whereColumn('quantity', '<=', 'min_threshold');

        if ($role === 'CHEF_MAGASIN') {
            $query->whereHas('product', fn ($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']));
        } elseif ($role === 'CHEF_CUISINE') {
            $query->whereHas('product', fn ($q) => $q->where('type', 'matiere_premiere'));
        } elseif ($role === 'RESPONSABLE_FB') {
            $query->whereHas('product', fn ($q) => $q->whereIn('type', ['commercial', 'food']));
        }

        $lowStocks = $query->get();

        return response()->json($lowStocks);
    }

    public function expiredProducts(): JsonResponse
    {
        $user = auth()->user();
        $role = $user->role?->name;

        $query = StockMovement::where('type', 'in')
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<', now())
            ->where('quantity', '>', 0)
            ->with('stock.product');

        if ($role === 'CHEF_MAGASIN') {
            $query->whereHas('stock.product', fn($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']));
        } elseif ($role === 'CHEF_CUISINE') {
            $query->whereHas('stock.product', fn($q) => $q->where('type', 'matiere_premiere'));
        } elseif ($role === 'RESPONSABLE_FB') {
            $query->whereHas('stock.product', fn($q) => $q->whereIn('type', ['commercial', 'food']));
        }

        return response()->json($query->get());
    }

    private function checkExpirationAlerts(Stock $stock): void
    {
        $nearExpiry = StockMovement::where('stock_id', $stock->id)
            ->where('type', 'in')
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<=', now()->addDays(7))
            ->where('expiration_date', '>=', now())
            ->exists();

        if ($nearExpiry) {
            $productName = $stock->product?->name ?? 'Produit';
            $users = User::whereHas('role', fn ($q) => $q->whereIn('name', ['CHEF_MAGASIN', 'RESPONSABLE_HYGIENE']))->get();

            foreach ($users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Produit proche expiration',
                    'message' => "Le produit \"{$productName}\" expire bientôt.",
                    'type' => 'alert',
                    'is_read' => false,
                    'data' => ['stock_id' => $stock->id],
                ]);
            }
        }
    }
}
