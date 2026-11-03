<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $balances = InventoryBalance::query()
            ->with([
                'warehouse:id,name,location',
                'product:id,product_category_id,sku,name,unit,minimum_stock',
                'product.category:id,name',
            ])
            ->whereHas('warehouse', function ($query) {
                $query->where('is_active', true);
            })
            ->whereHas('product', function ($query) {
                $query->where('is_active', true);
            })
            ->when(
                $request->filled('warehouse_id'),
                fn ($query) => $query->where(
                    'warehouse_id',
                    $request->integer('warehouse_id')
                )
            )
            ->when(
                $request->filled('product_id'),
                fn ($query) => $query->where(
                    'product_id',
                    $request->integer('product_id')
                )
            )
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->get();

        $data = $balances->map(function (InventoryBalance $balance) {

            $quantity = (float) $balance->quantity;
            $averageCost = (float) $balance->average_unit_cost;
            $minimumStock = (float) $balance->product->minimum_stock;

            $stockValue = $quantity * $averageCost;

            $stockStatus = match (true) {
                $quantity <= 0 => 'out_of_stock',
                $quantity <= $minimumStock => 'low_stock',
                default => 'in_stock',
            };

            return [
                'id' => $balance->id,

                'warehouse' => [
                    'id' => $balance->warehouse->id,
                    'name' => $balance->warehouse->name,
                    'location' => $balance->warehouse->location,
                ],

                'product' => [
                    'id' => $balance->product->id,
                    'sku' => $balance->product->sku,
                    'name' => $balance->product->name,
                    'unit' => $balance->product->unit,
                    'category' => $balance->product->category?->name,
                ],

                'quantity' => round($quantity, 4),
                'average_unit_cost_afn' => round($averageCost, 6),
                'minimum_stock' => round($minimumStock, 4),
                'stock_value_afn' => round($stockValue, 4),
                'stock_status' => $stockStatus,
            ];
        });

        return response()->json([
            'message' => 'Inventory report retrieved successfully.',
            'data' => $data,
            'summary' => [
                'products_count' => $data->count(),
                'total_stock_value_afn' => round(
                    $data->sum('stock_value_afn'),
                    4
                ),
                'in_stock_count' => $data->where(
                    'stock_status',
                    'in_stock'
                )->count(),
                'low_stock_count' => $data->where(
                    'stock_status',
                    'low_stock'
                )->count(),
                'out_of_stock_count' => $data->where(
                    'stock_status',
                    'out_of_stock'
                )->count(),
            ],
        ]);
    }
}
