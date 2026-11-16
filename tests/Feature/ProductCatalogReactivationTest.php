<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogReactivationTest extends TestCase
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

    public function test_deactivated_product_can_be_reactivated_through_update(): void
    {
        $category = ProductCategory::create([
            'name' => 'Reactivation Product Category',
            'is_active' => true,
        ]);
        $product = Product::create([
            'product_category_id' => $category->id,
            'name' => 'Reactivation Product',
            'unit' => 'piece',
            'is_active' => true,
        ]);

        $this->patchJson("/api/products/{$product->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->putJson("/api/products/{$product->id}", [
            'product_category_id' => $category->id,
            'name' => $product->name,
            'unit' => $product->unit,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.is_active', true);

        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_deactivated_category_can_be_reactivated_through_update(): void
    {
        $category = ProductCategory::create([
            'name' => 'Reactivation Category',
            'description' => 'Category reactivation test',
            'is_active' => true,
        ]);

        $this->patchJson("/api/product-categories/{$category->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->putJson("/api/product-categories/{$category->id}", [
            'name' => $category->name,
            'description' => $category->description,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.is_active', true);

        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_deactivated_unit_can_be_reactivated_through_update(): void
    {
        $unit = Unit::create([
            'name' => 'Reactivation Unit',
            'short_name' => 'RU',
            'description' => 'Unit reactivation test',
            'is_active' => true,
        ]);

        $this->patchJson("/api/units/{$unit->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->putJson("/api/units/{$unit->id}", [
            'name' => $unit->name,
            'short_name' => $unit->short_name,
            'description' => $unit->description,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.is_active', true);

        $this->assertTrue($unit->fresh()->is_active);
    }
}
