<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\BusinessPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialSummaryReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
        ]);

        $currencyId = $validated['currency_id'] ?? null;

        // 1. Cash and Bank balances
        $accounts = FinancialAccount::query()
            ->with('currency:id,code,name,symbol')
            ->where('is_active', true)
            ->when($currencyId, fn ($q) =>
                $q->where('currency_id', $currencyId)
            )
            ->orderBy('id')
            ->get();

        $accountIds = $accounts->pluck('id');

        $openingBalances = DB::table('opening_balances')
            ->whereIn('financial_account_id', $accountIds)
            ->select(
                'financial_account_id',
                DB::raw('SUM(amount) as opening_balance')
            )
            ->groupBy('financial_account_id')
            ->pluck('opening_balance', 'financial_account_id');

        $transactionTotals = DB::table('financial_transactions')
            ->whereIn('financial_account_id', $accountIds)
            ->select(
                'financial_account_id',
                DB::raw("
                    SUM(CASE WHEN direction = 'in'
                        THEN amount ELSE 0 END) as total_in
                "),
                DB::raw("
                    SUM(CASE WHEN direction = 'out'
                        THEN amount ELSE 0 END) as total_out
                ")
            )
            ->groupBy('financial_account_id')
            ->get()
            ->keyBy('financial_account_id');

        $cashBankByCurrency = $accounts
            ->groupBy(fn ($account) =>
                $account->currency?->code ?? 'UNKNOWN'
            )
            ->map(function ($currencyAccounts, $currencyCode) use (
                $openingBalances,
                $transactionTotals
            ) {
                $opening = 0;
                $totalIn = 0;
                $totalOut = 0;

                foreach ($currencyAccounts as $account) {
                    $id = $account->id;
                    $totals = $transactionTotals->get($id);

                    $opening += (float) ($openingBalances[$id] ?? 0);
                    $totalIn += (float) ($totals->total_in ?? 0);
                    $totalOut += (float) ($totals->total_out ?? 0);
                }

                return [
                    'currency' => $currencyCode,
                    'opening_balance' => round($opening, 4),
                    'total_in' => round($totalIn, 4),
                    'total_out' => round($totalOut, 4),
                    'balance' => round(
                        $opening + $totalIn - $totalOut,
                        4
                    ),
                ];
            })
            ->values();

        // 2. Customer and Supplier balances
        $partners = BusinessPartner::query()
            ->where('is_active', true)
            ->with('openingBalances.currency:id,code,name,symbol')
            ->orderBy('id')
            ->get();

        $partnerIds = $partners->pluck('id');

        $partnerTransactions = DB::table('partner_transactions')
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
            ->when($currencyId, fn ($q) =>
                $q->where('partner_transactions.currency_id', $currencyId)
            )
            ->select(
                'partner_transactions.business_partner_id',
                'currencies.id as currency_id',
                'currencies.code as currency_code'
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
                'partner_transactions.business_partner_id',
                'currencies.id',
                'currencies.code'
            )
            ->get()
            ->groupBy('business_partner_id');

        $partnerSummary = [];

        foreach (['Customer', 'Supplier'] as $partnerType) {
            $receivables = [];
            $payables = [];

            foreach ($partners->where('partner_type', $partnerType) as $partner) {
                $openingByCurrency = $partner->openingBalances
                    ->when($currencyId, fn ($items) =>
                        $items->where('currency_id', $currencyId)
                    )
                    ->groupBy('currency_id');

                $transactionsByCurrency = $partnerTransactions
                    ->get($partner->id, collect())
                    ->keyBy('currency_id');

                $currencyIds = $openingByCurrency->keys()
                    ->merge($transactionsByCurrency->keys())
                    ->unique();

                foreach ($currencyIds as $id) {
                    $opening = (float) $openingByCurrency
                        ->get($id, collect())
                        ->sum('amount');

                    $transaction = $transactionsByCurrency->get($id);

                    $credit = (float) ($transaction->total_credit ?? 0);
                    $debit = (float) ($transaction->total_debit ?? 0);

                    $currencyCode = $transaction->currency_code
                        ?? $openingByCurrency->get($id)?->first()?->currency?->code
                        ?? 'UNKNOWN';

                    if ($partnerType === 'Customer') {
                        $balance = $opening + $debit - $credit;

                        if ($balance > 0) {
                            $receivables[$currencyCode] =
                                ($receivables[$currencyCode] ?? 0) + $balance;
                        } elseif ($balance < 0) {
                            $payables[$currencyCode] =
                                ($payables[$currencyCode] ?? 0) + abs($balance);
                        }
                    } else {
                        $balance = $opening + $credit - $debit;

                        if ($balance > 0) {
                            $payables[$currencyCode] =
                                ($payables[$currencyCode] ?? 0) + $balance;
                        } elseif ($balance < 0) {
                            $receivables[$currencyCode] =
                                ($receivables[$currencyCode] ?? 0) + abs($balance);
                        }
                    }
                }
            }

            $partnerSummary[$partnerType] = [
                'receivables' => collect($receivables)
                    ->map(fn ($amount) => round($amount, 4)),
                'payables' => collect($payables)
                    ->map(fn ($amount) => round($amount, 4)),
            ];
        }

        return response()->json([
            'message' => 'Financial summary retrieved successfully.',
            'data' => [
                'cash_bank' => $cashBankByCurrency,
                'customers' => $partnerSummary['Customer'],
                'suppliers' => $partnerSummary['Supplier'],
            ],
        ]);
    }
}
