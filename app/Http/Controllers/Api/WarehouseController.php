<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $warehouses = Warehouse::query()
            ->withCount('inventoryBalances')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('is_active'), function ($query) use ($request) {
                $query->where(
                    'is_active',
                    filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                );
            })
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'message' => 'Warehouses retrieved successfully.',
            'data' => $warehouses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:warehouses,name'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $warehouse = Warehouse::create($validated);

        return response()->json([
            'message' => 'Warehouse created successfully.',
            'data' => $warehouse,
        ], 201);
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        $warehouse->loadCount('inventoryBalances');

        return response()->json([
            'message' => 'Warehouse retrieved successfully.',
            'data' => $warehouse,
        ]);
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('warehouses', 'name')->ignore($warehouse->id),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $warehouse->update($validated);

        return response()->json([
            'message' => 'Warehouse updated successfully.',
            'data' => $warehouse->fresh(),
        ]);
    }

    public function deactivate(Warehouse $warehouse): JsonResponse
    {
        $hasStock = $warehouse->inventoryBalances()
            ->where('quantity', '>', 0)
            ->exists();

        if ($hasStock) {
            return response()->json([
                'message' => 'This warehouse cannot be deactivated because it still has stock.',
            ], 422);
        }

        $warehouse->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Warehouse deactivated successfully.',
            'data' => $warehouse->fresh(),
        ]);
    }
}
