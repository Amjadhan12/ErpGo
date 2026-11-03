<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\OpeningBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class OpeningBalanceController extends Controller
{
    public function index(): JsonResponse
    {
        $balances = OpeningBalance::with([
            'financialAccount.currency',
            'creator:id,name',
        ])
            ->orderByDesc('opening_date')
            ->get();

        return response()->json([
            'data' => $balances,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'financial_account_id' => [
                'required',
                'integer',
                'exists:financial_accounts,id',
            ],
            'amount' => [
                'required',
                'numeric',
                'min:0',
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

        $account = FinancialAccount::findOrFail(
            $validated['financial_account_id']
        );

        if (! $account->is_active) {
            return response()->json([
                'message' => 'Cannot add an opening balance to an inactive account.',
            ], 422);
        }

        $openingBalance = DB::transaction(function () use (
            $validated,
            $request
        ) {
            $exists = OpeningBalance::where(
                'financial_account_id',
                $validated['financial_account_id']
            )
                ->where('opening_date', $validated['opening_date'])
                ->exists();

            if ($exists) {
                return null;
            }

            return OpeningBalance::create([
                'financial_account_id' => $validated['financial_account_id'],
                'amount' => $validated['amount'],
                'opening_date' => $validated['opening_date'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        });

        if (! $openingBalance) {
            return response()->json([
                'message' => 'An opening balance already exists for this account and date.',
            ], 409);
        }

        return response()->json([
            'message' => 'Opening balance created successfully.',
            'data' => $openingBalance->load('financialAccount.currency'),
        ], 201);
    }
}
