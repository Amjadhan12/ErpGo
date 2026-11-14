<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementBatch;
use App\Models\PartnerTransaction;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\ProductBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\InventoryBatchService;

class PurchaseReturnController extends Controller
{
    public function index(): JsonResponse
    {
        $returns = PurchaseReturn::with([
            'purchase',
            'supplier',
            'warehouse',
            'currency',
            'items.product',
            'creator',
        ])->latest('return_date')->paginate(20);

        return response()->json($returns);
    }

    public function show(int $id): JsonResponse
    {
        $return = PurchaseReturn::with([
            'purchase',
            'supplier',
            'warehouse',
            'currency',
            'items.purchaseItem',
            'items.product',
            'creator',
        ])->findOrFail($id);

        return response()->json(['data' => $return]);
    }

    public function store(
        Request $request,
        InventoryBatchService $inventoryBatchService
    ): JsonResponse
    {
        $validated = $request->validate([
            'return_number' => [
                'required', 'string', 'max:50',
                'unique:purchase_returns,return_number',
            ],
            'purchase_id' => [
                'required', 'integer', 'exists:purchases,id',
            ],
            'return_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id' => [
                'required', 'integer', 'distinct',
                'exists:purchase_items,id',
            ],
            'items.*.quantity' => [
                'required', 'numeric', 'gt:0', 'decimal:0,4',
            ],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use (
            $validated,
            $request,
            $inventoryBatchService
        ) {
            $purchase = Purchase::whereKey($validated['purchase_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchase->status !== 'posted') {
                throw ValidationException::withMessages([
                    'purchase_id' => 'Only posted purchases can be returned.',
                ]);
            }

            if ($validated['return_date'] < $purchase->purchase_date->format('Y-m-d')) {
                throw ValidationException::withMessages([
                    'return_date' => 'Return date cannot be before purchase date.',
                ]);
            }

            $supplier = BusinessPartner::whereKey($purchase->business_partner_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($supplier->partner_type !== 'Supplier') {
                throw ValidationException::withMessages([
                    'purchase_id' => 'The purchase supplier is invalid.',
                ]);
            }

            $prepared = [];
            $subtotal = 0;
            $subtotalAfn = 0;

            foreach ($validated['items'] as $input) {
                $purchaseItem = $purchase->items()
                    ->whereKey($input['purchase_item_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $purchaseItem) {
                    throw ValidationException::withMessages([
                        'items' => 'A selected item does not belong to this purchase.',
                    ]);
                }

                $alreadyReturned = PurchaseReturnItem::where(
                    'purchase_item_id',
                    $purchaseItem->id
                )->whereHas('purchaseReturn', function ($query) {
                    $query->where('status', 'posted');
                })->sum('quantity');

                $availableToReturn = (float) $purchaseItem->quantity
                    - (float) $alreadyReturned;

                $quantity = (float) $input['quantity'];

                if ($quantity > $availableToReturn) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Return quantity exceeds the remaining purchase quantity for product ID {$purchaseItem->product_id}. Available: {$availableToReturn}.",
                        ],
                    ]);
                }

                $unitCost = (float) $purchaseItem->unit_cost;
                $unitCostAfn = (float) $purchaseItem->unit_cost_afn;

                // Use the effective original line value, including discounts.
                $effectiveUnitCost = (float) $purchaseItem->quantity > 0
                    ? (float) $purchaseItem->line_total / (float) $purchaseItem->quantity
                    : 0;

                $effectiveUnitCostAfn = (float) $purchaseItem->quantity > 0
                    ? (float) $purchaseItem->line_total_afn / (float) $purchaseItem->quantity
                    : 0;

                $lineTotal = round($effectiveUnitCost * $quantity, 4);
                $lineTotalAfn = round($effectiveUnitCostAfn * $quantity, 4);

                $stock = $inventoryBatchService->lockStock(
                    $purchase->warehouse_id,
                    $purchaseItem->product_id,
                );
                $balance = $stock['balance'];

                if (! $balance || (float) $balance->quantity < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Insufficient stock to return product ID {$purchaseItem->product_id}.",
                        ],
                    ]);
                }

                $originalPurchaseBatches = $stock['batches']->filter(
                    fn (ProductBatch $batch) => $batch->source_type === Purchase::class
                        && (int) $batch->source_id === (int) $purchase->id
                );
                $allocations = $inventoryBatchService->allocate(
                    $originalPurchaseBatches,
                    $quantity,
                    "Return quantity exceeds the remaining stock from the original purchase batch for product ID {$purchaseItem->product_id}.",
                );
                $inventoryCost = round(
                    array_sum(array_column($allocations, 'total_cost_afn')),
                    4,
                );
                $inventoryUnitCost = $quantity > 0
                    ? round($inventoryCost / $quantity, 6)
                    : 0;

                $prepared[] = [
                    'purchase_item' => $purchaseItem,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'unit_cost_afn' => $unitCostAfn,
                    'line_total' => $lineTotal,
                    'line_total_afn' => $lineTotalAfn,
                    'balance' => $balance,
                    'stock' => $stock,
                    'allocations' => $allocations,
                    'inventory_cost' => $inventoryCost,
                    'inventory_unit_cost' => $inventoryUnitCost,
                    'notes' => $input['notes'] ?? null,
                ];

                $subtotal += $lineTotal;
                $subtotalAfn += $lineTotalAfn;
            }

            $return = PurchaseReturn::create([
                'return_number' => $validated['return_number'],
                'purchase_id' => $purchase->id,
                'business_partner_id' => $purchase->business_partner_id,
                'warehouse_id' => $purchase->warehouse_id,
                'currency_id' => $purchase->currency_id,
                'created_by' => $request->user()->id,
                'return_date' => $validated['return_date'],
                'exchange_rate_to_afn' => $purchase->exchange_rate_to_afn,
                'subtotal' => round($subtotal, 4),
                'discount_amount' => 0,
                'grand_total' => round($subtotal, 4),
                'grand_total_afn' => round($subtotalAfn, 4),
                'status' => 'posted',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($prepared as $line) {
                $return->items()->create([
                    'purchase_item_id' => $line['purchase_item']->id,
                    'product_id' => $line['purchase_item']->product_id,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'discount_amount' => 0,
                    'line_total' => $line['line_total'],
                    'unit_cost_afn' => $line['unit_cost_afn'],
                    'line_total_afn' => $line['line_total_afn'],
                    'notes' => $line['notes'],
                ]);

                $movement = InventoryMovement::create([
                    'warehouse_id' => $purchase->warehouse_id,
                    'product_id' => $line['purchase_item']->product_id,
                    'movement_type' => 'purchase_return',
                    'quantity_in' => 0,
                    'quantity_out' => $line['quantity'],
                    'unit_cost' => $line['inventory_unit_cost'],
                    'total_cost' => $line['inventory_cost'],
                    'reference_type' => PurchaseReturn::class,
                    'reference_id' => $return->id,
                    'movement_date' => $validated['return_date'] . ' 00:00:00',
                    'notes' => 'Purchase return ' . $return->return_number,
                    'created_by' => $request->user()->id,
                ]);

                foreach ($line['allocations'] as $allocation) {
                    InventoryMovementBatch::create([
                        'inventory_movement_id' => $movement->id,
                        'product_batch_id' => $allocation['batch']->id,
                        'direction' => 'out',
                        'quantity' => $allocation['quantity'],
                        'unit_cost_afn' => $allocation['unit_cost_afn'],
                        'total_cost_afn' => $allocation['total_cost_afn'],
                    ]);
                }

                $inventoryBatchService->syncBalance(
                    $line['balance'],
                    $purchase->warehouse_id,
                    $line['purchase_item']->product_id,
                    $line['stock']['batches'],
                );
            }

            // Debit reduces the amount owed to the supplier.
            PartnerTransaction::create([
                'business_partner_id' => $purchase->business_partner_id,
                'currency_id' => $purchase->currency_id,
                'created_by' => $request->user()->id,
                'transaction_type' => 'purchase_return',
                'direction' => 'debit',
                'amount' => round($subtotal, 4),
                'reference_type' => PurchaseReturn::class,
                'reference_id' => $return->id,
                'transaction_date' => $validated['return_date'] . ' 00:00:00',
                'description' => 'Purchase return ' . $return->return_number,
            ]);

            return $return->load([
                'purchase',
                'supplier',
                'warehouse',
                'currency',
                'items.product',
            ]);
        });

        return response()->json([
            'message' => 'Purchase return recorded successfully.',
            'data' => $result,
        ], 201);
    }
}
