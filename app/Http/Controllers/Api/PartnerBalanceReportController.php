<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartnerBalanceReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_type' => [
                'nullable',
                Rule::in(['Customer', 'Supplier']),
            ],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = BusinessPartner::query()
            ->where('is_active', true)
            ->with([
                'openingBalances.currency:id,code,name,symbol',
            ])
            ->orderBy('name');

        if (!empty($validated['partner_type'])) {
            $query->where('partner_type', $validated['partner_type']);
        }

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $partners = $query->get();

        $partnerIds = $partners->pluck('id');

        $transactionRows = DB::table('partner_transactions')
            ->join(
                'currencies',
                'currencies.id',
                '=',
                'partner_transactions.currency_id'
            )
            ->whereIn(
                'partner_transactions.business_partner_id',
                $partnerIds
            )
            ->select(
                'partner_transactions.business_partner_id',
                'currencies.id as currency_id',
                'currencies.code as currency_code',
                'currencies.name as currency_name',
                'currencies.symbol as currency_symbol'
            )
            ->selectRaw("
                COALESCE(
                    SUM(
                        CASE
                            WHEN partner_transactions.direction = 'credit'
                            THEN partner_transactions.amount
                            ELSE 0
                        END
                    ), 0
                ) as total_credit
            ")
            ->selectRaw("
                COALESCE(
                    SUM(
                        CASE
                            WHEN partner_transactions.direction = 'debit'
                            THEN partner_transactions.amount
                            ELSE 0
                        END
                    ), 0
                ) as total_debit
            ")
            ->groupBy(
                'partner_transactions.business_partner_id',
                'currencies.id',
                'currencies.code',
                'currencies.name',
                'currencies.symbol'
            )
            ->get()
            ->groupBy('business_partner_id');

        $data = $partners->map(function (BusinessPartner $partner) use (
            $transactionRows
        ) {
            $openingByCurrency = $partner->openingBalances
                ->groupBy('currency_id');

            $transactionsByCurrency = $transactionRows
                ->get($partner->id, collect())
                ->keyBy('currency_id');

            $currencyIds = $openingByCurrency->keys()
                ->merge($transactionsByCurrency->keys())
                ->unique()
                ->values();

            $balances = $currencyIds->map(function ($currencyId) use (
                $partner,
                $openingByCurrency,
                $transactionsByCurrency
            ) {
                $openingItems = $openingByCurrency->get(
                    $currencyId,
                    collect()
                );

                $transaction = $transactionsByCurrency->get($currencyId);

                $openingBalance = (float) $openingItems->sum('amount');

                $totalCredit = (float) ($transaction->total_credit ?? 0);

                $totalDebit = (float) ($transaction->total_debit ?? 0);

                $currency = $openingItems->first()?->currency;

                $currencyCode = $transaction->currency_code
                    ?? $currency?->code;

                $currencyName = $transaction->currency_name
                    ?? $currency?->name;

                $currencySymbol = $transaction->currency_symbol
                    ?? $currency?->symbol;

                // Customer: positive balance means customer owes us.
                // Supplier: positive balance means we owe supplier.
                if ($partner->partner_type === 'Customer') {
                    $balance = $openingBalance + $totalDebit - $totalCredit;

                    $balanceType = $balance > 0
                        ? 'customer_owes_us'
                        : ($balance < 0
                            ? 'we_owe_customer'
                            : 'settled');
                } else {
                    $balance = $openingBalance + $totalCredit - $totalDebit;

                    $balanceType = $balance > 0
                        ? 'we_owe_supplier'
                        : ($balance < 0
                            ? 'supplier_owes_us'
                            : 'settled');
                }

                return [
                    'currency' => $currencyCode,
                    'currency_name' => $currencyName,
                    'currency_symbol' => $currencySymbol,
                    'opening_balance' => round($openingBalance, 4),
                    'total_credit' => round($totalCredit, 4),
                    'total_debit' => round($totalDebit, 4),
                    'balance' => round($balance, 4),
                    'balance_type' => $balanceType,
                ];
            });

            return [
                'id' => $partner->id,
                'partner_type' => $partner->partner_type,
                'name' => $partner->name,
                'business_name' => $partner->business_name,
                'phone' => $partner->phone,
                'balances' => $balances,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }
}
