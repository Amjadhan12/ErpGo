<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementBatch;
use App\Models\ProductBatch;
use App\Models\StockTransferItemBatch;
use App\Services\InventoryBatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockTransferController extends Controller
{
    public function index()
    {
        $transfers = StockTransfer::with([
            'fromWarehouse',
            'toWarehouse',
            'creator',
            'items.product',
        ])->latest()->paginate(15);

        return response()->json([
            'message' => 'Stock transfers retrieved successfully.',
            'data' => $transfers,
        ]);
    }

    public function show(StockTransfer $stockTransfer)
    {
        $stockTransfer->load([
            'fromWarehouse',
            'toWarehouse',
            'creator',
            'items.product',
        ]);

        return response()->json([
            'message' => 'Stock transfer retrieved successfully.',
            'data' => $stockTransfer,
        ]);
    }

    public function store(
        Request $request,
        InventoryBatchService $inventoryBatchService
    )
    {
        $validated = $request->validate([
            'from_warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at'),
            ],

            'to_warehouse_id' => [
                'required',
                'integer',
                'different:from_warehouse_id',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at'),
            ],

            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],

            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],

            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
            ],
        ]);

        $transfer = DB::transaction(function () use (
            $validated,
            $request,
            $inventoryBatchService
        ) {

            /*
            |--------------------------------------------------------------------------
            | 1. Lock Warehouses
            |--------------------------------------------------------------------------
            */

            $warehouseIds = [
                $validated['from_warehouse_id'],
                $validated['to_warehouse_id'],
            ];

            sort($warehouseIds);

            $warehouses = DB::table('warehouses')
                ->whereIn('id', $warehouseIds)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $fromWarehouse = $warehouses->get(
                $validated['from_warehouse_id']
            );

            $toWarehouse = $warehouses->get(
                $validated['to_warehouse_id']
            );

            if (
                !$fromWarehouse ||
                !$toWarehouse ||
                !$fromWarehouse->is_active ||
                !$toWarehouse->is_active
            ) {
                throw ValidationException::withMessages([
                    'warehouse_id' => [
                        'Both warehouses must be active and available.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Create Transfer Header
            |--------------------------------------------------------------------------
            */

            $transfer = StockTransfer::create([
                'transfer_number' => 'TR-' .
                    now()->format('YmdHis') . '-' .
                    strtoupper(Str::random(4)),

                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'created_by' => $request->user()?->id,
                'transfer_date' => $validated['transfer_date'],
                'status' => 'posted',
                'notes' => $validated['notes'] ?? null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | 3. Process Transfer Items
            |--------------------------------------------------------------------------
            */

            foreach ($validated['items'] as $item) {

                $product = DB::table('products')
                    ->where('id', $item['product_id'])
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'One or more products are inactive or unavailable.',
                        ],
                    ]);
                }

                $quantity = round((float) $item['quantity'], 4);

                /*
                |--------------------------------------------------------------------------
                | 4. Lock Source Inventory Balance
                |--------------------------------------------------------------------------
                */

                $sourceStock = $inventoryBatchService->lockStock(
                    $validated['from_warehouse_id'],
                    $item['product_id'],
                );
                $destinationStock = $inventoryBatchService->lockStock(
                    $validated['to_warehouse_id'],
                    $item['product_id'],
                );
                $sourceBalance = $sourceStock['balance'];
                $sourceBatches = $sourceStock['batches'];
                $destinationBatches = $destinationStock['batches'];

                if (!$sourceBalance) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Product {$product->name} has no stock in the source warehouse.",
                        ],
                    ]);
                }

                $sourceQuantity = (float) $sourceBalance->quantity;

                if ($sourceQuantity < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Insufficient stock for {$product->name}. Available: {$sourceQuantity}. Requested: {$quantity}.",
                        ],
                    ]);
                }

                $allocations = $inventoryBatchService->allocate(
                    $sourceBatches,
                    $quantity,
                    "Insufficient batch stock for {$product->name} in the source warehouse.",
                );
                $totalCost = round(
                    array_sum(array_column($allocations, 'total_cost_afn')),
                    4,
                );
                $unitCost = $quantity > 0
                    ? round($totalCost / $quantity, 6)
                    : 0;

                /*
                |--------------------------------------------------------------------------
                | 9. Create Transfer Item
                |--------------------------------------------------------------------------
                */

                $transferItem = $transfer->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $quantity,
                    'unit_cost_afn' => $unitCost,
                    'total_cost_afn' => $totalCost,
                ]);

                /*
                |--------------------------------------------------------------------------
                | 10. Create Source Inventory Movement
                |--------------------------------------------------------------------------
                */

                $sourceMovement = InventoryMovement::create([
                    'warehouse_id' => $validated['from_warehouse_id'],
                    'product_id' => $item['product_id'],
                    'movement_type' => 'transfer_out',
                    'quantity_in' => 0,
                    'quantity_out' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $transfer->id,
                    'movement_date' =>
                        $validated['transfer_date'] . ' 00:00:00',
                    'notes' => 'Stock transferred to warehouse ID ' .
                        $validated['to_warehouse_id'],
                    'created_by' => $request->user()?->id,
                ]);

                /*
                |--------------------------------------------------------------------------
                | 11. Create Destination Inventory Movement
                |--------------------------------------------------------------------------
                */

                $destinationMovement = InventoryMovement::create([
                    'warehouse_id' => $validated['to_warehouse_id'],
                    'product_id' => $item['product_id'],
                    'movement_type' => 'transfer_in',
                    'quantity_in' => $quantity,
                    'quantity_out' => 0,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $transfer->id,
                    'movement_date' =>
                        $validated['transfer_date'] . ' 00:00:00',
                    'notes' => 'Stock received from warehouse ID ' .
                        $validated['from_warehouse_id'],
                    'created_by' => $request->user()?->id,
                ]);

                $inventoryBatchService->syncBalance(
                    $sourceBalance,
                    $validated['from_warehouse_id'],
                    $item['product_id'],
                    $sourceBatches,
                );

                foreach ($allocations as $allocation) {
                    /** @var ProductBatch $sourceBatch */
                    $sourceBatch = $allocation['batch'];
                    $destinationBatch = ProductBatch::create([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $validated['to_warehouse_id'],
                        'batch_number' => $sourceBatch->batch_number,
                        'expiry_date' => $sourceBatch->expiry_date?->format('Y-m-d'),
                        'received_quantity' => $allocation['quantity'],
                        'remaining_quantity' => $allocation['quantity'],
                        'unit_cost_afn' => $allocation['unit_cost_afn'],
                        'source_type' => 'stock_transfer',
                        'source_id' => $transfer->id,
                        'created_by' => $request->user()?->id,
                    ]);
                    $destinationBatches->push($destinationBatch);

                    StockTransferItemBatch::create([
                        'stock_transfer_item_id' => $transferItem->id,
                        'source_product_batch_id' => $sourceBatch->id,
                        'destination_product_batch_id' => $destinationBatch->id,
                        'quantity' => $allocation['quantity'],
                        'unit_cost_afn' => $allocation['unit_cost_afn'],
                        'total_cost_afn' => $allocation['total_cost_afn'],
                    ]);

                    InventoryMovementBatch::create([
                        'inventory_movement_id' => $sourceMovement->id,
                        'product_batch_id' => $sourceBatch->id,
                        'direction' => 'out',
                        'quantity' => $allocation['quantity'],
                        'unit_cost_afn' => $allocation['unit_cost_afn'],
                        'total_cost_afn' => $allocation['total_cost_afn'],
                    ]);

                    InventoryMovementBatch::create([
                        'inventory_movement_id' => $destinationMovement->id,
                        'product_batch_id' => $destinationBatch->id,
                        'direction' => 'in',
                        'quantity' => $allocation['quantity'],
                        'unit_cost_afn' => $allocation['unit_cost_afn'],
                        'total_cost_afn' => $allocation['total_cost_afn'],
                    ]);
                }

                $inventoryBatchService->syncBalance(
                    $destinationStock['balance'],
                    $validated['to_warehouse_id'],
                    $item['product_id'],
                    $destinationBatches,
                );
            }

            return $transfer;
        });

        $transfer->load([
            'fromWarehouse',
            'toWarehouse',
            'creator',
            'items.product',
        ]);

        return response()->json([
            'message' => 'Stock transferred successfully.',
            'data' => $transfer,
        ], 201);
    }
}