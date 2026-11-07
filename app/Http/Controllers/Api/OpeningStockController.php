<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OpeningStock;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'opening_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                'exists:products,id',
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost_afn' => ['required', 'numeric', 'gte:0'],
        ]);

        $openingStock = DB::transaction(function () use ($validated, $request) {

            $warehouse = DB::table('warehouses')
                ->where('id', $validated['warehouse_id'])
                ->where('is_active', true)
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
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => ['One or more selected products are inactive or unavailable.'],
                    ]);
                }

                $quantity = (float) $item['quantity'];
                $unitCost = (float) $item['unit_cost_afn'];
                $totalCost = $quantity * $unitCost;

                $balance = InventoryBalance::where('warehouse_id', $validated['warehouse_id'])
                    ->where('product_id', $item['product_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$balance) {
                    $balance = new InventoryBalance([
                        'warehouse_id' => $validated['warehouse_id'],
                        'product_id' => $item['product_id'],
                        'quantity' => 0,
                        'average_unit_cost' => 0,
                    ]);
                }

                $oldQuantity = (float) $balance->quantity;
                $oldAverageCost = (float) $balance->average_unit_cost;

                if ($oldQuantity < 0) {
                    throw ValidationException::withMessages([
                        'items' => ['Cannot add opening stock while existing inventory quantity is negative.'],
                    ]);
                }

                $newQuantity = $oldQuantity + $quantity;

                $newAverageCost = $newQuantity > 0
                    ? (($oldQuantity * $oldAverageCost) + $totalCost) / $newQuantity
                    : 0;

                $balance->quantity = $newQuantity;
                $balance->average_unit_cost = $newAverageCost;
                $balance->save();

                $openingStock->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $quantity,
                    'unit_cost_afn' => $unitCost,
                    'total_cost_afn' => $totalCost,
                ]);

                InventoryMovement::create([
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