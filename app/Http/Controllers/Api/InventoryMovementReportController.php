<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryMovementReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'movement_type' => ['nullable', 'string', 'max:30'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        $movements = InventoryMovement::query()
            ->with([
                'warehouse:id,name',
                'product:id,sku,name,unit',
                'creator:id,name',
            ])
            ->when(
                isset($validated['warehouse_id']),
                fn ($query) => $query->where(
                    'warehouse_id',
                    $validated['warehouse_id']
                )
            )
            ->when(
                isset($validated['product_id']),
                fn ($query) => $query->where(
                    'product_id',
                    $validated['product_id']
                )
            )
            ->when(
                isset($validated['movement_type']),
                fn ($query) => $query->where(
                    'movement_type',
                    $validated['movement_type']
                )
            )
            ->when(
                isset($validated['from_date']),
                fn ($query) => $query->whereDate(
                    'movement_date',
                    '>=',
                    $validated['from_date']
                )
            )
            ->when(
                isset($validated['to_date']),
                fn ($query) => $query->whereDate(
                    'movement_date',
                    '<=',
                    $validated['to_date']
                )
            )
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $summaryQuery = InventoryMovement::query()
            ->when(
                isset($validated['warehouse_id']),
                fn ($query) => $query->where(
                    'warehouse_id',
                    $validated['warehouse_id']
                )
            )
            ->when(
                isset($validated['product_id']),
                fn ($query) => $query->where(
                    'product_id',
                    $validated['product_id']
                )
            )
            ->when(
                isset($validated['movement_type']),
                fn ($query) => $query->where(
                    'movement_type',
                    $validated['movement_type']
                )
            )
            ->when(
                isset($validated['from_date']),
                fn ($query) => $query->whereDate(
                    'movement_date',
                    '>=',
                    $validated['from_date']
                )
            )
            ->when(
                isset($validated['to_date']),
                fn ($query) => $query->whereDate(
                    'movement_date',
                    '<=',
                    $validated['to_date']
                )
            );

        $summary = [
            'total_movements' => (clone $summaryQuery)->count(),
            'total_quantity_in' => (clone $summaryQuery)->sum('quantity_in'),
            'total_quantity_out' => (clone $summaryQuery)->sum('quantity_out'),
            'total_cost_in' => (clone $summaryQuery)
    ->where('quantity_in', '>', 0)
    ->sum('total_cost'),
        ];

        return response()->json([
            'message' => 'Inventory movement report retrieved successfully.',
            'data' => $movements,
            'summary' => $summary,
        ]);
    }
}
