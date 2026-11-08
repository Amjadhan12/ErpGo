<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\Warehouse;
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

    public function store(Request $request)
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

        $adjustment = DB::transaction(function () use ($validated) {

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

                $balance = InventoryBalance::where(
                    'warehouse_id',
                    $warehouse->id
                )
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if (!$balance) {
                    $balance = InventoryBalance::create([
                        'warehouse_id' => $warehouse->id,
                        'product_id' => $product->id,
                        'quantity' => 0,
                        'average_unit_cost' => 0,
                    ]);
                }

                $currentQuantity = (float) $balance->quantity;
                $currentAverageCost = (float) $balance->average_unit_cost;
                $quantity = (float) $item['quantity'];

                if ($item['adjustment_type'] === 'increase') {

                    $unitCost = (float) $item['unit_cost_afn'];

                    $newQuantity = $currentQuantity + $quantity;

                    $newAverageCost = $newQuantity > 0
                        ? (
                            ($currentQuantity * $currentAverageCost)
                            + ($quantity * $unitCost)
                        ) / $newQuantity
                        : $unitCost;

                    $movementType = 'adjustment_in';
                    $quantityIn = $quantity;
                    $quantityOut = 0;

                } else {

                    if ($quantity > $currentQuantity) {
                        throw ValidationException::withMessages([
                            'items' =>
                                "Insufficient stock for product {$product->name}. Available: {$currentQuantity}.",
                        ]);
                    }

                    $unitCost = $currentAverageCost;

                    $newQuantity = $currentQuantity - $quantity;
                    $newAverageCost = $currentAverageCost;

                    $movementType = 'adjustment_out';
                    $quantityIn = 0;
                    $quantityOut = $quantity;
                }

                $totalCost = $quantity * $unitCost;

                $balance->update([
                    'quantity' => round($newQuantity, 4),
                    'average_unit_cost' => round($newAverageCost, 6),
                ]);

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

                InventoryMovement::create([
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
