<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockWastage;
use App\Models\StockWastageItem;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        return response()->json(
            $query->latest('wastage_date')->paginate(20)
        );
    }

    public function store(Request $request)
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

        return DB::transaction(function () use ($validated, $request) {

            $warehouse = Warehouse::whereKey($validated['warehouse_id'])
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
                    ->lockForUpdate()
                    ->first();

                if (!$product || $product->deleted_at !== null) {
                    throw ValidationException::withMessages([
                        'items' => ['A selected product is unavailable.'],
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
                    throw ValidationException::withMessages([
                        'items' => [
                            "No stock balance found for product {$product->id}.",
                        ],
                    ]);
                }

                $quantity = (float) $item['quantity'];
                $available = (float) $balance->quantity;

                if ($available < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Insufficient stock for product {$product->id}. Available: {$available}",
                        ],
                    ]);
                }

                $unitCost = (float) $balance->average_unit_cost;
                $totalCost = round($quantity * $unitCost, 6);

                $balance->quantity = round($available - $quantity, 4);
                $balance->save();

                StockWastageItem::create([
                    'stock_wastage_id' => $wastage->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_cost_afn' => $unitCost,
                    'total_cost_afn' => $totalCost,
                    'reason' => $item['reason'],
                    'notes' => $item['notes'] ?? null,
                ]);

                InventoryMovement::create([
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
        return response()->json([
            'data' => $stockWastage->load([
                'warehouse',
                'creator',
                'items.product',
            ]),
        ]);
    }
}