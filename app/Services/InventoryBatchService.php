<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class InventoryBatchService
{
    /**
     * Lock a warehouse/product stock group and reject unclassified legacy gaps.
     * Call inside a database transaction.
     *
     * @return array{balance: InventoryBalance|null, batches: Collection<int, ProductBatch>}
     */
    public function lockStock(int $warehouseId, int $productId): array
    {
        $warehouse = Warehouse::query()
            ->whereKey($warehouseId)
            ->lockForUpdate()
            ->first();
        $product = Product::query()
            ->whereKey($productId)
            ->lockForUpdate()
            ->first();

        if (!$warehouse || !$product) {
            throw ValidationException::withMessages([
                'items' => ['The selected warehouse or product is unavailable.'],
            ]);
        }

        $balance = InventoryBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        $batches = ProductBatch::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $balanceQuantity = round((float) ($balance?->quantity ?? 0), 4);
        $batchQuantity = $this->batchQuantity($batches);

        if (
            $balanceQuantity < -0.00005 ||
            $batches->contains(
                fn (ProductBatch $batch) => (float) $batch->remaining_quantity < -0.00005
            )
        ) {
            throw ValidationException::withMessages([
                'items' => [
                    "Negative inventory was found for product {$productId} in warehouse {$warehouseId}. Reconcile stock before continuing.",
                ],
            ]);
        }

        if (abs($balanceQuantity - $batchQuantity) >= 0.00005) {
            throw ValidationException::withMessages([
                'items' => [
                    "Inventory balance and batch quantities differ for product {$productId} in warehouse {$warehouseId}. Reconcile legacy stock before continuing.",
                ],
            ]);
        }

        return [
            'balance' => $balance,
            'batches' => $batches,
        ];
    }

    /**
     * Reduce eligible batches in their existing FEFO order.
     *
     * @param Collection<int, ProductBatch> $eligibleBatches
     * @return list<array{batch: ProductBatch, quantity: float, unit_cost_afn: float, total_cost_afn: float}>
     */
    public function allocate(
        Collection $eligibleBatches,
        float $quantity,
        string $insufficientMessage,
    ): array {
        $available = $this->batchQuantity($eligibleBatches);
        if ($available + 0.00005 < $quantity) {
            throw ValidationException::withMessages([
                'items' => [$insufficientMessage],
            ]);
        }

        $remaining = round($quantity, 4);
        $allocations = [];

        foreach ($eligibleBatches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $batchAvailable = (float) $batch->remaining_quantity;
            if ($batchAvailable <= 0) {
                continue;
            }

            $allocated = round(min($batchAvailable, $remaining), 4);
            $unitCost = (float) $batch->unit_cost_afn;
            $totalCost = round($allocated * $unitCost, 4);

            $batch->remaining_quantity = round($batchAvailable - $allocated, 4);
            $batch->save();

            $allocations[] = [
                'batch' => $batch,
                'quantity' => $allocated,
                'unit_cost_afn' => $unitCost,
                'total_cost_afn' => $totalCost,
            ];
            $remaining = round($remaining - $allocated, 4);
        }

        if ($remaining > 0.00005) {
            throw ValidationException::withMessages([
                'items' => [$insufficientMessage],
            ]);
        }

        return $allocations;
    }

    /**
     * Rebuild aggregate quantity and weighted average cost from locked batches.
     * Call after batch changes, inside a database transaction.
     *
     * @param Collection<int, ProductBatch> $batches
     */
    public function syncBalance(
        ?InventoryBalance $balance,
        int $warehouseId,
        int $productId,
        Collection $batches,
    ): InventoryBalance {
        $quantity = $this->batchQuantity($batches);
        $totalCost = 0.0;

        foreach ($batches as $batch) {
            $totalCost +=
                (float) $batch->remaining_quantity * (float) $batch->unit_cost_afn;
        }

        $averageUnitCost = $quantity > 0
            ? round($totalCost / $quantity, 6)
            : 0;

        if (!$balance) {
            return InventoryBalance::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'average_unit_cost' => $averageUnitCost,
            ]);
        }

        $balance->update([
            'quantity' => $quantity,
            'average_unit_cost' => $averageUnitCost,
        ]);

        return $balance;
    }

    /**
     * @param Collection<int, ProductBatch> $batches
     */
    public function batchQuantity(Collection $batches): float
    {
        return round(
            (float) $batches->sum(fn (ProductBatch $batch) => (float) $batch->remaining_quantity),
            4,
        );
    }
}