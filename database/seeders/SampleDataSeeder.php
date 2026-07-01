<?php

namespace Database\Seeders;

use App\Http\Controllers\Api\PurchaseNeedController;
use App\Models\Category;
use App\Models\Comment;
use App\Models\HygieneReport;
use App\Models\InternalOrder;
use App\Models\Menu;
use App\Models\Notification;
use App\Models\Planning;
use App\Models\PointDeVente;
use App\Models\Product;
use App\Models\Role;

use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@aeroserve.com')->firstOrFail();
        $pdv = PointDeVente::query()->first();

        $fbRole = Role::where('name', 'RESPONSABLE_FB')->firstOrFail();
        $chefCuisineRole = Role::where('name', 'CHEF_CUISINE')->firstOrFail();
        $chefMagasinRole = Role::where('name', 'CHEF_MAGASIN')->firstOrFail();
        $hygieneRole = Role::where('name', 'RESPONSABLE_HYGIENE')->firstOrFail();
        $caissierRole = Role::where('name', 'CAISSIER')->firstOrFail();
        $achatRole = Role::where('name', 'RESPONSABLE_ACHAT')->firstOrFail();

        $fb = User::firstOrCreate(
            ['email' => 'fb@aeroserve.com'],
            [
                'first_name' => 'Fathi',
                'last_name' => 'Ben Ali',
                'password' => 'password',
                'phone' => '20111222',
                'role_id' => $fbRole->id,
                'pdv_id' => $pdv?->id,
                'status' => 'active',
            ]
        );

        if ($pdv) {
            $pdv->update(['responsable_fb_id' => $fb->id]);
        }

        $chefCuisine = User::firstOrCreate(
            ['email' => 'cuisine@aeroserve.com'],
            [
                'first_name' => 'Nour',
                'last_name' => 'Chef',
                'password' => 'password',
                'phone' => '20111333',
                'role_id' => $chefCuisineRole->id,
                'status' => 'active',
            ]
        );

        $chefMagasin = User::firstOrCreate(
            ['email' => 'magasin@aeroserve.com'],
            [
                'first_name' => 'Sami',
                'last_name' => 'Store',
                'password' => 'password',
                'phone' => '20111444',
                'role_id' => $chefMagasinRole->id,
                'status' => 'active',
            ]
        );

        $hygiene = User::firstOrCreate(
            ['email' => 'hygiene@aeroserve.com'],
            [
                'first_name' => 'Amira',
                'last_name' => 'Clean',
                'password' => 'password',
                'phone' => '20111555',
                'role_id' => $hygieneRole->id,
                'status' => 'active',
            ]
        );

        $caissier = User::firstOrCreate(
            ['email' => 'caissier@aeroserve.com'],
            [
                'first_name' => 'Ali',
                'last_name' => 'Cash',
                'password' => 'password',
                'phone' => '20111666',
                'role_id' => $caissierRole->id,
                'pdv_id' => $pdv?->id,
                'status' => 'active',
            ]
        );

        User::firstOrCreate(
            ['email' => 'achat@aeroserve.com'],
            [
                'first_name' => 'Rim',
                'last_name' => 'Buyer',
                'password' => 'password',
                'phone' => '20111777',
                'role_id' => $achatRole->id,
                'status' => 'active',
            ]
        );

        $catCommercial = Category::firstOrCreate(['name' => 'Boissons'], ['type' => 'commercial']);
        $catIngredient = Category::firstOrCreate(['name' => 'Ingredients'], ['type' => 'matiere_premiere']);
        $catFood = Category::firstOrCreate(['name' => 'Sandwichs'], ['type' => 'food']);

        $eau = Product::firstOrCreate(
            ['name' => 'Eau 50cl'],
            [
                'description' => 'Bouteille eau minerale',
                'type' => 'commercial',
                'category_id' => $catCommercial->id,
                'price' => 2.500,
                'approval_status' => 'approved',
                'created_by' => $admin->id,
            ]
        );

        $poulet = Product::firstOrCreate(
            ['name' => 'Poulet'],
            [
                'description' => 'Poulet pour preparation',
                'type' => 'matiere_premiere',
                'category_id' => $catIngredient->id,
                'price' => 18.000,
                'allergens' => ['none'],
                'approval_status' => 'approved',
                'created_by' => $admin->id,
            ]
        );

        $pain = Product::firstOrCreate(
            ['name' => 'Pain Sandwich'],
            [
                'description' => 'Pain de sandwich',
                'type' => 'matiere_premiere',
                'category_id' => $catIngredient->id,
                'price' => 1.000,
                'allergens' => ['gluten'],
                'approval_status' => 'approved',
                'created_by' => $admin->id,
            ]
        );

        $salade = Product::firstOrCreate(
            ['name' => 'Salade'],
            [
                'description' => 'Salade verte',
                'type' => 'matiere_premiere',
                'category_id' => $catIngredient->id,
                'price' => 2.200,
                'approval_status' => 'approved',
                'created_by' => $admin->id,
            ]
        );

        $sandwich = Product::firstOrCreate(
            ['name' => 'Sandwich Poulet'],
            [
                'description' => 'Sandwich poulet salade',
                'type' => 'food',
                'category_id' => $catFood->id,
                'price' => 12.500,
                'allergens' => ['gluten'],
                'approval_status' => 'approved',
                'created_by' => $admin->id,
            ]
        );

        $sandwich->ingredients()->syncWithoutDetaching([
            $poulet->id => ['quantity' => 0.15, 'unit' => 'kg'],
            $pain->id => ['quantity' => 1, 'unit' => 'piece'],
            $salade->id => ['quantity' => 0.05, 'unit' => 'kg'],
        ]);

        foreach ([
            [$eau, 120, 20, 'piece'],
            [$poulet, 4.0, 10, 'kg'], // Low stock to simulate shortfall
            [$pain, 200, 30, 'piece'],
            [$salade, 1.0, 8, 'kg'],  // Low stock to simulate shortfall
            [$sandwich, 60, 12, 'piece'],
        ] as [$product, $qty, $threshold, $unit]) {
            $stock = $product->stock()->firstOrCreate([], [
                'quantity' => $qty,
                'min_threshold' => $threshold,
                'unit' => $unit,
            ]);

            StockMovement::firstOrCreate(
                [
                    'stock_id' => $stock->id,
                    'type' => 'in',
                    'reason' => 'Stock initial',
                ],
                [
                    'quantity' => $qty,
                    'expiration_date' => now()->addMonths(2),
                    'user_id' => $admin->id,
                ]
            );
        }

        $order = InternalOrder::firstOrCreate(
            ['notes' => 'Reappro sandwich pour midi'],
            [
                'type' => 'food',
                'status' => 'EN_ATTENTE',
                'created_by' => $fb->id,
                'assigned_to' => $chefCuisine->id,
                'pdv_id' => $pdv?->id,
            ]
        );

        $order->items()->firstOrCreate(
            ['product_id' => $sandwich->id],
            ['quantity_requested' => 12, 'quantity_fulfilled' => 0]
        );

        Comment::firstOrCreate(
            [
                'user_id' => $fb->id,
                'commentable_type' => InternalOrder::class,
                'commentable_id' => $order->id,
                'body' => 'Merci de prioriser cette commande.',
            ]
        );

        $weekStart = Carbon::now()->startOfWeek();
        $menu = Menu::firstOrCreate(
            ['name' => 'Menu Semaine Standard'],
            [
                'start_date' => $weekStart->toDateString(),
                'end_date' => $weekStart->copy()->endOfWeek()->toDateString(),
                'created_by' => $chefCuisine->id,
                'is_active' => true,
            ]
        );

        $menu->items()->firstOrCreate(
            ['product_id' => $sandwich->id, 'day_of_week' => 'monday'],
            ['meal_type' => 'lunch']
        );

        // Generate purchase needs calculation for the seeded menu (Feature 6)
        PurchaseNeedController::generateForMenu($menu);

        Planning::firstOrCreate(
            ['user_id' => $caissier->id, 'date' => now()->toDateString()],
            [
                'pdv_id' => $pdv?->id,
                'is_day_off' => false,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $fb->id,
            ]
        );


        HygieneReport::firstOrCreate(
            [
                'product_id' => $poulet->id,
                'inspected_by' => $hygiene->id,
            ],
            [
                'allergens_verified' => true,
                'expiration_verified' => true,
                'status' => 'conforme',
                'remarks' => 'Produit conforme.',
            ]
        );

        Notification::firstOrCreate(
            [
                'user_id' => $fb->id,
                'title' => 'Stock faible',
                'message' => 'Le stock de salade est proche du seuil.',
                'type' => 'warning',
            ],
            [
                'is_read' => false,
                'data' => ['product' => 'Salade'],
            ]
        );
    }
}
