<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerPortalController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        $partner = $user->businessPartner;

        if (!$partner || !$partner->is_active) {
            return response()->json([
                'message' => 'Customer profile not found or inactive.',
            ], 404);
        }

        return response()->json([
            'message' => 'Customer profile retrieved successfully.',
            'data' => [
                'account' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'customer' => [
                    'id' => $partner->id,
                    'name' => $partner->name,
                    'phone' => $partner->phone,
                    'email' => $partner->email,
                    'address' => $partner->address,
                    'business_name' => $partner->business_name,
                    'is_active' => $partner->is_active,
                ],
            ],
        ]);
    }
}