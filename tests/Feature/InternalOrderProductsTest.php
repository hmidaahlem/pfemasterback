<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class InternalOrderProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_fetch_food_products_by_type_without_category_id()
    {
        // Create role
        $role = Role::create(['name' => 'RESPONSABLE_FB', 'display_name' => 'Responsable FB']);

        // Create user
        $user = User::factory()->create(['role_id' => $role->id]);
        $token = JWTAuth::fromUser($user);

        // Create a food product (approved, active)
        $foodProduct = Product::create([
            'name' => 'Pizza',
            'type' => 'food',
            'approval_status' => 'approved',
            'is_active' => true,
            'price' => 10,
        ]);

        // Create a commercial product
        $commercialProduct = Product::create([
            'name' => 'Coca Cola',
            'type' => 'commercial',
            'approval_status' => 'approved',
            'is_active' => true,
            'price' => 5,
        ]);

        // Request without category_ids, but with type
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/products/by-categories', [
            'type' => 'food',
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Pizza');
    }

    public function test_it_can_fetch_products_by_category_and_type()
    {
        $role = Role::create(['name' => 'RESPONSABLE_FB', 'display_name' => 'Responsable FB']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $token = JWTAuth::fromUser($user);

        $category = Category::create([
            'name' => 'Drinks',
            'type' => 'commercial',
            'code' => 'COM_DRINKS',
        ]);

        $commercialProduct = Product::create([
            'name' => 'Pepsi',
            'type' => 'commercial',
            'approval_status' => 'approved',
            'is_active' => true,
            'price' => 4,
            'category_id' => $category->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/products/by-categories', [
            'category_ids' => [$category->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Pepsi');
    }
}
