<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
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

    public function store(Request $request)
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

        $transfer = DB::transaction(function () use ($validated, $request) {

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

                $sourceBalance = InventoryBalance::where(
                    'warehouse_id',
                    $validated['from_warehouse_id']
                )
                    ->where('product_id', $item['product_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$sourceBalance) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Product {$product->name} has no stock in the source warehouse.",
                        ],
                    ]);
                }

                $sourceQuantity = (float) $sourceBalance->quantity;
                $unitCost = (float) $sourceBalance->average_unit_cost;

                if ($sourceQuantity < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Insufficient stock for {$product->name}. Available: {$sourceQuantity}. Requested: {$quantity}.",
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 5. Calculate Transfer Cost
                |--------------------------------------------------------------------------
                */

                $totalCost = round($quantity * $unitCost, 6);

                /*
                |--------------------------------------------------------------------------
                | 6. Decrease Source Warehouse Stock
                |--------------------------------------------------------------------------
                */

                $newSourceQuantity = round(
                    $sourceQuantity - $quantity,
                    4
                );

                $sourceBalance->quantity = $newSourceQuantity;

                if ($newSourceQuantity <= 0) {
                    $sourceBalance->average_unit_cost = 0;
                }

                $sourceBalance->save();

                /*
                |--------------------------------------------------------------------------
                | 7. Lock or Create Destination Balance
                |--------------------------------------------------------------------------
                */

                $destinationBalance = InventoryBalance::where(
                    'warehouse_id',
                    $validated['to_warehouse_id']
                )
                    ->where('product_id', $item['product_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$destinationBalance) {
                    $destinationBalance = new InventoryBalance([
                        'warehouse_id' => $validated['to_warehouse_id'],
                        'product_id' => $item['product_id'],
                        'quantity' => 0,
                        'average_unit_cost' => 0,
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 8. Calculate Destination Weighted Average Cost
                |--------------------------------------------------------------------------
                */

                $oldDestinationQuantity =
                    (float) $destinationBalance->quantity;

                $oldDestinationAverage =
                    (float) $destinationBalance->average_unit_cost;

                $newDestinationQuantity = round(
                    $oldDestinationQuantity + $quantity,
                    4
                );

                $newDestinationAverage = $newDestinationQuantity > 0
                    ? (
                        ($oldDestinationQuantity * $oldDestinationAverage)
                        + $totalCost
                    ) / $newDestinationQuantity
                    : 0;

                $destinationBalance->quantity = $newDestinationQuantity;

                $destinationBalance->average_unit_cost = round(
                    $newDestinationAverage,
                    6
                );

                $destinationBalance->save();

                /*
                |--------------------------------------------------------------------------
                | 9. Create Transfer Item
                |--------------------------------------------------------------------------
                */

                $transfer->items()->create([
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

                InventoryMovement::create([
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

                InventoryMovement::create([
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