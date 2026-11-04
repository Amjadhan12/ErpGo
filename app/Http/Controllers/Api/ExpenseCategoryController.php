<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    /**
     * Display all expense categories.
     */
    public function index(): JsonResponse
    {
        $categories = ExpenseCategory::query()
            ->withCount('expenses')
            ->orderBy('name')
            ->paginate(20);

        return response()->json([
            'message' => 'Expense categories retrieved successfully.',
            'data' => $categories,
        ]);
    }

    /**
     * Store a new expense category.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:190',
                'unique:expense_categories,name',
            ],
            'details' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        $category = ExpenseCategory::create([
            'name' => $validated['name'],
            'details' => $validated['details'] ?? null,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Expense category created successfully.',
            'data' => $category,
        ], 201);
    }

    /**
     * Show a specific expense category.
     */
    public function show(int $id): JsonResponse
    {
        $category = ExpenseCategory::withCount('expenses')
            ->findOrFail($id);

        return response()->json([
            'data' => $category,
        ]);
    }

    /**
     * Update an expense category.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = ExpenseCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:190',
                Rule::unique('expense_categories', 'name')
                    ->ignore($category->id),
            ],
            'details' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'Expense category updated successfully.',
            'data' => $category->fresh(),
        ]);
    }

    /**
     * Deactivate an expense category.
     */
    public function destroy(int $id): JsonResponse
    {
        $category = ExpenseCategory::findOrFail($id);

        $category->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Expense category deactivated successfully.',
        ]);
    }
}