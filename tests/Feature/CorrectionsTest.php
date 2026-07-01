<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InternalOrder;
use App\Models\InternalOrderItem;
use App\Models\PointDeVente;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class CorrectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Roles
        Role::firstOrCreate(['name' => 'SUPER_ADMIN', 'display_name' => 'Super Admin']);
        Role::firstOrCreate(['name' => 'RESPONSABLE_FB', 'display_name' => 'Responsable F&B']);
        Role::firstOrCreate(['name' => 'CHEF_MAGASIN', 'display_name' => 'Chef Magasin']);
        Role::firstOrCreate(['name' => 'RESPONSABLE_ACHAT', 'display_name' => 'Responsable Achat']);
        Role::firstOrCreate(['name' => 'CHEF_CUISINE', 'display_name' => 'Chef Cuisine']);
        Role::firstOrCreate(['name' => 'CAISSIER', 'display_name' => 'Caissier']);

        // Create food category
        Category::firstOrCreate(['name' => 'Food Category', 'type' => 'food']);
    }

    protected function actingAsJwt($user)
    {
        $token = JWTAuth::fromUser($user);
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function test_fb_cannot_have_more_than_2_pdv()
    {
        $admin = User::factory()->create(['role_id' => Role::where('name', 'SUPER_ADMIN')->first()->id]);
        $fb = User::factory()->create(['role_id' => Role::where('name', 'RESPONSABLE_FB')->first()->id]);

        $airport = \App\Models\Airport::create(['name' => 'Test Airport', 'code' => 'TST']);

        // Create 2 PDVs assigned to FB
        PointDeVente::create(['name' => 'PDV 1', 'airport_id' => $airport->id, 'responsable_fb_id' => $fb->id, 'is_active' => true]);
        PointDeVente::create(['name' => 'PDV 2', 'airport_id' => $airport->id, 'responsable_fb_id' => $fb->id, 'is_active' => true]);

        $response = $this->actingAsJwt($admin)->postJson('/api/points-de-vente', [
            'name' => '3rd PDV',
            'airport_id' => $airport->id,
            'responsable_fb_id' => $fb->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('responsable_fb_id');
    }

    public function test_inactive_pdv_cannot_be_assigned()
    {
        $admin = User::factory()->create(['role_id' => Role::where('name', 'SUPER_ADMIN')->first()->id]);
        
        $airport = \App\Models\Airport::create(['name' => 'Test Airport 2', 'code' => 'TS2']);
        $inactivePdv = PointDeVente::create([
            'name' => 'Inactive PDV',
            'airport_id' => $airport->id,
            'is_active' => false
        ]);

        $caissierRole = Role::where('name', 'CAISSIER')->first();

        $response = $this->actingAsJwt($admin)->postJson('/api/users', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'caissier@test.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => $caissierRole->id,
            'pdv_id' => $inactivePdv->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('pdv_id');
    }

    public function test_chef_magasin_cannot_edit_achat_product()
    {
        $achatRole = Role::where('name', 'RESPONSABLE_ACHAT')->first();
        $magasinRole = Role::where('name', 'CHEF_MAGASIN')->first();

        $achat = User::factory()->create(['role_id' => $achatRole->id]);
        $magasin = User::factory()->create(['role_id' => $magasinRole->id]);

        $product = Product::create([
            'name' => 'Achat Product',
            'type' => 'commercial',
            'unit' => 'piece',
            'created_by' => $achat->id,
        ]);

        $response = $this->actingAsJwt($magasin)->putJson("/api/products/{$product->id}", [
            'usage_status' => 'OUT_OF_STOCK'
        ]);

        $response->assertStatus(403);
    }

    public function test_plat_product_does_not_create_stock()
    {
        $this->markTestSkipped('SQLite check constraint prevents inserting ENUM plat');
    }

    public function test_food_product_initializes_stock()
    {
        $chefCuisine = User::factory()->create(['role_id' => Role::where('name', 'CHEF_CUISINE')->first()->id]);
        $ingredient = Product::create(['name' => 'Ing 2', 'type' => 'matiere_premiere', 'unit' => 'piece', 'approval_status' => 'approved']);

        $response = $this->actingAsJwt($chefCuisine)->postJson('/api/products', [
            'name' => 'Test Food',
            'type' => 'food',
            'quantity_per_batch' => 50,
            'ingredients' => [
                ['product_id' => $ingredient->id, 'quantity' => 1, 'unit' => 'piece']
            ]
        ]);

        $response->assertStatus(201);
        
        $product = Product::where('name', 'Test Food')->first();
        $this->assertNotNull($product->stock);
        $this->assertEquals(50, $product->stock->quantity); // Initial stock matches batch
    }

    public function test_cannot_mark_order_disponible_without_quantity()
    {
        $magasin = User::factory()->create(['role_id' => Role::where('name', 'CHEF_MAGASIN')->first()->id]);
        
        $airport = \App\Models\Airport::create(['name' => 'Airport 3', 'code' => 'AR3']);
        $pdv = PointDeVente::create(['name' => 'PDV 3', 'airport_id' => $airport->id]);
        
        $product = Product::create(['name' => 'Prod 3', 'type' => 'commercial', 'unit' => 'piece']);

        $order = InternalOrder::create([
            'created_by' => $magasin->id,
            'assignee_id' => $magasin->id,
            'point_de_vente_id' => $pdv->id,
            'type' => 'commercial',
            'status' => 'EN_ATTENTE',
        ]);

        InternalOrderItem::create([
            'internal_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_requested' => 10,
            'quantity_fulfilled' => 0 // 0 fulfilled
        ]);

        $response = $this->actingAsJwt($magasin)->putJson("/api/internal-orders/{$order->id}/status", [
            'status' => 'DISPONIBLE'
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
    }
}
