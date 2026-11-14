<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockWastage;
use App\Models\StockWastageItem;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use App\Models\InventoryMovementBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\InventoryBatchService;

class StockWastageController extends Controller
{
    public function index(Request $request)
    {
        $query = StockWastage::with([
            'warehouse',
            'creator',
            'items.product',
        ]);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('wastage_date', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('wastage_date', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('wastage_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $wastages = $query->latest('wastage_date')->paginate(20);
        $this->attachBatchAllocations($wastages->getCollection());

        return response()->json($wastages);
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
                'exists:warehouses,id',
            ],
            'wastage_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                'exists:products,id',
            ],
            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,4',
            ],
            'items.*.reason' => [
                'required',
                'string',
                'max:100',
            ],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use (
            $validated,
            $request,
            $inventoryBatchService
        ) {

            $warehouse = Warehouse::whereKey($validated['warehouse_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$warehouse || $warehouse->deleted_at !== null) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ['Selected warehouse is unavailable.'],
                ]);
            }

            $wastage = StockWastage::create([
                'wastage_number' => 'WST-' . now()->format('YmdHis')
                    . '-' . strtoupper(\Illuminate\Support\Str::random(4)),
                'warehouse_id' => $warehouse->id,
                'created_by' => $request->user()->id,
                'wastage_date' => $validated['wastage_date'],
                'status' => 'posted',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {

                $product = Product::whereKey($item['product_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$product || $product->deleted_at !== null) {
                    throw ValidationException::withMessages([
                        'items' => ['A selected product is unavailable.'],
                    ]);
                }

                $stock = $inventoryBatchService->lockStock(
                    $warehouse->id,
                    $product->id,
                );
                $quantity = round((float) $item['quantity'], 4);
                $balanceAvailable = (float) ($stock['balance']?->quantity ?? 0);

                if ($balanceAvailable + 0.00005 < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Insufficient stock for product {$product->id}. Available: {$balanceAvailable}",
                        ],
                    ]);
                }

                // Expired lots remain eligible for wastage and sort before later-expiring stock.
                $allocations = $inventoryBatchService->allocate(
                    $stock['batches'],
                    $quantity,
                    "Insufficient batch stock for product {$product->id}.",
                );
                $totalCost = round(
                    array_sum(array_column($allocations, 'total_cost_afn')),
                    4,
                );
                $unitCost = round($totalCost / $quantity, 6);


                StockWastageItem::create([
                    'stock_wastage_id' => $wastage->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_cost_afn' => $unitCost,
                    'total_cost_afn' => $totalCost,
                    'reason' => $item['reason'],
                    'notes' => $item['notes'] ?? null,
                ]);

                $movement = InventoryMovement::create([
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'movement_type' => 'wastage_out',
                    'quantity_in' => 0,
                    'quantity_out' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'reference_type' => 'stock_wastage',
                    'reference_id' => $wastage->id,
                    'movement_date' => $validated['wastage_date'],
                    'notes' => $item['reason'],
                    'created_by' => $request->user()->id,
                ]);

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

                $inventoryBatchService->syncBalance(
                    $stock['balance'],
                    $warehouse->id,
                    $product->id,
                    $stock['batches'],
                );

            }

            return response()->json([
                'message' => 'Stock wastage recorded successfully.',
                'data' => $wastage->load([
                    'warehouse',
                    'creator',
                    'items.product',
                ]),
            ], 201);

        }, 3);
    }

    public function show(StockWastage $stockWastage)
    {
        $stockWastage->load(['warehouse', 'creator', 'items.product']);
        $this->attachBatchAllocations(collect([$stockWastage]));

        return response()->json(['data' => $stockWastage]);
    }

    private function attachBatchAllocations(Collection $wastages): void
    {
        if ($wastages->isEmpty()) {
            return;
        }

        $movements = InventoryMovement::with('batchDetails.productBatch')
            ->where('reference_type', 'stock_wastage')
            ->whereIn('reference_id', $wastages->pluck('id'))
            ->get()
            ->groupBy('reference_id');

        foreach ($wastages as $wastage) {
            $byProduct = $movements->get($wastage->id, collect())
                ->keyBy('product_id');

            foreach ($wastage->items as $item) {
                $movement = $byProduct->get($item->product_id);
                $item->setAttribute('batch_allocations', $movement
                    ? $movement->batchDetails->map(function ($allocation) {
                        $batch = $allocation->productBatch;

                        return [
                            'batch_number' => $batch?->batch_number,
                            'expiry_date' => $batch?->expiry_date?->toDateString(),
                            'quantity' => (float) $allocation->quantity,
                        ];
                    })->values()
                    : collect());
            }
        }
    }
}
