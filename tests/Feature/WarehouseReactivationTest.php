<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseReactivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]), 'sanctum');
    }

    public function test_empty_warehouse_can_be_deactivated_and_reactivated(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Warehouse Status Test',
            'is_active' => true,
        ]);

        $this->patchJson("/api/warehouses/{$warehouse->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->putJson("/api/warehouses/{$warehouse->id}", [
            'name' => $warehouse->name,
            'location' => $warehouse->location,
            'description' => $warehouse->description,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.is_active', true);

        $this->assertTrue($warehouse->fresh()->is_active);
    }

    public function test_warehouse_with_positive_stock_cannot_be_deactivated(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Warehouse Positive Stock Test',
            'is_active' => true,
        ]);
        $category = ProductCategory::create([
            'name' => 'Warehouse Stock Test Category',
            'is_active' => true,
        ]);
        $product = Product::create([
            'product_category_id' => $category->id,
            'name' => 'Warehouse Stock Test Product',
            'product_type' => 'goods',
            'unit' => 'piece',
            'minimum_stock' => 0,
            'is_active' => true,
        ]);
        InventoryBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->patchJson("/api/warehouses/{$warehouse->id}/deactivate")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'This warehouse cannot be deactivated because it still has stock.',
            );

        $this->assertTrue($warehouse->fresh()->is_active);
    }
}
