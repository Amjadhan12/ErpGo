<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessPartnerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BusinessPartner::query()
            ->with('openingBalances.currency')
            ->orderBy('name');

        if ($request->filled('partner_type')) {
            $request->validate([
                'partner_type' => ['required', Rule::in(['Customer', 'Supplier'])],
            ]);

            $query->where('partner_type', $request->partner_type);
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_type' => ['required', Rule::in(['Customer', 'Supplier'])],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $partner = BusinessPartner::create($validated);

        return response()->json([
            'message' => 'Business partner created successfully.',
            'data' => $partner,
        ], 201);
    }

    public function show(BusinessPartner $businessPartner): JsonResponse
    {
        return response()->json([
            'data' => $businessPartner->load('openingBalances.currency'),
        ]);
    }

    public function update(
        Request $request,
        BusinessPartner $businessPartner
    ): JsonResponse {
        $validated = $request->validate([
            'partner_type' => ['sometimes', Rule::in(['Customer', 'Supplier'])],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $businessPartner->update($validated);

        return response()->json([
            'message' => 'Business partner updated successfully.',
            'data' => $businessPartner->fresh(),
        ]);
    }

    public function destroy(BusinessPartner $businessPartner): JsonResponse
    {
        if ($businessPartner->openingBalances()->exists()) {
            return response()->json([
                'message' => 'This partner has opening balances and cannot be deleted. Deactivate it instead.',
            ], 422);
        }

        $businessPartner->delete();

        return response()->json([
            'message' => 'Business partner deleted successfully.',
        ]);
    }
}