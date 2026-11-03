<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Currency;
use App\Models\PartnerOpeningBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PartnerOpeningBalanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PartnerOpeningBalance::with([
            'businessPartner:id,partner_type,name,phone',
            'currency:id,code,name,symbol',
            'creator:id,name',
        ])->orderByDesc('opening_date');

        if ($request->filled('business_partner_id')) {
            $query->where(
                'business_partner_id',
                $request->integer('business_partner_id')
            );
        }

        if ($request->filled('partner_type')) {
            $request->validate([
                'partner_type' => ['required', 'in:Customer,Supplier'],
            ]);

            $query->whereHas('businessPartner', function ($partnerQuery) use ($request) {
                $partnerQuery->where('partner_type', $request->partner_type);
            });
        }

        if ($request->filled('currency_id')) {
            $query->where('currency_id', $request->integer('currency_id'));
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_partner_id' => [
                'required',
                'integer',
                'exists:business_partners,id',
            ],
            'currency_id' => [
                'required',
                'integer',
                'exists:currencies,id',
            ],
            'amount' => [
                'required',
                'numeric',
                'gt:0',
                'max:9999999999999999.9999',
            ],
            'opening_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        $partner = BusinessPartner::findOrFail(
            $validated['business_partner_id']
        );

        if (! $partner->is_active) {
            return response()->json([
                'message' => 'Cannot add an opening balance to an inactive partner.',
            ], 422);
        }

        $currency = Currency::findOrFail($validated['currency_id']);

        if (! $currency->is_active) {
            return response()->json([
                'message' => 'Cannot use an inactive currency.',
            ], 422);
        }

        $exists = PartnerOpeningBalance::where(
            'business_partner_id',
            $validated['business_partner_id']
        )
            ->where('currency_id', $validated['currency_id'])
            ->where('opening_date', $validated['opening_date'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'An opening balance already exists for this partner, currency and date.',
            ], 409);
        }

        try {
            $balance = PartnerOpeningBalance::create([
                ...$validated,
                'created_by' => $request->user()->id,
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'An opening balance already exists for this partner, currency and date.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Partner opening balance created successfully.',
            'data' => $balance->load([
                'businessPartner',
                'currency',
                'creator:id,name',
            ]),
        ], 201);
    }
}