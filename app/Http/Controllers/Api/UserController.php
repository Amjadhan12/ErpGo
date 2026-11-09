<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * List users with search and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(['Secretary', 'Customer', 'Supplier'])],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->with('businessPartner:id,name,partner_type,business_name')
            ->orderByDesc('id');

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (!empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', $validated['is_active']);
        }

        return response()->json([
            'message' => 'Users retrieved successfully.',
            'data' => $query->paginate($validated['per_page'] ?? 20),
        ]);
    }

    /**
     * Show one user.
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'message' => 'User retrieved successfully.',
            'data' => $user->load('businessPartner'),
        ]);
    }

    /**
     * Update user information and synchronize linked partner.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
                Rule::unique('business_partners', 'email')
                    ->ignore($user->business_partner_id),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'confirmed'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (
            isset($validated['is_active']) &&
            !$validated['is_active'] &&
            $request->user()->id === $user->id
        ) {
            return response()->json([
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        DB::transaction(function () use ($validated, $user) {
            $userData = [];

            foreach (['name', 'email', 'password', 'is_active'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $userData[$field] = $validated[$field];
                }
            }

            $user->update($userData);

            if ($user->businessPartner) {
                $partnerData = [];

                foreach (['name', 'email', 'is_active'] as $field) {
                    if (array_key_exists($field, $validated)) {
                        $partnerData[$field] = $validated[$field];
                    }
                }

                if (!empty($partnerData)) {
                    $user->businessPartner->update($partnerData);
                }
            }

            // Revoke existing tokens when an account is deactivated.
            if (
                array_key_exists('is_active', $validated) &&
                !$validated['is_active']
            ) {
                $user->tokens()->delete();
            }
        });

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $user->fresh()->load('businessPartner'),
        ]);
    }
}
