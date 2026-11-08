<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialAccountLedgerReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'direction' => ['nullable', 'in:in,out'],
            'transaction_type' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $account = FinancialAccount::with('currency')
            ->findOrFail($validated['financial_account_id']);

        $fromDate = $validated['from_date'] ?? null;
        $toDate = $validated['to_date'] ?? null;

        // Opening balances before the selected period.
        $openingBalanceQuery = DB::table('opening_balances')
            ->where('financial_account_id', $account->id);

        if ($fromDate) {
            $openingBalanceQuery->whereDate('opening_date', '<', $fromDate);
        }

        $openingBalance = (float) $openingBalanceQuery->sum('amount');

        // Transactions before the selected period are included
        // in the starting balance.
        $previousTransactionsQuery = FinancialTransaction::query()
            ->where('financial_account_id', $account->id);

        if ($fromDate) {
            $previousTransactionsQuery->whereDate('transaction_date', '<', $fromDate);
        }

        if ($toDate) {
            $previousTransactionsQuery->whereDate('transaction_date', '<=', $toDate);
        }

       $previousIn = 0;
$previousOut = 0;

if ($fromDate) {
    $previousIn = (float) (clone $previousTransactionsQuery)
        ->where('direction', 'in')
        ->sum('amount');

    $previousOut = (float) (clone $previousTransactionsQuery)
        ->where('direction', 'out')
        ->sum('amount');
}

$startingBalance = $openingBalance + $previousIn - $previousOut;

        // Transactions inside the selected period.
   
// All transactions inside the selected date period.
$periodTransactionsQuery = FinancialTransaction::query()
    ->where('financial_account_id', $account->id)
    ->when($fromDate, fn ($query) =>
        $query->whereDate('transaction_date', '>=', $fromDate)
    )
    ->when($toDate, fn ($query) =>
        $query->whereDate('transaction_date', '<=', $toDate)
    );

// Summary must include all transactions in the date period.
$totalIn = (float) (clone $periodTransactionsQuery)
    ->where('direction', 'in')
    ->sum('amount');

$totalOut = (float) (clone $periodTransactionsQuery)
    ->where('direction', 'out')
    ->sum('amount');

// Calculate running balance using ALL transactions.
$allTransactions = (clone $periodTransactionsQuery)
    ->orderBy('transaction_date')
    ->orderBy('id')
    ->get();

$runningBalance = $startingBalance;

$ledgerRows = $allTransactions->map(function ($transaction) use (&$runningBalance) {
    if ($transaction->direction === 'in') {
        $runningBalance += (float) $transaction->amount;
    } else {
        $runningBalance -= (float) $transaction->amount;
    }

    return [
        'id' => $transaction->id,
        'transaction_date' => $transaction->transaction_date,
        'transaction_type' => $transaction->transaction_type,
        'direction' => $transaction->direction,
        'amount' => $transaction->amount,
        'description' => $transaction->description,
        'reference_type' => $transaction->reference_type,
        'reference_id' => $transaction->reference_id,
        'transaction_group' => $transaction->transaction_group,
        'running_balance' => number_format($runningBalance, 4, '.', ''),
    ];
});

// Apply display filters AFTER calculating running balances.
$filteredLedgerRows = $ledgerRows->filter(function ($row) use ($validated) {
    if (
        isset($validated['direction']) &&
        $row['direction'] !== $validated['direction']
    ) {
        return false;
    }

    if (
        isset($validated['transaction_type']) &&
        $row['transaction_type'] !== $validated['transaction_type']
    ) {
        return false;
    }

    return true;
})->values();

$perPage = $validated['per_page'] ?? 20;
$currentPage = max(1, (int) $request->query('page', 1));

$paginatedRows = $filteredLedgerRows
    ->forPage($currentPage, $perPage)
    ->values();

$closingBalance = $startingBalance + $totalIn - $totalOut;


        return response()->json([
            'message' => 'Financial account ledger retrieved successfully.',
            'data' => [
                'account' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'account_type' => $account->account_type,
                    'currency' => $account->currency?->code,
                ],
                'filters' => [
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'direction' => $validated['direction'] ?? null,
                    'transaction_type' => $validated['transaction_type'] ?? null,
                ],
                'summary' => [
                    'opening_balance' => number_format($startingBalance, 4, '.', ''),
                    'total_in' => number_format($totalIn, 4, '.', ''),
                    'total_out' => number_format($totalOut, 4, '.', ''),
                    'closing_balance' => number_format($closingBalance, 4, '.', ''),
                    'currency' => $account->currency?->code,
                ],
                'transactions' => [
                    'current_page' => $currentPage,
                    'per_page' => $perPage,
                    'total' => $ledgerRows->count(),
                    'last_page' => max(1, (int) ceil($ledgerRows->count() / $perPage)),
                    'data' => $paginatedRows,
                ],
            ],
        ]);
    }
}
