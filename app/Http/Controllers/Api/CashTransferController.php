<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashTransfer;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\OpeningBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashTransferController extends Controller
{
    public function index(): JsonResponse
    {
        $transfers = CashTransfer::with([
            'fromAccount.currency',
            'toAccount.currency',
            'currency',
            'creator',
        ])
            ->latest('transfer_date')
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'message' => 'Cash transfers retrieved successfully.',
            'data' => $transfers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transfer_number' => [
                'required',
                'string',
                'max:50',
                'unique:cash_transfers,transfer_number',
            ],
            'from_account_id' => [
                'required',
                'integer',
                'different:to_account_id',
                'exists:financial_accounts,id',
            ],
            'to_account_id' => [
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
            'transfer_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        $transfer = DB::transaction(function () use (
            $validated,
            $request
        ) {
            $fromId = (int) $validated['from_account_id'];
            $toId = (int) $validated['to_account_id'];

            // Lock both accounts in a consistent order.
            $accounts = FinancialAccount::query()
                ->whereIn('id', [$fromId, $toId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $fromAccount = $accounts->get($fromId);
            $toAccount = $accounts->get($toId);

            if (!$fromAccount || !$toAccount) {
                throw ValidationException::withMessages([
                    'financial_account_id' => [
                        'One or both financial accounts were not found.',
                    ],
                ]);
            }

            if (!$fromAccount->is_active || !$toAccount->is_active) {
                throw ValidationException::withMessages([
                    'financial_account_id' => [
                        'Both financial accounts must be active.',
                    ],
                ]);
            }

            if ($fromId === $toId) {
                throw ValidationException::withMessages([
                    'to_account_id' => [
                        'Source and destination accounts must be different.',
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

            // This version supports same-currency transfers only.
            if (
                (int) $fromAccount->currency_id !== (int) $currency->id ||
                (int) $toAccount->currency_id !== (int) $currency->id
            ) {
                throw ValidationException::withMessages([
                    'currency_id' => [
                        'Both accounts must use the selected currency. '
                        . 'Cross-currency transfers are not supported.',
                    ],
                ]);
            }

            $transferDate = $validated['transfer_date'];

            $openingBalance = (float) OpeningBalance::query()
                ->where('financial_account_id', $fromId)
                ->whereDate('opening_date', '<=', $transferDate)
                ->sum('amount');

            $totalIn = (float) FinancialTransaction::query()
                ->where('financial_account_id', $fromId)
                ->where('direction', 'in')
                ->whereDate('transaction_date', '<=', $transferDate)
                ->sum('amount');

            $totalOut = (float) FinancialTransaction::query()
                ->where('financial_account_id', $fromId)
                ->where('direction', 'out')
                ->whereDate('transaction_date', '<=', $transferDate)
                ->sum('amount');

            $availableBalance = $openingBalance + $totalIn - $totalOut;

            $amount = (float) $validated['amount'];

            if ($amount > $availableBalance) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Insufficient source account balance. Available balance is '
                        . number_format($availableBalance, 4)
                        . ' '
                        . $currency->code
                        . '.',
                    ],
                ]);
            }

            $transfer = CashTransfer::create([
                'transfer_number' => $validated['transfer_number'],
                'from_account_id' => $fromId,
                'to_account_id' => $toId,
                'currency_id' => $currency->id,
                'amount' => $validated['amount'],
                'transfer_date' => $transferDate,
                'description' => $validated['description'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $group = (string) Str::uuid();

            // Money leaves the source account.
            FinancialTransaction::create([
                'financial_account_id' => $fromId,
                'related_account_id' => $toId,
                'created_by' => $request->user()->id,
                'transaction_type' => 'cash_transfer',
                'direction' => 'out',
                'amount' => $validated['amount'],
                'exchange_rate' => 1,
                'transaction_group' => $group,
                'reference_type' => CashTransfer::class,
                'reference_id' => $transfer->id,
                'transaction_date' => $transferDate . ' 00:00:00',
                'description' => 'Cash transfer '
                    . $transfer->transfer_number
                    . ' to '
                    . $toAccount->name,
            ]);

            // Money enters the destination account.
            FinancialTransaction::create([
                'financial_account_id' => $toId,
                'related_account_id' => $fromId,
                'created_by' => $request->user()->id,
                'transaction_type' => 'cash_transfer',
                'direction' => 'in',
                'amount' => $validated['amount'],
                'exchange_rate' => 1,
                'transaction_group' => $group,
                'reference_type' => CashTransfer::class,
                'reference_id' => $transfer->id,
                'transaction_date' => $transferDate . ' 00:00:00',
                'description' => 'Cash transfer '
                    . $transfer->transfer_number
                    . ' from '
                    . $fromAccount->name,
            ]);

            return $transfer->load([
                'fromAccount.currency',
                'toAccount.currency',
                'currency',
                'creator',
            ]);
        });

        return response()->json([
            'message' => 'Cash transfer created successfully.',
            'data' => $transfer,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $transfer = CashTransfer::with([
            'fromAccount.currency',
            'toAccount.currency',
            'currency',
            'creator',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Cash transfer retrieved successfully.',
            'data' => $transfer,
        ]);
    }
}
