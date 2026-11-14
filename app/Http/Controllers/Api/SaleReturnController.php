<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementBatch;
use App\Models\PartnerTransaction;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\ProductBatch;
use App\Models\SaleItemBatch;
use App\Models\SaleReturnItemBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\InventoryBatchService;

class SaleReturnController extends Controller
{
    public function index(): JsonResponse
    {
        $returns = SaleReturn::with([
            'sale',
            'customer',
            'warehouse',
            'currency',
            'items.product',
            'creator',
        ])->latest('return_date')->paginate(20);

        return response()->json($returns);
    }

    public function show(int $id): JsonResponse
    {
        $return = SaleReturn::with([
            'sale',
            'customer',
            'warehouse',
            'currency',
            'items.saleItem',
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
                'unique:sale_returns,return_number',
            ],
            'sale_id' => [
                'required', 'integer', 'exists:sales,id',
            ],
            'return_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => [
                'required', 'integer', 'distinct',
                'exists:sale_items,id',
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
            $sale = Sale::whereKey($validated['sale_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($sale->status !== 'posted') {
                throw ValidationException::withMessages([
                    'sale_id' => 'Only posted sales can be returned.',
                ]);
            }

            if ($validated['return_date'] < $sale->sale_date->format('Y-m-d')) {
                throw ValidationException::withMessages([
                    'return_date' => 'Return date cannot be before sale date.',
                ]);
            }

            $customer = BusinessPartner::whereKey($sale->business_partner_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($customer->partner_type !== 'Customer') {
                throw ValidationException::withMessages([
                    'sale_id' => 'The sale customer is invalid.',
                ]);
            }

            $prepared = [];
            $subtotal = 0;
            $subtotalAfn = 0;

            foreach ($validated['items'] as $input) {
                $saleItem = $sale->items()
                    ->whereKey($input['sale_item_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $saleItem) {
                    throw ValidationException::withMessages([
                        'items' => 'A selected item does not belong to this sale.',
                    ]);
                }

                $alreadyReturned = SaleReturnItem::where(
                    'sale_item_id',
                    $saleItem->id
                )->whereHas('saleReturn', function ($query) {
                    $query->where('status', 'posted');
                })->sum('quantity');

                $availableToReturn = (float) $saleItem->quantity
                    - (float) $alreadyReturned;

                $quantity = (float) $input['quantity'];

                if ($quantity > $availableToReturn) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Return quantity exceeds the remaining sold quantity for product ID {$saleItem->product_id}. Available: {$availableToReturn}.",
                        ],
                    ]);
                }

                $effectiveUnitPrice = (float) $saleItem->quantity > 0
                    ? (float) $saleItem->line_total / (float) $saleItem->quantity
                    : 0;

                $effectiveUnitPriceAfn = (float) $saleItem->quantity > 0
                    ? (float) $saleItem->line_total_afn / (float) $saleItem->quantity
                    : 0;

                $unitCostAfn = (float) $saleItem->unit_cost_afn;

                $lineTotal = round($effectiveUnitPrice * $quantity, 4);
                $lineTotalAfn = round($effectiveUnitPriceAfn * $quantity, 4);
                $totalCostAfn = round($unitCostAfn * $quantity, 4);

                $stock = $inventoryBatchService->lockStock(
                    $sale->warehouse_id,
                    $saleItem->product_id,
                );
                $batchMap = $stock['batches']->keyBy('id');
                $saleBatchAllocations = SaleItemBatch::query()
                    ->where('sale_item_id', $saleItem->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $remainingToRestore = round($quantity, 4);
                $batchAllocations = [];

                foreach ($saleBatchAllocations as $saleBatchAllocation) {
                    if ($remainingToRestore <= 0) {
                        break;
                    }

                    $availableToRestore = round(
                        (float) $saleBatchAllocation->quantity
                            - (float) $saleBatchAllocation->returned_quantity,
                        4,
                    );
                    if ($availableToRestore <= 0) {
                        continue;
                    }

                    $productBatch = $batchMap->get(
                        $saleBatchAllocation->product_batch_id
                    );
                    if (!$productBatch) {
                        throw ValidationException::withMessages([
                            'items' => [
                                "The original batch for sale item {$saleItem->id} is unavailable.",
                            ],
                        ]);
                    }

                    $allocated = round(
                        min($availableToRestore, $remainingToRestore),
                        4,
                    );
                    $productBatch->remaining_quantity = round(
                        (float) $productBatch->remaining_quantity + $allocated,
                        4,
                    );
                    $productBatch->save();

                    $saleBatchAllocation->returned_quantity = round(
                        (float) $saleBatchAllocation->returned_quantity + $allocated,
                        4,
                    );
                    $saleBatchAllocation->save();

                    $batchUnitCost = (float) $saleBatchAllocation->unit_cost_afn;
                    $batchAllocations[] = [
                        'sale_batch_allocation' => $saleBatchAllocation,
                        'batch' => $productBatch,
                        'quantity' => $allocated,
                        'unit_cost_afn' => $batchUnitCost,
                        'total_cost_afn' => round($allocated * $batchUnitCost, 4),
                    ];
                    $remainingToRestore = round(
                        $remainingToRestore - $allocated,
                        4,
                    );
                }

                // Older sale lines without batch allocations return as an explicitly
                // unidentified return lot; no batch number or expiry is fabricated.
                $untrackedReturnQuantity = max(0, $remainingToRestore);
                $inventoryCostAfn = round(
                    array_sum(array_column($batchAllocations, 'total_cost_afn'))
                        + ($untrackedReturnQuantity * $unitCostAfn),
                    4,
                );
                $inventoryUnitCostAfn = $quantity > 0
                    ? round($inventoryCostAfn / $quantity, 6)
                    : 0;

                $prepared[] = [
                    'sale_item' => $saleItem,
                    'quantity' => $quantity,
                    'unit_price' => (float) $saleItem->unit_price,
                    'unit_price_afn' => (float) $saleItem->unit_price_afn,
                    'line_total' => $lineTotal,
                    'line_total_afn' => $lineTotalAfn,
                    'unit_cost_afn' => $unitCostAfn,
                    'total_cost_afn' => $totalCostAfn,
                    'stock' => $stock,
                    'batch_allocations' => $batchAllocations,
                    'untracked_return_quantity' => $untrackedReturnQuantity,
                    'inventory_cost_afn' => $inventoryCostAfn,
                    'inventory_unit_cost_afn' => $inventoryUnitCostAfn,
                    'notes' => $input['notes'] ?? null,
                ];

                $subtotal += $lineTotal;
                $subtotalAfn += $lineTotalAfn;
            }

            $return = SaleReturn::create([
                'return_number' => $validated['return_number'],
                'sale_id' => $sale->id,
                'business_partner_id' => $sale->business_partner_id,
                'warehouse_id' => $sale->warehouse_id,
                'currency_id' => $sale->currency_id,
                'created_by' => $request->user()->id,
                'return_date' => $validated['return_date'],
                'exchange_rate_to_afn' => $sale->exchange_rate_to_afn,
                'subtotal' => round($subtotal, 4),
                'discount_amount' => 0,
                'grand_total' => round($subtotal, 4),
                'grand_total_afn' => round($subtotalAfn, 4),
                'status' => 'posted',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($prepared as $line) {
                $returnItem = $return->items()->create([
                    'sale_item_id' => $line['sale_item']->id,
                    'product_id' => $line['sale_item']->product_id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => 0,
                    'line_total' => $line['line_total'],
                    'unit_price_afn' => $line['unit_price_afn'],
                    'line_total_afn' => $line['line_total_afn'],
                    'unit_cost_afn' => $line['inventory_unit_cost_afn'],
                    'total_cost_afn' => $line['inventory_cost_afn'],
                    'notes' => $line['notes'],
                ]);

                foreach ($line['batch_allocations'] as $allocation) {
                    SaleReturnItemBatch::create([
                        'sale_return_item_id' => $returnItem->id,
                        'sale_item_batch_id' => $allocation['sale_batch_allocation']->id,
                        'product_batch_id' => $allocation['batch']->id,
                        'quantity' => $allocation['quantity'],
                        'unit_cost_afn' => $allocation['unit_cost_afn'],
                        'total_cost_afn' => $allocation['total_cost_afn'],
                    ]);
                }

                $movement = InventoryMovement::create([
                    'warehouse_id' => $sale->warehouse_id,
                    'product_id' => $line['sale_item']->product_id,
                    'movement_type' => 'sale_return',
                    'quantity_in' => $line['quantity'],
                    'quantity_out' => 0,
                    'unit_cost' => $line['inventory_unit_cost_afn'],
                    'total_cost' => $line['inventory_cost_afn'],
                    'reference_type' => SaleReturn::class,
                    'reference_id' => $return->id,
                    'movement_date' => $validated['return_date'] . ' 00:00:00',
                    'notes' => 'Sale return ' . $return->return_number,
                    'created_by' => $request->user()->id,
                ]);

                $movementAllocations = $line['batch_allocations'];
                if ($line['untracked_return_quantity'] > 0) {
                    $unknownReturnBatch = ProductBatch::create([
                        'product_id' => $line['sale_item']->product_id,
                        'warehouse_id' => $sale->warehouse_id,
                        'batch_number' => null,
                        'expiry_date' => null,
                        'received_quantity' => $line['untracked_return_quantity'],
                        'remaining_quantity' => $line['untracked_return_quantity'],
                        'unit_cost_afn' => $line['unit_cost_afn'],
                        'source_type' => SaleReturn::class,
                        'source_id' => $return->id,
                        'created_by' => $request->user()->id,
                    ]);
                    $line['stock']['batches']->push($unknownReturnBatch);
                    $movementAllocations[] = [
                        'batch' => $unknownReturnBatch,
                        'quantity' => $line['untracked_return_quantity'],
                        'unit_cost_afn' => $line['unit_cost_afn'],
                        'total_cost_afn' => round(
                            $line['untracked_return_quantity']
                                * $line['unit_cost_afn'],
                            4,
                        ),
                    ];
                }

                foreach ($movementAllocations as $allocation) {
                    InventoryMovementBatch::create([
                        'inventory_movement_id' => $movement->id,
                        'product_batch_id' => $allocation['batch']->id,
                        'direction' => 'in',
                        'quantity' => $allocation['quantity'],
                        'unit_cost_afn' => $allocation['unit_cost_afn'],
                        'total_cost_afn' => $allocation['total_cost_afn'],
                    ]);
                }

                $inventoryBatchService->syncBalance(
                    $line['stock']['balance'],
                    $sale->warehouse_id,
                    $line['sale_item']->product_id,
                    $line['stock']['batches'],
                );
            }

            // Credit reduces the customer's outstanding debt.
            PartnerTransaction::create([
                'business_partner_id' => $sale->business_partner_id,
                'currency_id' => $sale->currency_id,
                'created_by' => $request->user()->id,
                'transaction_type' => 'sale_return',
                'direction' => 'credit',
                'amount' => round($subtotal, 4),
                'reference_type' => SaleReturn::class,
                'reference_id' => $return->id,
                'transaction_date' => $validated['return_date'] . ' 00:00:00',
                'description' => 'Sale return ' . $return->return_number,
            ]);

            return $return->load([
                'sale',
                'customer',
                'warehouse',
                'currency',
                'items.product',
            ]);
        });

        return response()->json([
            'message' => 'Sale return recorded successfully.',
            'data' => $result,
        ], 201);
    }
}
