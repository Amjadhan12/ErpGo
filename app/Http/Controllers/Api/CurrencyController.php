<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CurrencyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currencies = Currency::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('is_active'), function ($query) use ($request) {
                $query->where(
                    'is_active',
                    filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                );
            })
            ->orderByDesc('is_base')
            ->orderBy('code')
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'message' => 'Currencies retrieved successfully.',
            'data' => $currencies,
        ]);
    }

    public function show(Currency $currency): JsonResponse
    {
        return response()->json([
            'message' => 'Currency retrieved successfully.',
            'data' => $currency,
        ]);
    }

    public function update(Request $request, Currency $currency): JsonResponse
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'size:3',
                Rule::unique('currencies', 'code')->ignore($currency->id),
            ],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['required', 'string', 'max:10'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:4'],
            'is_base' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($currency->is_base && ! $validated['is_base']) {
            return response()->json([
                'message' => 'The base currency cannot be changed without a dedicated base-currency migration.',
            ], 422);
        }

        if ($validated['is_base']) {
            $anotherBaseExists = Currency::where('is_base', true)
                ->where('id', '!=', $currency->id)
                ->exists();

            if ($anotherBaseExists) {
                return response()->json([
                    'message' => 'Only one base currency is allowed.',
                ], 422);
            }
        }

        $currency->update($validated);

        return response()->json([
            'message' => 'Currency updated successfully.',
            'data' => $currency->fresh(),
        ]);
    }
}