<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\OpeningBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashBookReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'financial_account_id' => [
                'nullable',
                'integer',
                'exists:financial_accounts,id',
            ],
            'currency_id' => [
                'nullable',
                'integer',
                'exists:currencies,id',
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $accountsQuery = FinancialAccount::query()
            ->with('currency')
            ->where('is_active', true)
            ->when(
                isset($validated['financial_account_id']),
                fn ($query) => $query->where(
                    'id',
                    $validated['financial_account_id']
                )
            )
            ->when(
                isset($validated['currency_id']),
                fn ($query) => $query->where(
                    'currency_id',
                    $validated['currency_id']
                )
            )
            ->orderBy('id');

        $accounts = $accountsQuery->get();

        $accountIds = $accounts->pluck('id');

        $fromDate = $validated['from_date'] ?? null;
        $toDate = $validated['to_date'] ?? null;

        // Opening balances and transactions before the selected period.
        $openingBalancesQuery = OpeningBalance::query()
            ->whereIn('financial_account_id', $accountIds);

        if ($fromDate) {
            $openingBalancesQuery->whereDate('opening_date', '<', $fromDate);
        }

        $openingBalances = $openingBalancesQuery
            ->select(
                'financial_account_id',
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('financial_account_id')
            ->pluck('total', 'financial_account_id');

        $previousTransactionsQuery = FinancialTransaction::query()
            ->whereIn('financial_account_id', $accountIds);

        if ($fromDate) {
            $previousTransactionsQuery->whereDate(
                'transaction_date',
                '<',
                $fromDate
            );
        }

        if ($toDate) {
            $previousTransactionsQuery->whereDate(
                'transaction_date',
                '<=',
                $toDate
            );
        }

        $previousTotals = (clone $previousTransactionsQuery)
            ->select(
                'financial_account_id',
                DB::raw("
                    SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END) as total_in
                "),
                DB::raw("
                    SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END) as total_out
                ")
            )
            ->groupBy('financial_account_id')
            ->get()
            ->keyBy('financial_account_id');

        $startingBalances = [];

        foreach ($accounts as $account) {
            $opening = (float) ($openingBalances[$account->id] ?? 0);

            $previous = $previousTotals->get($account->id);

            $previousIn = $fromDate
                ? (float) ($previous?->total_in ?? 0)
                : 0;

            $previousOut = $fromDate
                ? (float) ($previous?->total_out ?? 0)
                : 0;

            $startingBalances[$account->id] =
                $opening + $previousIn - $previousOut;
        }

        // Transactions in the selected period.
        $transactionsQuery = FinancialTransaction::query()
            ->with('financialAccount.currency')
            ->whereIn('financial_account_id', $accountIds)
            ->when($fromDate, fn ($query) =>
                $query->whereDate('transaction_date', '>=', $fromDate)
            )
            ->when($toDate, fn ($query) =>
                $query->whereDate('transaction_date', '<=', $toDate)
            );

        $totalInByAccount = (clone $transactionsQuery)
            ->where('direction', 'in')
            ->select(
                'financial_account_id',
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('financial_account_id')
            ->pluck('total', 'financial_account_id');

        $totalOutByAccount = (clone $transactionsQuery)
            ->where('direction', 'out')
            ->select(
                'financial_account_id',
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('financial_account_id')
            ->pluck('total', 'financial_account_id');

        $allTransactions = (clone $transactionsQuery)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $runningBalances = $startingBalances;

        $ledgerRows = $allTransactions->map(function ($transaction) use (
            &$runningBalances
        ) {
            $accountId = $transaction->financial_account_id;

            if ($transaction->direction === 'in') {
                $runningBalances[$accountId] += (float) $transaction->amount;
            } else {
                $runningBalances[$accountId] -= (float) $transaction->amount;
            }

            return [
                'id' => $transaction->id,
                'financial_account_id' => $accountId,
                'account_name' => $transaction->financialAccount?->name,
                'currency' => $transaction->financialAccount?->currency?->code,
                'transaction_date' => $transaction->transaction_date,
                'transaction_type' => $transaction->transaction_type,
                'direction' => $transaction->direction,
                'amount' => $transaction->amount,
                'description' => $transaction->description,
                'reference_type' => $transaction->reference_type,
                'reference_id' => $transaction->reference_id,
                'running_balance' => number_format(
                    $runningBalances[$accountId],
                    4,
                    '.',
                    ''
                ),
            ];
        });

        $summaryByCurrency = $accounts
            ->groupBy(fn ($account) => $account->currency?->code ?? 'UNKNOWN')
            ->map(function ($currencyAccounts, $currencyCode) use (
                $startingBalances,
                $totalInByAccount,
                $totalOutByAccount
            ) {
                $opening = 0;
                $totalIn = 0;
                $totalOut = 0;

                foreach ($currencyAccounts as $account) {
                    $opening += $startingBalances[$account->id] ?? 0;
                    $totalIn += (float) ($totalInByAccount[$account->id] ?? 0);
                    $totalOut += (float) ($totalOutByAccount[$account->id] ?? 0);
                }

                return [
                    'currency' => $currencyCode,
                    'opening_balance' => number_format($opening, 4, '.', ''),
                    'total_in' => number_format($totalIn, 4, '.', ''),
                    'total_out' => number_format($totalOut, 4, '.', ''),
                    'closing_balance' => number_format(
                        $opening + $totalIn - $totalOut,
                        4,
                        '.',
                        ''
                    ),
                ];
            })
            ->values();

        $accountSummaries = $accounts->map(function ($account) use (
            $startingBalances,
            $totalInByAccount,
            $totalOutByAccount
        ) {
            $opening = $startingBalances[$account->id] ?? 0;
            $totalIn = (float) ($totalInByAccount[$account->id] ?? 0);
            $totalOut = (float) ($totalOutByAccount[$account->id] ?? 0);

            return [
                'financial_account_id' => $account->id,
                'account_name' => $account->name,
                'account_type' => $account->account_type,
                'currency' => $account->currency?->code,
                'opening_balance' => number_format($opening, 4, '.', ''),
                'total_in' => number_format($totalIn, 4, '.', ''),
                'total_out' => number_format($totalOut, 4, '.', ''),
                'closing_balance' => number_format(
                    $opening + $totalIn - $totalOut,
                    4,
                    '.',
                    ''
                ),
            ];
        })->values();

        $perPage = (int) ($validated['per_page'] ?? 20);
        $currentPage = max(1, (int) $request->query('page', 1));

        $paginatedRows = $ledgerRows
            ->forPage($currentPage, $perPage)
            ->values();

        return response()->json([
            'message' => 'Cash book report retrieved successfully.',
            'data' => [
                'filters' => [
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'financial_account_id' =>
                        $validated['financial_account_id'] ?? null,
                    'currency_id' => $validated['currency_id'] ?? null,
                ],
                'summary_by_currency' => $summaryByCurrency,
                'account_summaries' => $accountSummaries,
                'transactions' => [
                    'current_page' => $currentPage,
                    'per_page' => $perPage,
                    'total' => $ledgerRows->count(),
                    'last_page' => max(
                        1,
                        (int) ceil($ledgerRows->count() / $perPage)
                    ),
                    'data' => $paginatedRows,
                ],
            ],
        ]);
    }
}
