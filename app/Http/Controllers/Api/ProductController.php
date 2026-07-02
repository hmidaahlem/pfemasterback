<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('category', 'creator', 'stock', 'hygieneReports');

        $user = auth()->user();
        $role = $user->role?->name;

        // Chef Magasin: only COMMERCIAL and MATIERE_PREMIERE
        if ($role === 'CHEF_MAGASIN') {
            $query->whereIn('type', ['commercial', 'matiere_premiere']);
        }

        // Chef Cuisine: only FOOD and PLAT (unless requesting all types for recipe builder)
        if ($role === 'CHEF_CUISINE') {
            if (!filter_var($request->get('all_types', false), FILTER_VALIDATE_BOOLEAN)) {
                $query->whereIn('type', ['food', 'plat']);
            }
        }

        // Responsable Achat: only COMMERCIAL and MATIERE_PREMIERE
        if ($role === 'RESPONSABLE_ACHAT') {
            $query->whereIn('type', ['commercial', 'matiere_premiere']);
        }

        // Responsable Hygiene: only approved FOOD and PLAT products (chef-made items, not commercial goods)
        if ($role === 'RESPONSABLE_HYGIENE') {
            $query->whereIn('type', ['food', 'plat'])
                  ->where('approval_status', 'approved');
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('approval_status')) {
            $query->where('approval_status', $request->approval_status);
        }

        if ($request->boolean('no_paginate')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        $role = $user->role?->name;

        // Chef Magasin: simplified validation (no price, expiration, allergens)
        if ($role === 'CHEF_MAGASIN') {
            $request->validate([
                'name' => 'required|string|max:255|unique:products,name',
                'description' => 'nullable|string',
                'type' => 'required|in:commercial,matiere_premiere',
                'category_id' => 'required|exists:categories,id',
                'image' => 'nullable|image|max:2048',
                'unit' => 'sometimes|string|in:piece,kg,g,liter,ml',
                'min_threshold' => 'sometimes|numeric|min:0',
                'is_active' => 'sometimes|boolean',
                'usage_status' => 'sometimes|in:IN_USE,NOT_IN_USE,OUT_OF_STOCK',
            ]);
        } elseif ($role === 'CHEF_CUISINE') {
            $request->validate([
                'name' => 'required|string|max:255|unique:products,name',
                'description' => 'nullable|string',
                'type' => 'required|in:food,plat',
                'category_id' => 'nullable|exists:categories,id',
                'image' => 'nullable|image|max:2048',
                'quantity_per_batch' => 'required|integer|min:1',
                'ingredients' => 'required|array|min:1',
                'ingredients.*.product_id' => 'required|exists:products,id',
                'ingredients.*.quantity' => 'required|numeric|min:0.01',
                'ingredients.*.unit' => 'sometimes|string',
                'unit' => 'sometimes|string|in:piece,kg,g,liter,ml',
                'min_threshold' => 'sometimes|numeric|min:0',
                'is_active' => 'sometimes|boolean',
                'usage_status' => 'sometimes|in:IN_USE,NOT_IN_USE,OUT_OF_STOCK',
            ]);
        } else {
            $request->validate([
                'name' => 'required|string|max:255|unique:products,name',
                'description' => 'nullable|string',
                'type' => 'required|in:commercial,matiere_premiere,food,plat',
                'category_id' => 'required_unless:type,food,plat|exists:categories,id',
                'price' => 'nullable|numeric|min:0',
                'image' => 'nullable|image|max:2048',
                'allergens' => 'nullable|array',
                'expiration_date' => 'nullable|date',
                'unit' => 'sometimes|string|in:piece,kg,g,liter,ml',
                'min_threshold' => 'sometimes|numeric|min:0',
                'is_active' => 'sometimes|boolean',
                'usage_status' => 'sometimes|in:IN_USE,NOT_IN_USE,OUT_OF_STOCK',
            ]);
        }

        // CHECK TYPE vs CATEGORY
        if ($request->filled('category_id')) {
            $category = Category::find($request->category_id);
            if ($category && $category->type !== $request->type) {
                return response()->json([
                    'message' => 'Le type du produit ne correspond pas à la catégorie.'
                ], 422);
            }
        } elseif (!in_array($request->type, ['food', 'plat'])) {
            return response()->json([
                'message' => 'La catégorie est obligatoire pour ce type de produit.'
            ], 422);
        }

        $data = $request->only(['name', 'description', 'type']);
        $data['created_by'] = $user->id;
        // CHEF_CUISINE, RESPONSABLE_ACHAT, and SUPER_ADMIN products are auto-approved during creation
        $data['approval_status'] = in_array($role, ['CHEF_CUISINE', 'RESPONSABLE_ACHAT', 'SUPER_ADMIN']) ? 'approved' : 'pending';

        if ($request->type === 'plat') {
            $data['quantity_per_batch'] = null;
            // No usage_status needed for plat
        } else {
            if ($request->has('is_active')) {
                $data['is_active'] = $request->boolean('is_active');
                $data['usage_status'] = $data['is_active'] ? 'IN_USE' : 'NOT_IN_USE';
                if ($request->has('usage_status') && $request->usage_status === 'OUT_OF_STOCK' && !$data['is_active']) {
                    $data['usage_status'] = 'OUT_OF_STOCK';
                }
            } elseif ($request->has('usage_status')) {
                $data['usage_status'] = $request->usage_status;
                $data['is_active'] = $request->usage_status === 'IN_USE';
            }

            if ($request->has('quantity_per_batch')) {
                $data['quantity_per_batch'] = $request->quantity_per_batch;
            }
        }

        if ($request->filled('category_id')) {
            $data['category_id'] = $request->category_id;
        } elseif ($request->type === 'food') {
            // Auto-assign first food category for food products
            $foodCategory = Category::where('type', 'food')->first();
            if ($foodCategory) {
                $data['category_id'] = $foodCategory->id;
            }
        }

        // Only add price/allergens/expiration if role allows it
        if (!in_array($role, ['CHEF_MAGASIN', 'CHEF_CUISINE'])) {
            if ($request->has('price')) $data['price'] = $request->price;
            if ($request->has('allergens')) $data['allergens'] = $request->allergens;
            if ($request->has('expiration_date')) $data['expiration_date'] = $request->expiration_date;
        }

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        if ($request->has('ingredients')) {
            $approvalIssues = [];
            foreach ($request->ingredients as $ingredient) {
                $ingredientProduct = Product::find($ingredient['product_id']);
                if (!$ingredientProduct) {
                    return response()->json([
                        'message' => 'L\'ingrédient ID ' . $ingredient['product_id'] . ' est introuvable.',
                    ], 422);
                }

                // Check that ingredient is APPROVED
                if ($ingredientProduct->approval_status !== 'approved') {
                    $approvalIssues[] = $ingredientProduct->name;
                }
            }

            if (count($approvalIssues) > 0) {
                return response()->json([
                    'message' => 'Les ingrédients doivent être approuvés.',
                    'unapproved_ingredients' => $approvalIssues,
                ], 422);
            }
        }

        $product = DB::transaction(function () use ($data, $request) {
            $product = Product::create($data);

            // Sync ingredients recipe if provided
            if ($request->has('ingredients')) {
                $syncData = [];
                foreach ($request->ingredients as $ingredient) {
                    $syncData[$ingredient['product_id']] = [
                        'quantity' => $ingredient['quantity'],
                        'unit' => $ingredient['unit'] ?? 'piece',
                    ];
                }
                $product->ingredients()->sync($syncData);
            }

            // Auto-create stock only for non-plat products
            if ($request->type !== 'plat') {
                $initialQuantity = 0;
                if ($request->type === 'food' && $request->has('quantity_per_batch')) {
                    $initialQuantity = $request->quantity_per_batch;
                }

                $product->stock()->create([
                    'quantity' => $initialQuantity,
                    'min_threshold' => $request->has('min_threshold') ? $request->min_threshold : 40,
                    'unit' => $request->unit ?? 'piece',
                ]);
            }

            return $product;
        });

        return response()->json([
            'message' => 'Produit créé.',
            'product' => $product->load('category', 'stock', 'ingredients'),
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(
            $product->load('category', 'creator', 'stock', 'ingredients', 'hygieneReports.inspector')
        );
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        \Illuminate\Support\Facades\Log::info('Update Product Payload: ', $request->all());
        $user = auth()->user();
        $role = $user->role?->name;

        // Prevent CHEF_MAGASIN from modifying products created by RESPONSABLE_ACHAT
        if ($role === 'CHEF_MAGASIN') {
            $creatorRole = $product->creator?->role?->name;
            if ($creatorRole === 'RESPONSABLE_ACHAT') {
                return response()->json([
                    'message' => 'Action non autorisée. Vous ne pouvez pas modifier un produit créé par le Responsable Achat.'
                ], 403);
            }
        }

        // Chef Magasin: can only update usage_status or image for approved/rejected products
        if ($role === 'CHEF_MAGASIN' && $product->approval_status !== 'pending') {
            $request->validate([
                'usage_status' => 'sometimes|in:IN_USE,NOT_IN_USE,OUT_OF_STOCK',
                'image' => 'nullable|image|max:2048',
            ]);

            $data = $request->only(['usage_status']);

            if ($request->hasFile('image')) {
                if ($product->image && \Illuminate\Support\Facades\Storage::disk('public')->exists($product->image)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image);
                }
                $data['image'] = $request->file('image')->store('products', 'public');
            }

            $oldStatus = $product->usage_status;
            $product->update($data);

            $title = 'Produit modifié';
            $msg = "Le produit \"{$product->name}\" a été modifié par le Chef Magasin.";
            if ($request->has('usage_status') && $request->usage_status === 'OUT_OF_STOCK' && $oldStatus !== 'OUT_OF_STOCK') {
                $title = 'Rupture de stock';
                $msg = "Le produit \"{$product->name}\" est désormais en rupture de stock (OUT_OF_STOCK).";
            }

            $this->notifyResponsableAchat(
                $title,
                $msg,
                $request->usage_status === 'OUT_OF_STOCK' ? 'warning' : 'info',
                ['product_id' => $product->id]
            );

            return response()->json([
                'message' => 'Statut d\'utilisation mis à jour.',
                'product' => $product->fresh()->load('category', 'stock'),
            ]);
        }

        // Chef Magasin: can only update if product is pending
        if ($role === 'CHEF_MAGASIN') {

            // Pending: can update basic fields, image
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|nullable|string',
                'category_id' => 'sometimes|nullable|exists:categories,id',
                'image' => 'nullable|image|max:2048',
                'unit' => 'sometimes|string|in:piece,kg,g,liter,ml',
            ]);

            $data = $request->only(['name', 'description', 'category_id']);
        } elseif ($role === 'CHEF_CUISINE') {
            // Chef Cuisine: can update name, description, recipe, quantity_per_batch, is_active
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|nullable|string',
                'image' => 'nullable|image|max:2048',
                'quantity_per_batch' => 'sometimes|integer|min:1',
                'ingredients' => 'sometimes|array|min:1',
                'ingredients.*.product_id' => 'sometimes|exists:products,id',
                'ingredients.*.quantity' => 'sometimes|numeric|min:0.01',
                'ingredients.*.unit' => 'sometimes|string',
                'unit' => 'sometimes|string|in:piece,kg,g,liter,ml',
                'is_active' => 'sometimes|boolean',
                'usage_status' => 'sometimes|in:IN_USE,NOT_IN_USE,OUT_OF_STOCK',
            ]);

            $data = $request->only(['name', 'description']);
            if ($request->has('quantity_per_batch') && $product->type !== 'plat') {
                $data['quantity_per_batch'] = $request->quantity_per_batch;
            }

            if ($request->has('is_active')) {
                $data['is_active'] = $request->boolean('is_active');
                $data['usage_status'] = $data['is_active'] ? 'IN_USE' : 'NOT_IN_USE';
                if ($request->has('usage_status') && $request->usage_status === 'OUT_OF_STOCK' && !$data['is_active']) {
                    $data['usage_status'] = 'OUT_OF_STOCK';
                }
            } elseif ($request->has('usage_status')) {
                $data['usage_status'] = $request->usage_status;
                $data['is_active'] = $request->usage_status === 'IN_USE';
            }


            if ($request->has('ingredients')) {
                $approvalIssues = [];
                foreach ($request->ingredients as $ingredient) {
                    $ingredientProduct = Product::find($ingredient['product_id']);
                    if (!$ingredientProduct) {
                        return response()->json([
                            'message' => 'L\'ingrédient ID ' . $ingredient['product_id'] . ' est introuvable.',
                        ], 422);
                    }
                    if ($ingredientProduct->approval_status !== 'approved') {
                        $approvalIssues[] = $ingredientProduct->name;
                    }
                }

                if (count($approvalIssues) > 0) {
                    return response()->json([
                        'message' => 'Les ingrédients doivent être approuvés.',
                        'unapproved_ingredients' => $approvalIssues,
                    ], 422);
                }
            }
        } else {
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|nullable|string',
                'type' => 'sometimes|in:commercial,matiere_premiere,food',
                'category_id' => 'sometimes|nullable|exists:categories,id',
                'price' => 'sometimes|numeric|min:0',
                'image' => 'sometimes|nullable|image|max:2048',
                'is_active' => 'sometimes|boolean',
                'usage_status' => 'sometimes|in:IN_USE,NOT_IN_USE,OUT_OF_STOCK',
                'allergens' => 'sometimes|nullable|array',
                'expiration_date' => 'sometimes|nullable|date',
                'unit' => 'sometimes|string|in:piece,kg,g,liter,ml',
            ]);

            $data = $request->only([
                'name', 'description', 'type', 'category_id', 'price',
                'is_active', 'allergens', 'expiration_date',
            ]);

            if ($request->has('is_active')) {
                $data['is_active'] = $request->boolean('is_active');
                $data['usage_status'] = $data['is_active'] ? 'IN_USE' : 'NOT_IN_USE';
                if ($request->has('usage_status') && $request->usage_status === 'OUT_OF_STOCK' && !$data['is_active']) {
                    $data['usage_status'] = 'OUT_OF_STOCK';
                }
            } elseif ($request->has('usage_status')) {
                $data['usage_status'] = $request->usage_status;
                $data['is_active'] = $request->usage_status === 'IN_USE';
            }
        }

        if ($request->hasFile('image')) {
            if ($product->image && Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        \Illuminate\Support\Facades\Log::info('Data array before update:', $data);
        $product->update($data);

        if ($request->has('unit') && $product->stock) {
            $product->stock->update(['unit' => $request->unit]);
        }

        // Sync recipe ingredients
        if ($request->has('ingredients')) {
            $syncData = [];
            foreach ($request->ingredients as $ingredient) {
                $syncData[$ingredient['product_id']] = [
                    'quantity' => $ingredient['quantity'],
                    'unit' => $ingredient['unit'] ?? 'piece',
                ];
            }
            $product->ingredients()->sync($syncData);
        }

        // Notify Responsable Achat when product is modified (by any role except Responsable Achat)
        if ($role !== 'RESPONSABLE_ACHAT') {
            $this->notifyResponsableAchat(
                'Produit modifié',
                "Le produit \"{$product->name}\" a été modifié.",
                'info',
                ['product_id' => $product->id]
            );
        }

        // Notify Chef Magasin when product is modified by Responsable Achat
        if ($role === 'RESPONSABLE_ACHAT') {
            $chefMagasinUsers = User::whereHas('role', fn($q) =>
                $q->where('name', 'CHEF_MAGASIN'))->get();
            foreach ($chefMagasinUsers as $chefUser) {
                Notification::create([
                    'user_id' => $chefUser->id,
                    'title'   => 'Produit modifié',
                    'message' => "Responsable Achat a modifié: {$product->name}",
                    'type'    => 'info',
                    'is_read' => false,
                ]);
            }
        }

        return response()->json([
            'message' => 'Produit mis à jour.',
            'product' => $product->fresh()->load('category', 'stock'),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $productName = $product->name;
        $user = auth()->user();
        $role = $user->role?->name;

        if ($role === 'CHEF_MAGASIN') {
            $creatorRole = $product->creator?->role?->name;
            if ($creatorRole === 'RESPONSABLE_ACHAT') {
                return response()->json([
                    'message' => 'Action non autorisée. Vous ne pouvez pas supprimer un produit créé par le Responsable Achat.'
                ], 403);
            }
        }

        $product->delete();

        // Notify Responsable Achat on deletion
        if ($role !== 'RESPONSABLE_ACHAT') {
            $this->notifyResponsableAchat(
                'Produit supprimé',
                "Le produit \"{$productName}\" a été supprimé.",
                'warning',
                ['product_name' => $productName]
            );
        }

        // Notify Chef Magasin if deletion was by Responsable Achat
        if ($role === 'RESPONSABLE_ACHAT') {
            $this->notifyChefMagasin(
                'Produit supprimé',
                "Le produit \"{$productName}\" a été supprimé par le Responsable Achat.",
                'warning',
                ['product_name' => $productName]
            );
        }

        return response()->json(['message' => 'Produit supprimé.']);
    }

    public function toggleActive(Product $product): JsonResponse
    {
        $newActive = !$product->is_active;
        $product->update([
            'is_active' => $newActive,
            'usage_status' => $newActive ? 'IN_USE' : 'NOT_IN_USE'
        ]);

        return response()->json([
            'message' => $product->is_active ? 'Produit activé.' : 'Produit désactivé.',
            'product' => $product,
        ]);
    }

    public function approveProduct(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'approval_status' => 'required|in:approved,rejected',
            'price' => 'nullable|numeric|min:0',
        ]);

        $data = ['approval_status' => $request->approval_status];

        // Add price when approving
        if ($request->approval_status === 'approved' && $request->has('price')) {
            $data['price'] = $request->price;
        }

        $product->update($data);

        // Notify Chef Magasin
        $status = $request->approval_status === 'approved' ? 'approuvé' : 'rejeté';
        $this->notifyChefMagasin(
            'Produit ' . $status,
            "Votre produit \"{$product->name}\" a été {$status} par le Responsable Achat.",
            $request->approval_status === 'approved' ? 'success' : 'warning',
            ['product_id' => $product->id]
        );

        return response()->json([
            'message' => 'Statut du produit mis à jour.',
            'product' => $product->fresh(),
        ]);
    }

    // Recipe management for food products
    public function setRecipe(Request $request, Product $product): JsonResponse
    {
        if (!in_array($product->type, ['food', 'plat'])) {
            return response()->json(['message' => 'Seuls les produits food et plat peuvent avoir une recette.'], 422);
        }

        $request->validate([
            'ingredients' => 'required|array|min:1',
            'ingredients.*.product_id' => 'required|exists:products,id',
            'ingredients.*.quantity' => 'required|numeric|min:0.01',
            'ingredients.*.unit' => 'sometimes|string',
        ]);

        // Check stock and approval status for each ingredient
        $approvalIssues = [];
        foreach ($request->ingredients as $ingredient) {
            $ingredientProduct = Product::find($ingredient['product_id']);
            if (!$ingredientProduct) {
                return response()->json([
                    'message' => 'L\'ingrédient ID ' . $ingredient['product_id'] . ' est introuvable.',
                ], 422);
            }
            if ($ingredientProduct->approval_status !== 'approved') {
                $approvalIssues[] = $ingredientProduct->name;
            }
        }

        if (count($approvalIssues) > 0) {
            return response()->json([
                'message' => 'Les ingrédients doivent être approuvés.',
                'unapproved_ingredients' => $approvalIssues,
            ], 422);
        }

        $syncData = [];
        foreach ($request->ingredients as $ingredient) {
            $syncData[$ingredient['product_id']] = [
                'quantity' => $ingredient['quantity'],
                'unit' => $ingredient['unit'] ?? 'piece',
            ];
        }

        $product->ingredients()->sync($syncData);

        return response()->json([
            'message' => 'Recette mise à jour.',
            'product' => $product->load('ingredients'),
        ]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(Category::all());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
            'type' => 'required|in:commercial,matiere_premiere,food,plat',
            'code' => 'nullable|string|max:255|unique:categories,code',
        ]);

        $code = $request->code;
        if (empty($code)) {
            $prefix = match($request->type) {
                'commercial' => 'COM',
                'matiere_premiere' => 'MAT',
                'food' => 'FOOD',
                'plat' => 'PLAT',
            };
            $baseCode = substr($prefix . '_' . strtoupper(\Illuminate\Support\Str::slug($request->name, '_')), 0, 240);
            $code = $baseCode;
            // Ensure unique code
            $counter = 1;
            while (Category::where('code', $code)->exists()) {
                $code = $baseCode . '_' . $counter++;
            }
        }

        $category = Category::create([
            'name' => $request->name,
            'type' => $request->type,
            'code' => $code,
        ]);

        return response()->json([
            'message' => 'Catégorie créée avec succès.',
            'category' => $category
        ], 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255|unique:categories,name,' . $category->id,
            'code' => 'nullable|string|max:255|unique:categories,code,' . $category->id,
        ]);

        $category->update($request->only(['name', 'code']));

        return response()->json([
            'message' => 'Catégorie mise à jour.',
            'category' => $category->fresh()
        ]);
    }

    public function destroyCategory(Category $category): JsonResponse
    {
        if ($category->products()->count() > 0) {
            return response()->json([
                'message' => 'Impossible de supprimer une catégorie qui contient des produits.'
            ], 422);
        }
        $category->delete();
        return response()->json(['message' => 'Catégorie supprimée.']);
    }

    public function hygieneUpdate(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'allergens'       => 'nullable|array',
            'allergens.*'     => 'string|max:100',
            'expiration_date' => 'nullable|date',
        ]);

        $data = [];

        if ($request->has('allergens')) {
            $data['allergens'] = $request->allergens ?? [];
        }

        // Use exists() not has() — allows saving null to clear the expiration date
        if ($request->exists('expiration_date')) {
            $data['expiration_date'] = $request->expiration_date ?: null;
        }

        $product->update($data);

        return response()->json([
            'message' => 'Informations hygiène mises à jour.',
            'product' => $product->fresh()->load('category', 'stock'),
        ]);
    }

    private function notifyResponsableAchat(string $title, string $message, string $type, array $data = []): void
    {
        $users = User::whereHas('role', fn($q) => $q->where('name', 'RESPONSABLE_ACHAT'))->get();
        foreach ($users as $user) {
            Notification::create([
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'is_read' => false,
                'data' => $data,
            ]);
        }
    }

    private function notifyChefMagasin(string $title, string $message, string $type, array $data = []): void
    {
        $users = User::whereHas('role', fn($q) => $q->where('name', 'CHEF_MAGASIN'))->get();
        foreach ($users as $user) {
            Notification::create([
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'is_read' => false,
                'data' => $data,
            ]);
        }
    }
}
