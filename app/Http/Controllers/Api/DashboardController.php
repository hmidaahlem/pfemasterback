<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalOrder;
use App\Models\Menu;
use App\Models\Product;
use App\Models\PurchaseNeed;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $role = $user->role?->name;

        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        $lowStockQuery = Stock::whereColumn('quantity', '<=', 'min_threshold');
        $expiredQuery = Product::where('expiration_date', '<', now())->where('is_active', true);

        if ($role === 'RESPONSABLE_FB') {
            $lowStockQuery->whereHas('product', fn($q) => $q->whereIn('type', ['commercial', 'food']));
            $expiredQuery->whereIn('type', ['commercial', 'food']);
        } elseif ($role === 'CHEF_MAGASIN') {
            $lowStockQuery->whereHas('product', fn($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']));
            $expiredQuery->whereIn('type', ['commercial', 'matiere_premiere']);
        } elseif ($role === 'CHEF_CUISINE') {
            $lowStockQuery->whereHas('product', fn($q) => $q->where('type', 'matiere_premiere'));
            $expiredQuery->whereIn('type', ['matiere_premiere']);
        }

        $lowStockCount = $lowStockQuery->count();
        $expiredCount = $expiredQuery->count();

        $activeUsers = User::where('status', 'active')->count();

        $pendingOrders = InternalOrder::where('status', 'EN_ATTENTE')->count();
        $processedToday = InternalOrder::whereDate('updated_at', today())
            ->where('status', 'DISPONIBLE')->count();
        $delayedOrders = InternalOrder::where('delivery_date', '<', now())
            ->whereNotIn('status', ['DISPONIBLE'])->count();
        $kitchenLoad = InternalOrder::where('type', 'food')
            ->where('status', 'EN_ATTENTE')->count();
        $warehouseLoad = InternalOrder::where('type', 'commercial')
            ->where('status', 'EN_ATTENTE')->count();

        // ─── GASPILLAGE (WASTE) KPIs ───
        $wasteMovementsQuery = StockMovement::where('type', 'out')
            ->where(function ($q) {
                $q->where('reason', 'LIKE', '%expir%')
                    ->orWhere('reason', 'LIKE', '%waste%')
                    ->orWhere('reason', 'LIKE', '%gaspill%')
                    ->orWhere('reason', 'LIKE', '%perte%');
            })
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        $expiredBatchesQuery = StockMovement::where('type', 'in')
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<', now())
            ->where('quantity', '>', 0);

        if ($role === 'RESPONSABLE_FB') {
            $wasteMovementsQuery->whereHas('stock.product', fn($q) => $q->whereIn('type', ['commercial', 'food']));
            $expiredBatchesQuery->whereHas('stock.product', fn($q) => $q->whereIn('type', ['commercial', 'food']));
        } elseif ($role === 'CHEF_MAGASIN') {
            $wasteMovementsQuery->whereHas('stock.product', fn($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']));
            $expiredBatchesQuery->whereHas('stock.product', fn($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']));
        } elseif ($role === 'CHEF_CUISINE') {
            $wasteMovementsQuery->whereHas('stock.product', fn($q) => $q->where('type', 'matiere_premiere'));
            $expiredBatchesQuery->whereHas('stock.product', fn($q) => $q->where('type', 'matiere_premiere'));
        }

        $wasteMovements = $wasteMovementsQuery->sum('quantity');
        $expiredBatches = $expiredBatchesQuery->sum('quantity');
        $totalWaste = $wasteMovements + $expiredBatches;

        $wasteTrendQuery = StockMovement::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(quantity) as total')
        )
            ->where('type', 'out')
            ->where(function ($q) {
                $q->where('reason', 'LIKE', '%expir%')
                    ->orWhere('reason', 'LIKE', '%waste%')
                    ->orWhere('reason', 'LIKE', '%perte%')
                    ->orWhere('reason', 'LIKE', '%gaspill%');
            })
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        if ($role === 'RESPONSABLE_FB') {
            $wasteTrendQuery->whereHas('stock.product', fn($q) => $q->whereIn('type', ['commercial', 'food']));
        } elseif ($role === 'CHEF_MAGASIN') {
            $wasteTrendQuery->whereHas('stock.product', fn($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']));
        } elseif ($role === 'CHEF_CUISINE') {
            $wasteTrendQuery->whereHas('stock.product', fn($q) => $q->where('type', 'matiere_premiere'));
        }

        $wasteTrend = $wasteTrendQuery->groupBy('date')
            ->orderBy('date')
            ->get();

        // ─── ROLE-SPECIFIC METRICS ───
        $roleData = [];

        if ($role === 'CHEF_MAGASIN') {
            $roleData['recent_movements'] = StockMovement::with('stock.product')
                ->whereHas('stock.product', fn($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']))
                ->latest()->limit(5)->get();
            $roleData['critical_products_list'] = Stock::with('product.category')
                ->whereHas('product', fn($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']))
                ->whereColumn('quantity', '<=', 'min_threshold')->limit(5)->get();
            $roleData['expired_batches_list'] = StockMovement::with('stock.product')
                ->where('type', 'in')->whereNotNull('expiration_date')
                ->where('expiration_date', '<', now())->where('quantity', '>', 0)
                ->whereHas('stock.product', fn($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']))
                ->limit(5)->get();
            $roleData['total_stock_qty'] = Stock::whereHas('product', fn($q) => $q->whereIn('type', ['commercial', 'matiere_premiere']))->sum('quantity');
        } elseif ($role === 'CHEF_CUISINE') {
            $totalIngredients = Product::where('type', 'matiere_premiere')
                ->where('approval_status', 'approved')
                ->count();
            $availableIngredients = Product::where('type', 'matiere_premiere')
                ->where('approval_status', 'approved')
                ->whereHas('stock', fn ($q) => $q->where('quantity', '>', 0))
                ->count();
            $availabilityRate = $totalIngredients > 0
                ? round(($availableIngredients / $totalIngredients) * 100)
                : 0;

            // ── Widget B: Weekly Menu Status ──
            $currentWeekMenu = Menu::with('items.product')
                ->where('start_date', '<=', now())
                ->where('end_date', '>=', now())
                ->first();
            $menuStatus = 'NON_PLANIFIE';
            if ($currentWeekMenu) {
                $menuStatus = $currentWeekMenu->status === 'VALIDE'
                    ? 'VALIDE'
                    : 'EN_ATTENTE_STOCK';
            }

            // ── Widget C: Order Volume ──
            $pendingToday = InternalOrder::where('status', 'EN_ATTENTE')
                ->whereDate('created_at', today())
                ->count();
            $completedToday = InternalOrder::whereIn('status', ['DISPONIBLE', 'PARTIELLEMENT_DISPONIBLE'])
                ->whereDate('updated_at', today())
                ->count();

            // ── Widget D1: Sous-Seuil Ingredients ──
            $sousSeuilIngredients = collect();
            if ($currentWeekMenu) {
                $menuProductIds = $currentWeekMenu->items()->pluck('product_id');
                $ingredientIds = DB::table('product_recipe')
                    ->whereIn('food_product_id', $menuProductIds)
                    ->pluck('ingredient_id');

                $sousSeuilIngredients = Stock::with('product')
                    ->whereIn('product_id', $ingredientIds)
                    ->whereColumn('quantity', '<=', 'min_threshold')
                    ->get()
                    ->map(fn ($s) => [
                        'ingredient_name' => $s->product->name,
                        'current_stock' => (float) $s->quantity,
                        'min_threshold' => (float) $s->min_threshold,
                        'unit' => $s->unit,
                    ]);
            }

            // ── Widget D2: Next-week menu check (read-only info; reminder is sent via scheduled job on Thursdays 20:00) ──
            $nextWeekStart = now()->addWeek()->startOfWeek()->toDateString();
            $nextWeekEnd = now()->addWeek()->endOfWeek()->toDateString();
            $nextWeekMenu = Menu::where('start_date', $nextWeekStart)
                ->where('end_date', $nextWeekEnd)
                ->first();

            $roleData['recipes_count'] = Product::whereIn('type', ['food', 'plat'])->where('approval_status', 'approved')->count();
            $roleData['active_menu'] = $currentWeekMenu;
            $roleData['critical_ingredients'] = Stock::with('product')
                ->whereHas('product', fn ($q) => $q->whereIn('type', ['matiere_premiere', 'commercial']))
                ->whereColumn('quantity', '<=', 'min_threshold')
                ->limit(5)
                ->get();

            // Widget A
            $roleData['availability_rate'] = $availabilityRate;
            $roleData['total_ingredients'] = $totalIngredients;
            $roleData['available_ingredients'] = $availableIngredients;

            // Widget B
            $roleData['current_menu_status'] = $menuStatus;
            $roleData['current_menu_name'] = $currentWeekMenu?->name;

            // Widget A — purchase need from latest menu
            $latestNeed = PurchaseNeed::with('items')
                ->whereHas('menu', fn ($q) => $q->where('created_by', $user->id))
                ->latest('generated_at')
                ->first();
            $roleData['latest_purchase_need'] = $latestNeed ? [
                'id' => $latestNeed->id,
                'total_items' => $latestNeed->items->count(),
                'items_requiring_restock' => $latestNeed->items->where('shortfall', '>', 0)->count(),
                'generated_at' => $latestNeed->generated_at,
            ] : null;

            // Widget C
            $roleData['pending_orders_today'] = $pendingToday;
            $roleData['completed_orders_today'] = $completedToday;

            // Widget D1
            $roleData['sous_seuil_ingredients'] = $sousSeuilIngredients;

            // Widget D2 — next week menu info (FIX 3: reminder push is now a scheduled job, not a runtime flag)
            $roleData['next_week_menu_planned'] = ! is_null($nextWeekMenu);
            $roleData['next_week_menu_name'] = $nextWeekMenu?->name;
        } elseif ($role === 'CAISSIER') {
            // Cashier no longer has sales
            $roleData['message'] = 'Bienvenue sur votre espace.';
        }

        // Fetch recent orders with items and products for the mobile dashboard
        $recentOrdersQuery = InternalOrder::with('items.product');
        if ($role === 'CHEF_MAGASIN') {
            $recentOrdersQuery->where('type', 'commercial');
        } elseif ($role === 'CHEF_CUISINE') {
            $recentOrdersQuery->where('type', 'food');
        } elseif ($role === 'RESPONSABLE_FB') {
            $recentOrdersQuery->whereIn('type', ['commercial', 'food']);
        }
        $recentOrders = $recentOrdersQuery->latest()->limit(5)->get();

        return response()->json([
            'low_stock_count' => $lowStockCount,
            'expired_products_count' => $expiredCount,

            // New metrics
            'active_users' => $activeUsers,
            'total_users' => $activeUsers, // Align with mobile model
            'pending_orders' => $pendingOrders,
            'processed_today' => $processedToday,
            'delayed_orders' => $delayedOrders,
            'kitchen_load' => $kitchenLoad,
            'warehouse_load' => $warehouseLoad,
            'total_waste' => $totalWaste,
            'waste_trend' => $wasteTrend,
            'recent_orders' => $recentOrders, // Align with mobile model

            // Role specific
            'role_specific' => $roleData,
        ]);
    }
}
