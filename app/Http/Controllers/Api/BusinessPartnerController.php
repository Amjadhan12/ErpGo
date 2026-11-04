<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        'email' => [
            'required',
            'email',
            'max:255',
            'unique:business_partners,email',
            'unique:users,email',
        ],
        'password' => ['required', 'string', 'min:8', 'confirmed'],
        'address' => ['nullable', 'string', 'max:255'],
        'business_name' => ['nullable', 'string', 'max:255'],
        'tax_number' => ['nullable', 'string', 'max:255'],
        'notes' => ['nullable', 'string', 'max:5000'],
        'is_active' => ['sometimes', 'boolean'],
    ]);

    $result = DB::transaction(function () use ($validated) {
        $partner = BusinessPartner::create([
            'partner_type' => $validated['partner_type'],
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'],
            'address' => $validated['address'] ?? null,
            'business_name' => $validated['business_name'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['partner_type'],
            'business_partner_id' => $partner->id,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return [
            'partner' => $partner,
            'user' => $user,
        ];
    });

    return response()->json([
        'message' => 'Business partner and login account created successfully.',
        'data' => [
            'partner' => $result['partner'],
            'account' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'role' => $result['user']->role,
                'is_active' => $result['user']->is_active,
            ],
        ],
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