<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    public function balance(Request $request): JsonResponse
{
    $user = $request->user();
    $partner = $user->businessPartner;

    if (!$partner || !$partner->is_active) {
        return response()->json([
            'message' => 'Customer account not found or inactive.',
        ], 404);
    }

    $partner->load([
        'openingBalances.currency:id,code,name,symbol',
    ]);

    $transactions = DB::table('partner_transactions')
        ->join(
            'currencies',
            'currencies.id',
            '=',
            'partner_transactions.currency_id'
        )
        ->where('partner_transactions.business_partner_id', $partner->id)
        ->select(
            'currencies.id as currency_id',
            'currencies.code',
            'currencies.name',
            'currencies.symbol'
        )
        ->selectRaw("
            SUM(CASE WHEN direction = 'credit'
                THEN amount ELSE 0 END) as total_credit
        ")
        ->selectRaw("
            SUM(CASE WHEN direction = 'debit'
                THEN amount ELSE 0 END) as total_debit
        ")
        ->groupBy(
            'currencies.id',
            'currencies.code',
            'currencies.name',
            'currencies.symbol'
        )
        ->get()
        ->keyBy('currency_id');

    $openingBalances = $partner->openingBalances->groupBy('currency_id');

    $currencyIds = $openingBalances->keys()
        ->merge($transactions->keys())
        ->unique();

    $balances = $currencyIds->map(function ($currencyId) use (
        $openingBalances,
        $transactions
    ) {
        $opening = $openingBalances->get($currencyId, collect());
        $transaction = $transactions->get($currencyId);

        $currency = $opening->first()?->currency;

        $openingAmount = (float) $opening->sum('amount');
        $credit = (float) ($transaction->total_credit ?? 0);
        $debit = (float) ($transaction->total_debit ?? 0);

        $balance = $openingAmount + $debit - $credit;

        return [
            'currency' => $transaction->code ?? $currency?->code,
            'currency_name' => $transaction->name ?? $currency?->name,
            'currency_symbol' => $transaction->symbol ?? $currency?->symbol,
            'opening_balance' => round($openingAmount, 4),
            'total_credit' => round($credit, 4),
            'total_debit' => round($debit, 4),
            'balance' => round($balance, 4),
            'balance_type' => $balance > 0
                ? 'customer_owes_us'
                : ($balance < 0 ? 'we_owe_customer' : 'settled'),
        ];
    })->values();

    return response()->json([
        'message' => 'Customer balance retrieved successfully.',
        'data' => [
            'customer_id' => $partner->id,
            'customer_name' => $partner->name,
            'balances' => $balances,
        ],
    ]);
}
}