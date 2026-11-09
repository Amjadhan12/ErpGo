<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\OpeningBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashFlowReportController extends Controller
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

        $fromDate = $validated['from_date'] ?? null;
        $toDate = $validated['to_date'] ?? null;

        $accounts = FinancialAccount::query()
            ->with('currency')
            ->where('is_active', true)
            ->when(
                isset($validated['financial_account_id']),
                fn ($q) => $q->where(
                    'id',
                    $validated['financial_account_id']
                )
            )
            ->when(
                isset($validated['currency_id']),
                fn ($q) => $q->where(
                    'currency_id',
                    $validated['currency_id']
                )
            )
            ->orderBy('id')
            ->get();

        $accountIds = $accounts->pluck('id');

        // Opening balances before the selected period.
        $openingQuery = OpeningBalance::query()
            ->whereIn('financial_account_id', $accountIds);

        if ($fromDate) {
            $openingQuery->whereDate('opening_date', '<', $fromDate);
        }

        $openingBalances = $openingQuery
            ->select(
                'financial_account_id',
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('financial_account_id')
            ->pluck('total', 'financial_account_id');

        // Transactions before the selected period.
        $previousQuery = FinancialTransaction::query()
            ->whereIn('financial_account_id', $accountIds);

        if ($fromDate) {
            $previousQuery->whereDate('transaction_date', '<', $fromDate);
        }

        if ($toDate) {
            $previousQuery->whereDate('transaction_date', '<=', $toDate);
        }

        $previousTotals = $previousQuery
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

        $startingBalances = [];

        foreach ($accounts as $account) {
            $opening = (float) ($openingBalances[$account->id] ?? 0);
           $previous = $fromDate
    ? $previousTotals->get($account->id)
    : null;

            $startingBalances[$account->id] =
                $opening
                + (float) ($previous?->total_in ?? 0)
                - (float) ($previous?->total_out ?? 0);
        }

        // Transactions within the selected period.
        $transactionsQuery = FinancialTransaction::query()
            ->with('financialAccount.currency')
            ->whereIn('financial_account_id', $accountIds)
            ->when($fromDate, fn ($q) =>
                $q->whereDate('transaction_date', '>=', $fromDate)
            )
            ->when($toDate, fn ($q) =>
                $q->whereDate('transaction_date', '<=', $toDate)
            );

        $transactions = (clone $transactionsQuery)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        // External cash flows exclude internal account transfers.
        $externalTransactions = $transactions->where(
            'transaction_type',
            '!=',
            'cash_transfer'
        );

        $totalInByAccount = $externalTransactions
            ->where('direction', 'in')
            ->groupBy('financial_account_id')
            ->map(fn ($rows) => $rows->sum(
                fn ($row) => (float) $row->amount
            ));

        $totalOutByAccount = $externalTransactions
            ->where('direction', 'out')
            ->groupBy('financial_account_id')
            ->map(fn ($rows) => $rows->sum(
                fn ($row) => (float) $row->amount
            ));

        $transferInByAccount = $transactions
            ->where('transaction_type', 'cash_transfer')
            ->where('direction', 'in')
            ->groupBy('financial_account_id')
            ->map(fn ($rows) => $rows->sum(
                fn ($row) => (float) $row->amount
            ));

        $transferOutByAccount = $transactions
            ->where('transaction_type', 'cash_transfer')
            ->where('direction', 'out')
            ->groupBy('financial_account_id')
            ->map(fn ($rows) => $rows->sum(
                fn ($row) => (float) $row->amount
            ));

        // Summaries grouped by currency.
        $summaryByCurrency = $accounts
            ->groupBy(fn ($account) =>
                $account->currency?->code ?? 'UNKNOWN'
            )
            ->map(function ($currencyAccounts, $currencyCode) use (
                $startingBalances,
                $totalInByAccount,
                $totalOutByAccount,
                $transferInByAccount,
                $transferOutByAccount
            ) {
                $opening = 0;
                $totalIn = 0;
                $totalOut = 0;
                $transferIn = 0;
                $transferOut = 0;

                foreach ($currencyAccounts as $account) {
                    $id = $account->id;

                    $opening += $startingBalances[$id] ?? 0;
                    $totalIn += (float) ($totalInByAccount[$id] ?? 0);
                    $totalOut += (float) ($totalOutByAccount[$id] ?? 0);
                    $transferIn += (float) ($transferInByAccount[$id] ?? 0);
                    $transferOut += (float) ($transferOutByAccount[$id] ?? 0);
                }

                return [
                    'currency' => $currencyCode,
                    'opening_balance' => number_format($opening, 4, '.', ''),
                    'operating_cash_inflow' => number_format($totalIn, 4, '.', ''),
                    'operating_cash_outflow' => number_format($totalOut, 4, '.', ''),
                    'net_cash_flow' => number_format(
                        $totalIn - $totalOut,
                        4,
                        '.',
                        ''
                    ),
                    'internal_transfer_in' => number_format($transferIn, 4, '.', ''),
                    'internal_transfer_out' => number_format($transferOut, 4, '.', ''),
                    'closing_balance' => number_format(
                        $opening + $totalIn - $totalOut + $transferIn - $transferOut,
                        4,
                        '.',
                        ''
                    ),
                ];
            })
            ->values();

        // Individual account summaries.
        $accountSummaries = $accounts->map(function ($account) use (
            $startingBalances,
            $totalInByAccount,
            $totalOutByAccount,
            $transferInByAccount,
            $transferOutByAccount
        ) {
            $id = $account->id;
            $opening = $startingBalances[$id] ?? 0;
            $totalIn = (float) ($totalInByAccount[$id] ?? 0);
            $totalOut = (float) ($totalOutByAccount[$id] ?? 0);
            $transferIn = (float) ($transferInByAccount[$id] ?? 0);
            $transferOut = (float) ($transferOutByAccount[$id] ?? 0);

            return [
                'financial_account_id' => $id,
                'account_name' => $account->name,
                'currency' => $account->currency?->code,
                'opening_balance' => number_format($opening, 4, '.', ''),
                'cash_inflow' => number_format($totalIn, 4, '.', ''),
                'cash_outflow' => number_format($totalOut, 4, '.', ''),
                'net_cash_flow' => number_format(
                    $totalIn - $totalOut,
                    4,
                    '.',
                    ''
                ),
                'internal_transfer_in' => number_format($transferIn, 4, '.', ''),
                'internal_transfer_out' => number_format($transferOut, 4, '.', ''),
                'closing_balance' => number_format(
                    $opening + $totalIn - $totalOut
                        + $transferIn - $transferOut,
                    4,
                    '.',
                    ''
                ),
            ];
        })->values();

        // Transaction type breakdown, excluding internal transfers.
       
// Transaction type breakdown, grouped by currency and type.
$typeBreakdown = $externalTransactions
    ->groupBy(fn ($row) =>
        ($row->financialAccount?->currency?->code ?? 'UNKNOWN')
        . '|'
        . $row->transaction_type
    )
    ->map(function ($rows) {
        $first = $rows->first();

        return [
            'transaction_type' => $first?->transaction_type,
            'currency' => $first?->financialAccount?->currency?->code,
            'cash_inflow' => number_format(
                $rows->where('direction', 'in')->sum(
                    fn ($row) => (float) $row->amount
                ),
                4,
                '.',
                ''
            ),
            'cash_outflow' => number_format(
                $rows->where('direction', 'out')->sum(
                    fn ($row) => (float) $row->amount
                ),
                4,
                '.',
                ''
            ),
            'transactions_count' => $rows->count(),
        ];
    })
    ->values();


        // Paginated transaction details.
        $perPage = (int) ($validated['per_page'] ?? 20);
        $currentPage = max(1, (int) $request->query('page', 1));

        $rows = $transactions->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'financial_account_id' => $transaction->financial_account_id,
                'account_name' => $transaction->financialAccount?->name,
                'currency' => $transaction->financialAccount?->currency?->code,
                'transaction_date' => $transaction->transaction_date,
                'transaction_type' => $transaction->transaction_type,
                'direction' => $transaction->direction,
                'amount' => $transaction->amount,
                'exchange_rate' => $transaction->exchange_rate,
                'is_internal_transfer' =>
                    $transaction->transaction_type === 'cash_transfer',
                'description' => $transaction->description,
                'reference_type' => $transaction->reference_type,
                'reference_id' => $transaction->reference_id,
            ];
        });

        return response()->json([
            'message' => 'Cash flow report retrieved successfully.',
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
                'transaction_type_breakdown' => $typeBreakdown,
                'transactions' => [
                    'current_page' => $currentPage,
                    'per_page' => $perPage,
                    'total' => $rows->count(),
                    'last_page' => max(
                        1,
                        (int) ceil($rows->count() / $perPage)
                    ),
                    'data' => $rows->forPage($currentPage, $perPage)->values(),
                ],
            ],
        ]);
    }
}
