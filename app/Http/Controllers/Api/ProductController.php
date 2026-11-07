<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * Display all products.
     */
    public function index(Request $request)
    {
        $products = Product::with('category')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('product_category_id'), function ($query) use ($request) {
                $query->where('product_category_id', $request->product_category_id);
            })
            ->when($request->filled('product_type'), function ($query) use ($request) {
                $query->where('product_type', $request->product_type);
            })
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'message' => 'Products retrieved successfully.',
            'data' => $products,
        ]);
    }

    /**
     * Store a new product.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_category_id' => [
                'required',
                'integer',
                Rule::exists('product_categories', 'id')
                    ->where('is_active', true),
            ],
            'sku' => [
                'nullable',
                'string',
                'max:255',
                'unique:products,sku',
            ],
            'name' => ['required', 'string', 'max:255'],
            'product_type' => ['sometimes', 'string', 'max:30'],
            'unit' => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string'],
            'minimum_stock' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $product = Product::create($validated);

        return response()->json([
            'message' => 'Product created successfully.',
            'data' => $product->load('category'),
        ], 201);
    }

    /**
     * Display a specific product.
     */
    public function show(Product $product)
    {
        return response()->json([
            'message' => 'Product retrieved successfully.',
            'data' => $product->load('category'),
        ]);
    }

    /**
     * Update a product.
     */
    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'product_category_id' => [
                'required',
                'integer',
                'exists:product_categories,id',
            ],
            'sku' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'sku')->ignore($product->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'product_type' => ['sometimes', 'string', 'max:30'],
            'unit' => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string'],
            'minimum_stock' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (
            (int) $validated['product_category_id'] !== (int) $product->product_category_id
            && !ProductCategory::where('id', $validated['product_category_id'])
                ->where('is_active', true)
                ->exists()
        ) {
            return response()->json([
                'message' => 'The selected product category is inactive.',
            ], 422);
        }

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully.',
            'data' => $product->fresh()->load('category'),
        ]);
    }

    /**
     * Deactivate a product without deleting its records.
     */
    public function deactivate(Product $product)
    {
        $product->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Product deactivated successfully.',
            'data' => $product->fresh()->load('category'),
        ]);
    }
}