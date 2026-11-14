<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OpeningStock;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementBatch;
use App\Models\ProductBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use App\Services\InventoryBatchService;

class OpeningStockController extends Controller
{
    public function index()
    {
        $openingStocks = OpeningStock::with([
            'warehouse',
            'creator',
            'items.product',
        ])->latest()->paginate(15);

        return response()->json([
            'message' => 'Opening stocks retrieved successfully.',
            'data' => $openingStocks,
        ]);
    }

    public function show(OpeningStock $openingStock)
    {
        $openingStock->load([
            'warehouse',
            'creator',
            'items.product',
        ]);

        return response()->json([
            'message' => 'Opening stock retrieved successfully.',
            'data' => $openingStock,
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
        Rule::exists('warehouses', 'id')->whereNull('deleted_at'),
    ],
    'opening_date' => ['required', 'date'],
    'notes' => ['nullable', 'string'],

    'items' => ['required', 'array', 'min:1'],
    'items.*.product_id' => [
        'required',
        'integer',
        'distinct',
        Rule::exists('products', 'id')->whereNull('deleted_at'),
    ],
    'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
    'items.*.unit_cost_afn' => ['required', 'numeric', 'gte:0'],
    'items.*.batch_number' => ['nullable', 'string', 'max:100'],
    'items.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
]);

        $openingStock = DB::transaction(function () use (
            $validated,
            $request,
            $inventoryBatchService
        ) {

            $warehouse = DB::table('warehouses')
    ->where('id', $validated['warehouse_id'])
    ->where('is_active', true)
    ->whereNull('deleted_at')
    ->lockForUpdate()
    ->first();

            if (!$warehouse) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ['Selected warehouse is inactive or unavailable.'],
                ]);
            }

            $openingStock = OpeningStock::create([
                'opening_number' => 'OS-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4)),
                'warehouse_id' => $validated['warehouse_id'],
                'created_by' => $request->user()?->id,
                'opening_date' => $validated['opening_date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {

                $product = DB::table('products')
    ->where('id', $item['product_id'])
    ->where('is_active', true)
    ->whereNull('deleted_at')
    ->lockForUpdate()
    ->first();

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => ['One or more selected products are inactive or unavailable.'],
                    ]);
                }

                $stock = $inventoryBatchService->lockStock(
                    $validated['warehouse_id'],
                    $product->id,
                );

                $quantity = (float) $item['quantity'];
                $unitCost = (float) $item['unit_cost_afn'];
                $totalCost = $quantity * $unitCost;

                $openingStock->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $quantity,
                    'unit_cost_afn' => $unitCost,
                    'total_cost_afn' => $totalCost,
                ]);

                $movement = InventoryMovement::create([
                    'warehouse_id' => $validated['warehouse_id'],
                    'product_id' => $item['product_id'],
                    'movement_type' => 'opening_stock',
                    'quantity_in' => $quantity,
                    'quantity_out' => 0,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'reference_type' => 'opening_stock',
                    'reference_id' => $openingStock->id,
                    'movement_date' => $validated['opening_date'] . ' 00:00:00',
                    'notes' => $validated['notes'] ?? 'Opening stock entry',
                    'created_by' => $request->user()?->id,
                ]);

                $batch = ProductBatch::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $validated['warehouse_id'],
                    'batch_number' => $item['batch_number'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'received_quantity' => $quantity,
                    'remaining_quantity' => $quantity,
                    'unit_cost_afn' => $unitCost,
                    'source_type' => 'opening_stock',
                    'source_id' => $openingStock->id,
                    'created_by' => $request->user()?->id,
                ]);

                InventoryMovementBatch::create([
                    'inventory_movement_id' => $movement->id,
                    'product_batch_id' => $batch->id,
                    'direction' => 'in',
                    'quantity' => $quantity,
                    'unit_cost_afn' => $unitCost,
                    'total_cost_afn' => $totalCost,
                ]);

                $stock['batches']->push($batch);
                $inventoryBatchService->syncBalance(
                    $stock['balance'],
                    $validated['warehouse_id'],
                    $product->id,
                    $stock['batches'],
                );
            }

            return $openingStock;
        });

        $openingStock->load([
            'warehouse',
            'creator',
            'items.product',
        ]);

        return response()->json([
            'message' => 'Opening stock created successfully.',
            'data' => $openingStock,
        ], 201);
    }
}