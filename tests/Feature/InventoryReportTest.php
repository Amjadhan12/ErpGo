<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReportTest extends TestCase
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

    public function test_inventory_report_returns_filtered_balances_and_valuation(): void
    {
        $category = ProductCategory::create([
            'name' => 'Inventory report category',
            'is_active' => true,
        ]);
        $product = Product::create([
            'product_category_id' => $category->id,
            'name' => 'Inventory report product',
            'sku' => 'INV-REPORT-1',
            'unit' => 'piece',
            'minimum_stock' => 2,
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'name' => 'Inventory report warehouse',
            'location' => 'Test location',
            'is_active' => true,
        ]);
        $otherWarehouse = Warehouse::create([
            'name' => 'Other inventory report warehouse',
            'is_active' => true,
        ]);
        InventoryBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'average_unit_cost' => 2,
        ]);
        InventoryBalance::create([
            'warehouse_id' => $otherWarehouse->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'average_unit_cost' => 4,
        ]);

        $this->getJson(
            "/api/reports/inventory?warehouse_id={$warehouse->id}&product_id={$product->id}",
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.name', 'Inventory report product')
            ->assertJsonPath('data.0.product.sku', 'INV-REPORT-1')
            ->assertJsonPath('data.0.product.category', 'Inventory report category')
            ->assertJsonPath('data.0.warehouse.name', 'Inventory report warehouse')
            ->assertJsonPath('data.0.quantity', 5)
            ->assertJsonPath('data.0.stock_status', 'in_stock')
            ->assertJsonPath('summary.products_count', 1)
            ->assertJsonPath('summary.total_stock_value_afn', 10);
    }

    public function test_movement_report_returns_filtered_history_and_summary(): void
    {
        $category = ProductCategory::create([
            'name' => 'Movement report category',
            'is_active' => true,
        ]);
        $product = Product::create([
            'product_category_id' => $category->id,
            'name' => 'Movement report product',
            'sku' => 'MOVE-REPORT-1',
            'unit' => 'piece',
            'minimum_stock' => 0,
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'name' => 'Movement report warehouse',
            'is_active' => true,
        ]);
        $otherWarehouse = Warehouse::create([
            'name' => 'Other movement report warehouse',
            'is_active' => true,
        ]);
        InventoryMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'movement_type' => 'opening_stock',
            'quantity_in' => 5,
            'unit_cost' => 2,
            'total_cost' => 10,
            'reference_type' => 'OpeningStock',
            'reference_id' => 42,
            'movement_date' => '2026-11-01 10:00:00',
        ]);
        InventoryMovement::create([
            'warehouse_id' => $otherWarehouse->id,
            'product_id' => $product->id,
            'movement_type' => 'purchase',
            'quantity_in' => 3,
            'unit_cost' => 4,
            'total_cost' => 12,
            'movement_date' => '2026-11-02 10:00:00',
        ]);

        $this->getJson(
            "/api/reports/inventory-movements?warehouse_id={$warehouse->id}&product_id={$product->id}&from_date=2026-11-01&to_date=2026-11-01",
        )
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.product.name', 'Movement report product')
            ->assertJsonPath('data.data.0.warehouse.name', 'Movement report warehouse')
            ->assertJsonPath('data.data.0.movement_type', 'opening_stock')
            ->assertJsonPath('data.data.0.reference_id', 42)
            ->assertJsonPath('summary.total_movements', 1)
            ->assertJsonPath('summary.total_quantity_in', 5)
            ->assertJsonPath('summary.total_cost_in', 10);
    }
}
