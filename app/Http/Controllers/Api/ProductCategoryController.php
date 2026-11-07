<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductCategoryController extends Controller
{
    /**
     * Display a listing of product categories.
     */
    public function index(Request $request)
    {
        $categories = ProductCategory::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->search . '%');
            })
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'message' => 'Product categories retrieved successfully.',
            'data' => $categories,
        ]);
    }

    /**
     * Store a newly created product category.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:product_categories,name'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = ProductCategory::create($validated);

        return response()->json([
            'message' => 'Product category created successfully.',
            'data' => $category,
        ], 201);
    }

    /**
     * Display the specified product category.
     */
    public function show(ProductCategory $productCategory)
    {
        return response()->json([
            'message' => 'Product category retrieved successfully.',
            'data' => $productCategory,
        ]);
    }

    /**
     * Update the specified product category.
     */
    public function update(Request $request, ProductCategory $productCategory)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_categories', 'name')->ignore($productCategory->id),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $productCategory->update($validated);

        return response()->json([
            'message' => 'Product category updated successfully.',
            'data' => $productCategory->fresh(),
        ]);
    }

    /**
     * Deactivate the specified product category.
     */
    public function deactivate(ProductCategory $productCategory)
    {
        $productCategory->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Product category deactivated successfully.',
            'data' => $productCategory->fresh(),
        ]);
    }
}