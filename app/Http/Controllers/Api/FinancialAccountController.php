<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialAccountController extends Controller
{
    public function balances(Request $request): JsonResponse
    {
        $accounts = FinancialAccount::query()
            ->with('currency')
            ->where('is_active', true)
            ->when(
                $request->filled('currency_id'),
                fn ($query) => $query->where(
                    'currency_id',
                    $request->integer('currency_id')
                )
            )
            ->orderBy('currency_id')
            ->orderBy('name')
            ->get();

        $data = $accounts->map(function (FinancialAccount $account) {

            $openingBalance = (float) $account->openingBalances()
                ->sum('amount');

            $totalIn = (float) $account->transactions()
                ->where('direction', 'in')
                ->sum('amount');

            $totalOut = (float) $account->transactions()
                ->where('direction', 'out')
                ->sum('amount');

            $currentBalance = $openingBalance + $totalIn - $totalOut;

            return [
                'id' => $account->id,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'holder_type' => $account->holder_type,

                'currency' => [
                    'id' => $account->currency->id,
                    'code' => $account->currency->code,
                    'name' => $account->currency->name,
                    'symbol' => $account->currency->symbol,
                ],

                'opening_balance' => round($openingBalance, 4),
                'total_in' => round($totalIn, 4),
                'total_out' => round($totalOut, 4),
                'current_balance' => round($currentBalance, 4),
            ];
        });

        return response()->json([
            'message' => 'Financial account balances retrieved successfully.',
            'data' => $data,
            'summary' => [
                'accounts_count' => $data->count(),
            ],
        ]);
    }
}
