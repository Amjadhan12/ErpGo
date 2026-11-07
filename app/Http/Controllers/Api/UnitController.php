<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $units = Unit::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('short_name', 'like', '%' . $request->search . '%');
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
            'message' => 'Units retrieved successfully.',
            'data' => $units,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:units,name'],
            'short_name' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $unit = Unit::create($validated);

        return response()->json([
            'message' => 'Unit created successfully.',
            'data' => $unit,
        ], 201);
    }

    public function show(Unit $unit): JsonResponse
    {
        return response()->json([
            'message' => 'Unit retrieved successfully.',
            'data' => $unit,
        ]);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('units', 'name')->ignore($unit->id),
            ],
            'short_name' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $unit->update($validated);

        return response()->json([
            'message' => 'Unit updated successfully.',
            'data' => $unit->fresh(),
        ]);
    }

    public function deactivate(Unit $unit): JsonResponse
    {
        $unit->update(['is_active' => false]);

        return response()->json([
            'message' => 'Unit deactivated successfully.',
            'data' => $unit->fresh(),
        ]);
    }
}
