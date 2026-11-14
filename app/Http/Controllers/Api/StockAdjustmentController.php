<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementBatch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\Warehouse;
use App\Services\InventoryBatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockAdjustmentController extends Controller
{
    public function index()
    {
        $adjustments = StockAdjustment::with([
            'warehouse:id,name',
            'creator:id,name',
            'items.product:id,name,sku,unit',
        ])
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'message' => 'Stock adjustments retrieved successfully.',
            'data' => $adjustments,
        ]);
    }

    public function store(
        Request $request,
        InventoryBatchService $inventoryBatchService
    )
    {
        $validated = $request->validate([
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')
                    ->whereNull('deleted_at'),
            ],

            'adjustment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],

            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')
                    ->whereNull('deleted_at'),
            ],

            'items.*.adjustment_type' => [
                'required',
                Rule::in(['increase', 'decrease']),
            ],

            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,4',
            ],

            'items.*.unit_cost_afn' => [
                'nullable',
                'numeric',
                'min:0',
                'decimal:0,6',
            ],

            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],

            'items.*.reason' => [
                'required',
                'string',
                'max:100',
            ],

            'items.*.notes' => [
                'nullable',
                'string',
            ],
        ]);

        foreach ($validated['items'] as $index => $item) {
            if (
                $item['adjustment_type'] === 'increase'
                && (!isset($item['unit_cost_afn']) || $item['unit_cost_afn'] === '')
            ) {
                throw ValidationException::withMessages([
                    "items.$index.unit_cost_afn" =>
                        'Unit cost is required for stock increase.',
                ]);
            }
        }

        $adjustment = DB::transaction(function () use (
            $validated,
            $inventoryBatchService
        ) {

            $warehouse = Warehouse::whereKey($validated['warehouse_id'])
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$warehouse) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'The selected warehouse is inactive or unavailable.',
                ]);
            }

            $adjustment = StockAdjustment::create([
                'adjustment_number' =>
                    'ADJ-' . now()->format('YmdHis') . '-' .
                    strtoupper(\Illuminate\Support\Str::random(4)),

                'warehouse_id' => $warehouse->id,
                'created_by' => auth()->id(),
                'adjustment_date' => $validated['adjustment_date'],
                'status' => 'posted',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {

                $product = Product::whereKey($item['product_id'])
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => "Product ID {$item['product_id']} is inactive or unavailable.",
                    ]);
                }

                $stock = $inventoryBatchService->lockStock(
                    $warehouse->id,
                    $product->id,
                );
                $quantity = (float) $item['quantity'];
                $allocations = [];

                if ($item['adjustment_type'] === 'increase') {
                    $unitCost = (float) $item['unit_cost_afn'];
                    $totalCost = round($quantity * $unitCost, 4);
                    $movementType = 'adjustment_in';
                    $quantityIn = $quantity;
                    $quantityOut = 0;
                } else {
                    $allocations = $inventoryBatchService->allocate(
                        $stock['batches'],
                        $quantity,
                        "Insufficient batch stock for product {$product->name}.",
                    );
                    $totalCost = round(
                        array_sum(array_column($allocations, 'total_cost_afn')),
                        4,
                    );
                    $unitCost = $quantity > 0
                        ? round($totalCost / $quantity, 6)
                        : 0;
                    $movementType = 'adjustment_out';
                    $quantityIn = 0;
                    $quantityOut = $quantity;
                }

                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $product->id,
                    'adjustment_type' => $item['adjustment_type'],
                    'quantity' => $quantity,
                    'unit_cost_afn' => round($unitCost, 6),
                    'total_cost_afn' => round($totalCost, 6),
                    'reason' => $item['reason'],
                    'notes' => $item['notes'] ?? null,
                ]);

                $movement = InventoryMovement::create([
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'movement_type' => $movementType,
                    'quantity_in' => $quantityIn,
                    'quantity_out' => $quantityOut,
                    'unit_cost' => round($unitCost, 6),
                    'total_cost' => round($totalCost, 6),
                    'reference_type' => 'stock_adjustment',
                    'reference_id' => $adjustment->id,
                    'movement_date' =>
                        $validated['adjustment_date'] . ' 00:00:00',
                    'notes' => $item['reason'] .
                        (!empty($item['notes']) ? ' - ' . $item['notes'] : ''),
                    'created_by' => auth()->id(),
                ]);

                if ($item['adjustment_type'] === 'increase') {
                    $batch = ProductBatch::create([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'batch_number' => $item['batch_number'] ?? null,
                        'expiry_date' => $item['expiry_date'] ?? null,
                        'received_quantity' => $quantity,
                        'remaining_quantity' => $quantity,
                        'unit_cost_afn' => round($unitCost, 6),
                        'source_type' => StockAdjustment::class,
                        'source_id' => $adjustment->id,
                        'created_by' => auth()->id(),
                    ]);
                    $stock['batches']->push($batch);

                    InventoryMovementBatch::create([
                        'inventory_movement_id' => $movement->id,
                        'product_batch_id' => $batch->id,
                        'direction' => 'in',
                        'quantity' => $quantity,
                        'unit_cost_afn' => round($unitCost, 6),
                        'total_cost_afn' => round($totalCost, 4),
                    ]);
                } else {
                    foreach ($allocations as $allocation) {
                        InventoryMovementBatch::create([
                            'inventory_movement_id' => $movement->id,
                            'product_batch_id' => $allocation['batch']->id,
                            'direction' => 'out',
                            'quantity' => $allocation['quantity'],
                            'unit_cost_afn' => $allocation['unit_cost_afn'],
                            'total_cost_afn' => $allocation['total_cost_afn'],
                        ]);
                    }
                }

                $inventoryBatchService->syncBalance(
                    $stock['balance'],
                    $warehouse->id,
                    $product->id,
                    $stock['batches'],
                );
            }

            return $adjustment;
        }, 3);

        return response()->json([
            'message' => 'Stock adjustment created successfully.',
            'data' => StockAdjustment::with([
                'warehouse:id,name',
                'creator:id,name',
                'items.product:id,name,sku,unit',
            ])->findOrFail($adjustment->id),
        ], 201);
    }

    public function show(StockAdjustment $stockAdjustment)
    {
        $stockAdjustment->load([
            'warehouse:id,name',
            'creator:id,name',
            'items.product:id,name,sku,unit',
        ]);

        return response()->json([
            'message' => 'Stock adjustment retrieved successfully.',
            'data' => $stockAdjustment,
        ]);
    }
}
