<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerBalanceReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:business_partners,id'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
        ]);

        $customers = BusinessPartner::query()
            ->where('partner_type', 'customer')
            ->where('is_active', true)
            ->when(
                isset($validated['customer_id']),
                fn ($query) => $query->where('id', $validated['customer_id'])
            )
            ->with([
                'openingBalances.currency',
            ])
            ->with(['openingBalances' => function ($query) use ($validated) {
                $query->when(
                    isset($validated['currency_id']),
                    fn ($q) => $q->where('currency_id', $validated['currency_id'])
                );
            }])
            ->with(['openingBalances.currency'])
            ->with(['openingBalances'])
            ->with(['openingBalances'])
            ->with(['openingBalances'])
            ->with(['openingBalances'])
            ->get();

        $data = $customers->map(function (BusinessPartner $customer) use ($validated) {
            $transactions = $customer->openingBalances
                ->groupBy('currency_id');

            $currencyIds = $transactions->keys();

            $partnerTransactions = $customer->transactions()
                ->when(
                    isset($validated['currency_id']),
                    fn ($query) => $query->where('currency_id', $validated['currency_id'])
                )
                ->get()
                ->groupBy('currency_id');

            $allCurrencyIds = $currencyIds
                ->merge($partnerTransactions->keys())
                ->unique()
                ->values();

            $balances = $allCurrencyIds->map(function ($currencyId) use (
                $transactions,
                $partnerTransactions
            ) {
                $opening = (float) $transactions
                    ->get($currencyId, collect())
                    ->sum('amount');

                $items = $partnerTransactions->get($currencyId, collect());

                $debit = (float) $items
                    ->where('direction', 'debit')
                    ->sum('amount');

                $credit = (float) $items
                    ->where('direction', 'credit')
                    ->sum('amount');

                $currentBalance = $opening + $debit - $credit;

                $currency = $items->first()?->currency
                    ?? $transactions->get($currencyId)?->first()?->currency;

                return [
                    'currency_id' => $currencyId,
                    'currency_code' => $currency?->code,
                    'currency_name' => $currency?->name,
                    'opening_balance' => round($opening, 4),
                    'total_debit' => round($debit, 4),
                    'total_credit' => round($credit, 4),
                    'current_balance' => round($currentBalance, 4),
                    'balance_type' => $currentBalance > 0
                        ? 'customer_owes_us'
                        : ($currentBalance < 0 ? 'we_owe_customer' : 'settled'),
                ];
            });

            return [
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'business_name' => $customer->business_name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                ],
                'balances' => $balances,
            ];
        });

        return response()->json([
            'message' => 'Customer balance report retrieved successfully.',
            'data' => $data,
            'summary' => [
                'customers_count' => $data->count(),
            ],
        ]);
    }
}
