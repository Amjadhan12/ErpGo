<?php

namespace Tests\Feature;

use App\Models\BusinessPartner;
use App\Models\Currency;
use App\Models\InventoryBalance;
use App\Models\InventoryMovementBatch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItemBatch;
use App\Models\SaleReturnItemBatch;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InventoryBatchConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $secretary;

    private BusinessPartner $supplier;

    private BusinessPartner $customer;

    private Currency $currency;

    private Warehouse $warehouse;

    private Warehouse $secondWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secretary = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);
        $this->actingAs($this->secretary, 'sanctum');

        $this->supplier = BusinessPartner::create([
            'partner_type' => 'Supplier',
            'name' => 'Inventory Test Supplier',
            'is_active' => true,
        ]);
        $this->customer = BusinessPartner::create([
            'partner_type' => 'Customer',
            'name' => 'Inventory Test Customer',
            'is_active' => true,
        ]);
        $this->currency = Currency::create([
            'code' => 'AFN',
            'name' => 'Afghan Afghani',
            'symbol' => 'AFN',
            'decimal_places' => 2,
            'is_base' => true,
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::create([
            'name' => 'Inventory Test Warehouse A',
            'is_active' => true,
        ]);
        $this->secondWarehouse = Warehouse::create([
            'name' => 'Inventory Test Warehouse B',
            'is_active' => true,
        ]);
    }

    public function test_opening_stock_creates_batches_with_supplied_or_unknown_metadata(): void
    {
        $product = $this->createProduct('Opening Stock Product');

        $this->postOpeningStock(
            $product,
            $this->warehouse,
            5,
            'OPEN-LOT-1',
            '2027-06-30',
        )->assertCreated();
        $this->postOpeningStock(
            $product,
            $this->secondWarehouse,
            3,
            null,
            null,
        )->assertCreated();

        $knownBatch = ProductBatch::query()
            ->where('batch_number', 'OPEN-LOT-1')
            ->firstOrFail();
        $this->assertSame('2027-06-30', $knownBatch->expiry_date->format('Y-m-d'));
        $this->assertNull(
            ProductBatch::query()
                ->where('warehouse_id', $this->secondWarehouse->id)
                ->where('product_id', $product->id)
                ->firstOrFail()
                ->batch_number
        );
        $this->assertStockConsistent($this->warehouse, $product);
        $this->assertStockConsistent($this->secondWarehouse, $product);
        $this->assertSame(2, InventoryMovementBatch::query()->count());
    }

    public function test_transfer_splits_across_fefo_batches_and_preserves_lot_metadata(): void
    {
        $product = $this->createProduct('Transfer Batch Product');
        $this->postOpeningStock($product, $this->warehouse, 3, 'TR-EARLY', '2026-12-01')
            ->assertCreated();
        $this->postOpeningStock($product, $this->warehouse, 5, 'TR-LATE', '2027-06-01')
            ->assertCreated();

        $this->actingAs($this->secretary, 'sanctum')
            ->postJson('/api/stock-transfers', [
                'from_warehouse_id' => $this->warehouse->id,
                'to_warehouse_id' => $this->secondWarehouse->id,
                'transfer_date' => '2026-11-14',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 6,
                ]],
            ])
            ->assertCreated();

        $this->assertSame(2, \DB::table('stock_transfer_item_batches')->count());
        $this->assertSame(4, InventoryMovementBatch::query()
            ->whereHas('inventoryMovement', fn ($query) => $query
                ->where('reference_type', 'stock_transfer'))
            ->count());
        $this->assertSame(0.0, (float) ProductBatch::where('batch_number', 'TR-EARLY')->firstOrFail()->remaining_quantity);
        $this->assertSame(2.0, (float) ProductBatch::where('batch_number', 'TR-LATE')->firstOrFail()->remaining_quantity);
        $destinationBatches = ProductBatch::query()
            ->where('warehouse_id', $this->secondWarehouse->id)
            ->where('product_id', $product->id)
            ->orderBy('batch_number')
            ->get();
        $this->assertCount(2, $destinationBatches);
        $this->assertSame(['TR-EARLY', 'TR-LATE'], $destinationBatches->pluck('batch_number')->all());
        $this->assertStockConsistent($this->warehouse, $product);
        $this->assertStockConsistent($this->secondWarehouse, $product);
    }

    public function test_transfer_rolls_back_all_batch_and_balance_changes_when_a_later_item_fails(): void
    {
        $firstProduct = $this->createProduct('Transfer Rollback Product A');
        $secondProduct = $this->createProduct('Transfer Rollback Product B');
        $this->postOpeningStock($firstProduct, $this->warehouse, 4, 'ROLLBACK-A', null)
            ->assertCreated();
        $this->postOpeningStock($secondProduct, $this->warehouse, 1, 'ROLLBACK-B', null)
            ->assertCreated();

        $this->postJson('/api/stock-transfers', [
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $this->secondWarehouse->id,
            'transfer_date' => '2026-11-14',
            'items' => [
                ['product_id' => $firstProduct->id, 'quantity' => 2],
                ['product_id' => $secondProduct->id, 'quantity' => 2],
            ],
        ])->assertUnprocessable();

        $this->assertSame(0, StockTransfer::query()->count());
        $this->assertSame(4.0, (float) ProductBatch::where('batch_number', 'ROLLBACK-A')->firstOrFail()->remaining_quantity);
        $this->assertSame(1.0, (float) ProductBatch::where('batch_number', 'ROLLBACK-B')->firstOrFail()->remaining_quantity);
        $this->assertStockConsistent($this->warehouse, $firstProduct);
        $this->assertStockConsistent($this->warehouse, $secondProduct);
    }

    public function test_adjustment_increase_creates_a_lot_and_decrease_reduces_fefo_lots(): void
    {
        $product = $this->createProduct('Adjustment Batch Product');
        $this->postOpeningStock($product, $this->warehouse, 3, 'ADJ-EARLY', '2026-12-01')
            ->assertCreated();
        $this->postOpeningStock($product, $this->warehouse, 2, 'ADJ-LATE', '2027-06-01')
            ->assertCreated();

        $this->postJson('/api/stock-adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => '2026-11-14',
            'items' => [[
                'product_id' => $product->id,
                'adjustment_type' => 'increase',
                'quantity' => 2,
                'unit_cost_afn' => 12,
                'batch_number' => 'ADJ-NEW',
                'expiry_date' => '2027-12-01',
                'reason' => 'Count correction',
            ]],
        ])->assertCreated();

        $newBatch = ProductBatch::where('batch_number', 'ADJ-NEW')->firstOrFail();
        $this->assertSame(2.0, (float) $newBatch->remaining_quantity);

        $this->postJson('/api/stock-adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => '2026-11-14',
            'items' => [[
                'product_id' => $product->id,
                'adjustment_type' => 'decrease',
                'quantity' => 4,
                'reason' => 'Damaged count correction',
            ]],
        ])->assertCreated();

        $this->assertSame(0.0, (float) ProductBatch::where('batch_number', 'ADJ-EARLY')->firstOrFail()->remaining_quantity);
        $this->assertSame(1.0, (float) ProductBatch::where('batch_number', 'ADJ-LATE')->firstOrFail()->remaining_quantity);
        $this->assertSame(2.0, (float) ProductBatch::where('batch_number', 'ADJ-NEW')->firstOrFail()->remaining_quantity);
        $this->assertStockConsistent($this->warehouse, $product);
    }

    public function test_purchase_return_reduces_only_the_original_purchase_batch(): void
    {
        $product = $this->createProduct('Purchase Return Batch Product');
        $purchase = $this->createPurchase($product, 'PUR-RETURN-LOT', 5);
        $purchaseItemId = (int) \DB::table('purchase_items')
            ->where('purchase_id', $purchase->id)
            ->value('id');

        $this->postJson('/api/purchase-returns', [
            'return_number' => 'PUR-RETURN-001',
            'purchase_id' => $purchase->id,
            'return_date' => '2026-11-14',
            'items' => [[
                'purchase_item_id' => $purchaseItemId,
                'quantity' => 2,
            ]],
        ])->assertCreated();

        $batch = ProductBatch::where('batch_number', 'PUR-RETURN-LOT')->firstOrFail();
        $this->assertSame(3.0, (float) $batch->remaining_quantity);
        $this->assertSame(1, InventoryMovementBatch::where('product_batch_id', $batch->id)
            ->where('direction', 'out')
            ->count());
        $this->assertStockConsistent($this->warehouse, $product);
        $this->assertSame(50.0, (float) Purchase::findOrFail($purchase->id)->grand_total);
    }

    public function test_purchase_return_rejects_quantity_above_original_batch_stock(): void
    {
        $product = $this->createProduct('Purchase Return Availability Product');
        $purchase = $this->createPurchase($product, 'PUR-RETURN-SOLD-LOT', 5);
        $this->postOpeningStock($product, $this->warehouse, 3, 'OTHER-AVAILABLE-LOT', null)
            ->assertCreated();
        $this->createSale($product, 4, 'PUR-RETURN-SALE-001');
        $purchaseItemId = (int) \DB::table('purchase_items')
            ->where('purchase_id', $purchase->id)
            ->value('id');

        $this->postJson('/api/purchase-returns', [
            'return_number' => 'PUR-RETURN-REJECT-001',
            'purchase_id' => $purchase->id,
            'return_date' => '2026-11-14',
            'items' => [[
                'purchase_item_id' => $purchaseItemId,
                'quantity' => 2,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, \DB::table('purchase_returns')
            ->where('return_number', 'PUR-RETURN-REJECT-001')
            ->count());
        $this->assertSame(1.0, (float) ProductBatch::where('batch_number', 'PUR-RETURN-SOLD-LOT')->firstOrFail()->remaining_quantity);
        $this->assertSame(3.0, (float) ProductBatch::where('batch_number', 'OTHER-AVAILABLE-LOT')->firstOrFail()->remaining_quantity);
        $this->assertStockConsistent($this->warehouse, $product);
    }

    public function test_sale_uses_fefo_and_sale_return_restores_original_sold_batch(): void
    {
        $product = $this->createProduct('Sale Batch Product');
        $this->postOpeningStock($product, $this->warehouse, 3, 'SALE-EARLY', '2026-12-01')
            ->assertCreated();
        $this->postOpeningStock($product, $this->warehouse, 4, 'SALE-LATE', '2027-06-01')
            ->assertCreated();

        $sale = $this->createSale($product, 5, 'SALE-BATCH-001');
        $earlyBatch = ProductBatch::where('batch_number', 'SALE-EARLY')->firstOrFail();
        $lateBatch = ProductBatch::where('batch_number', 'SALE-LATE')->firstOrFail();
        $this->assertSame(0.0, (float) $earlyBatch->fresh()->remaining_quantity);
        $this->assertSame(2.0, (float) $lateBatch->fresh()->remaining_quantity);
        $earlySaleItemBatch = SaleItemBatch::query()
            ->whereHas('saleItem', fn ($query) => $query->where('sale_id', $sale->id))
            ->where('product_batch_id', $earlyBatch->id)
            ->firstOrFail();
        $this->assertSame(3.0, (float) $earlySaleItemBatch->quantity);
        $this->assertSame(0.0, (float) $earlySaleItemBatch->returned_quantity);

        $this->postJson('/api/sale-returns', [
            'return_number' => 'SALE-RETURN-001',
            'sale_id' => $sale->id,
            'return_date' => '2026-11-14',
            'items' => [[
                'sale_item_id' => $earlySaleItemBatch->sale_item_id,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        $this->assertSame(1, SaleReturnItemBatch::query()
            ->where('sale_item_batch_id', $earlySaleItemBatch->id)
            ->count());
        $this->assertSame(1.0, (float) $earlyBatch->fresh()->remaining_quantity);
        $this->assertSame(2.0, (float) $lateBatch->fresh()->remaining_quantity);
        $this->assertSame(1.0, (float) $earlySaleItemBatch->fresh()->returned_quantity);
        $this->assertStockConsistent($this->warehouse, $product);
        $this->assertSame(100.0, (float) Sale::findOrFail($sale->id)->grand_total);
    }

    public function test_untraceable_legacy_sale_return_uses_null_batch_metadata(): void
    {
        $product = $this->createProduct('Legacy Sale Return Product');
        $this->postOpeningStock($product, $this->warehouse, 4, 'LEGACY-RETURN-STOCK', null)
            ->assertCreated();
        $sale = $this->createSale($product, 1, 'LEGACY-SALE-RETURN-001');
        $saleItemId = (int) \DB::table('sale_items')
            ->where('sale_id', $sale->id)
            ->value('id');
        $saleMovementIds = \DB::table('inventory_movements')
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->pluck('id');

        \DB::table('sale_item_batches')->where('sale_item_id', $saleItemId)->delete();
        \DB::table('inventory_movement_batches')
            ->whereIn('inventory_movement_id', $saleMovementIds)
            ->delete();

        $this->postJson('/api/sale-returns', [
            'return_number' => 'LEGACY-SALE-RETURN-R1',
            'sale_id' => $sale->id,
            'return_date' => '2026-11-14',
            'items' => [[
                'sale_item_id' => $saleItemId,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        $returnBatch = ProductBatch::query()
            ->where('source_type', \App\Models\SaleReturn::class)
            ->where('source_id', \DB::table('sale_returns')
                ->where('sale_id', $sale->id)
                ->latest('id')
                ->value('id'))
            ->firstOrFail();
        $this->assertNull($returnBatch->batch_number);
        $this->assertNull($returnBatch->expiry_date);
        $this->assertSame(1.0, (float) $returnBatch->remaining_quantity);
        $this->assertSame(4.0, (float) InventoryBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity'));
        $this->assertStockConsistent($this->warehouse, $product);
    }

    public function test_wastage_uses_fefo_and_can_allocate_expired_batches(): void
    {
        $product = $this->createProduct('Wastage Batch Product');
        $this->postOpeningStock($product, $this->warehouse, 2, 'WASTE-EXPIRED', '2026-10-01')
            ->assertCreated();
        $this->postOpeningStock($product, $this->warehouse, 3, 'WASTE-VALID', '2027-03-01')
            ->assertCreated();

        $this->postJson('/api/stock-wastages', [
            'warehouse_id' => $this->warehouse->id,
            'wastage_date' => '2026-11-14',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'reason' => 'Expired and damaged stock',
            ]],
        ])->assertCreated();

        $this->assertSame(0.0, (float) ProductBatch::where('batch_number', 'WASTE-EXPIRED')->firstOrFail()->remaining_quantity);
        $this->assertSame(2.0, (float) ProductBatch::where('batch_number', 'WASTE-VALID')->firstOrFail()->remaining_quantity);
        $this->assertStockConsistent($this->warehouse, $product);
    }

    public function test_sale_rejects_expired_only_batch_stock_and_rolls_back(): void
    {
        $product = $this->createProduct('Expired Sale Batch Product');
        $this->postOpeningStock($product, $this->warehouse, 3, 'SALE-EXPIRED', '2026-10-01')
            ->assertCreated();

        $this->postJson('/api/sales', $this->salePayload($product, 1, 'SALE-EXPIRED-001'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(3.0, (float) ProductBatch::where('batch_number', 'SALE-EXPIRED')->firstOrFail()->remaining_quantity);
        $this->assertStockConsistent($this->warehouse, $product);
    }

    public function test_stock_mutation_rejects_preexisting_balance_batch_mismatch(): void
    {
        $product = $this->createProduct('Mismatch Product');
        $this->postOpeningStock($product, $this->warehouse, 5, 'MISMATCH-LOT', null)
            ->assertCreated();
        InventoryBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->update(['quantity' => 6]);

        $this->postJson('/api/stock-adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => '2026-11-14',
            'items' => [[
                'product_id' => $product->id,
                'adjustment_type' => 'increase',
                'quantity' => 1,
                'unit_cost_afn' => 10,
                'reason' => 'Should be blocked',
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, StockAdjustment::query()->count());
        $this->assertSame(6.0, (float) InventoryBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity'));
        $this->assertSame(5.0, (float) ProductBatch::where('batch_number', 'MISMATCH-LOT')->firstOrFail()->remaining_quantity);
    }

    public function test_stock_mutation_rejects_negative_balance_and_batch_quantities(): void
    {
        $product = $this->createProduct('Negative Batch Product');
        $this->postOpeningStock($product, $this->warehouse, 2, 'NEGATIVE-LOT', null)
            ->assertCreated();
        ProductBatch::query()
            ->where('batch_number', 'NEGATIVE-LOT')
            ->update(['remaining_quantity' => -1]);
        InventoryBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->update(['quantity' => -1]);

        $this->postJson('/api/stock-adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => '2026-11-14',
            'items' => [[
                'product_id' => $product->id,
                'adjustment_type' => 'increase',
                'quantity' => 1,
                'unit_cost_afn' => 10,
                'reason' => 'Should be blocked',
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, StockAdjustment::query()->count());
        $this->assertSame(-1.0, (float) ProductBatch::where('batch_number', 'NEGATIVE-LOT')->firstOrFail()->remaining_quantity);
        $this->assertSame(-1.0, (float) InventoryBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity'));
    }

    private function createProduct(string $name): Product
    {
        $category = ProductCategory::create([
            'name' => $name . ' Category',
            'is_active' => true,
        ]);

        return Product::create([
            'product_category_id' => $category->id,
            'name' => $name,
            'product_type' => 'goods',
            'unit' => 'piece',
            'minimum_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function postOpeningStock(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        ?string $batchNumber,
        ?string $expiryDate,
        float $unitCost = 10,
    ): TestResponse {
        return $this->postJson('/api/opening-stocks', [
            'warehouse_id' => $warehouse->id,
            'opening_date' => '2026-11-14',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_cost_afn' => $unitCost,
                'batch_number' => $batchNumber,
                'expiry_date' => $expiryDate,
            ]],
        ]);
    }

    private function createPurchase(
        Product $product,
        string $batchNumber,
        float $quantity,
    ): Purchase {
        $response = $this->postJson('/api/purchases', [
            'purchase_number' => 'PUR-' . strtoupper(substr(md5($batchNumber), 0, 10)),
            'business_partner_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_id' => $this->currency->id,
            'purchase_date' => '2026-11-14',
            'exchange_rate_to_afn' => 1,
            'payment_type' => 'credit',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_cost' => 10,
                'batch_number' => $batchNumber,
                'expiry_date' => '2027-06-30',
            ]],
        ]);
        $response->assertCreated();

        return Purchase::query()->where('purchase_number', 'PUR-' . strtoupper(substr(md5($batchNumber), 0, 10)))->firstOrFail();
    }

    private function createSale(Product $product, float $quantity, string $saleNumber): Sale
    {
        $response = $this->postJson(
            '/api/sales',
            $this->salePayload($product, $quantity, $saleNumber),
        );
        $response->assertCreated();

        return Sale::query()->where('sale_number', $saleNumber)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function salePayload(Product $product, float $quantity, string $saleNumber): array
    {
        return [
            'sale_number' => $saleNumber,
            'business_partner_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_id' => $this->currency->id,
            'sale_date' => '2026-11-14',
            'exchange_rate_to_afn' => 1,
            'payment_type' => 'credit',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => 20,
            ]],
        ];
    }

    private function assertStockConsistent(Warehouse $warehouse, Product $product): void
    {
        $balanceQuantity = (float) (InventoryBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity') ?? 0);
        $batchQuantity = (float) ProductBatch::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('remaining_quantity');

        $this->assertEqualsWithDelta(
            round($balanceQuantity, 4),
            round($batchQuantity, 4),
            0.00005,
        );
    }
}