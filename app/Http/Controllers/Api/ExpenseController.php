<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\OpeningBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(): JsonResponse
    {
        $expenses = Expense::with([
            'category',
            'financialAccount.currency',
            'currency',
            'creator',
        ])
            ->latest('expense_date')
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'message' => 'Expenses retrieved successfully.',
            'data' => $expenses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'expense_number' => [
                'required',
                'string',
                'max:50',
                'unique:expenses,expense_number',
            ],
            'expense_category_id' => [
                'required',
                'integer',
                'exists:expense_categories,id',
            ],
            'financial_account_id' => [
                'required',
                'integer',
                'exists:financial_accounts,id',
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
                'decimal:0,4',
            ],
            'exchange_rate_to_afn' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,8',
            ],
            'expense_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        $expense = DB::transaction(function () use (
            $validated,
            $request
        ) {
            $category = ExpenseCategory::query()
                ->lockForUpdate()
                ->findOrFail($validated['expense_category_id']);

            if (!$category->is_active) {
                throw ValidationException::withMessages([
                    'expense_category_id' => [
                        'The selected expense category is inactive.',
                    ],
                ]);
            }

            $account = FinancialAccount::query()
                ->lockForUpdate()
                ->findOrFail($validated['financial_account_id']);

            if (!$account->is_active) {
                throw ValidationException::withMessages([
                    'financial_account_id' => [
                        'The selected financial account is inactive.',
                    ],
                ]);
            }

            $currency = Currency::query()
                ->findOrFail($validated['currency_id']);

            if (!$currency->is_active) {
                throw ValidationException::withMessages([
                    'currency_id' => [
                        'The selected currency is inactive.',
                    ],
                ]);
            }

            if ((int) $account->currency_id !== (int) $currency->id) {
                throw ValidationException::withMessages([
                    'currency_id' => [
                        'The selected currency does not match the financial account currency.',
                    ],
                ]);
            }

            if ($currency->code === 'AFN'
                && (float) $validated['exchange_rate_to_afn'] !== 1.0) {
                throw ValidationException::withMessages([
                    'exchange_rate_to_afn' => [
                        'AFN exchange rate must be exactly 1.',
                    ],
                ]);
            }

            $expenseDate = $validated['expense_date'];

            $openingBalance = OpeningBalance::query()
                ->where('financial_account_id', $account->id)
                ->whereDate('opening_date', '<=', $expenseDate)
                ->sum('amount');

            $totalIn = FinancialTransaction::query()
                ->where('financial_account_id', $account->id)
                ->where('direction', 'in')
                ->whereDate('transaction_date', '<=', $expenseDate)
                ->sum('amount');

            $totalOut = FinancialTransaction::query()
                ->where('financial_account_id', $account->id)
                ->where('direction', 'out')
                ->whereDate('transaction_date', '<=', $expenseDate)
                ->sum('amount');

            $availableBalance = (float) $openingBalance
                + (float) $totalIn
                - (float) $totalOut;

            $amount = (float) $validated['amount'];

            if ($amount > $availableBalance) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Insufficient balance. Available balance is '
                        . number_format($availableBalance, 4)
                        . ' '
                        . $currency->code
                        . '.',
                    ],
                ]);
            }

            $expense = Expense::create([
                'expense_number' => $validated['expense_number'],
                'expense_category_id' => $category->id,
                'financial_account_id' => $account->id,
                'currency_id' => $currency->id,
                'amount' => $validated['amount'],
                'exchange_rate_to_afn' => $validated['exchange_rate_to_afn'],
                'expense_date' => $expenseDate,
                'description' => $validated['description'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            FinancialTransaction::create([
                'financial_account_id' => $account->id,
                'related_account_id' => null,
                'created_by' => $request->user()->id,
                'transaction_type' => 'expense',
                'direction' => 'out',
                'amount' => $validated['amount'],
                'exchange_rate' => $validated['exchange_rate_to_afn'],
                'transaction_group' => (string) Str::uuid(),
                'reference_type' => Expense::class,
                'reference_id' => $expense->id,
                'transaction_date' => $expenseDate . ' 00:00:00',
                'description' => 'Expense '
                    . $expense->expense_number
                    . ' - '
                    . $category->name,
            ]);

            return $expense->load([
                'category',
                'financialAccount.currency',
                'currency',
                'creator',
            ]);
        });

        return response()->json([
            'message' => 'Expense created successfully.',
            'data' => $expense,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $expense = Expense::with([
            'category',
            'financialAccount.currency',
            'currency',
            'creator',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Expense retrieved successfully.',
            'data' => $expense,
        ]);
    }
}
