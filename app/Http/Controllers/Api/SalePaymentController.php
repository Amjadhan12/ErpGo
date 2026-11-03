<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\OpeningBalance;
use App\Models\PartnerTransaction;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalePaymentController extends Controller
{
    public function index(): JsonResponse
    {
        $payments = SalePayment::with([
            'sale.customer',
            'financialAccount',
            'currency',
            'creator',
        ])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($payments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_number' => [
                'required',
                'string',
                'max:50',
                'unique:sale_payments,payment_number',
            ],
            'sale_id' => [
                'required',
                'integer',
                'exists:sales,id',
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
            'payment_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        $payment = DB::transaction(function () use (
            $validated,
            $request
        ) {
            // Lock the sale to prevent concurrent overpayments.
            $sale = Sale::whereKey($validated['sale_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($sale->status !== 'posted') {
                throw ValidationException::withMessages([
                    'sale_id' => 'Payments can only be recorded for posted sales.',
                ]);
            }

            if ($validated['payment_date'] < $sale->sale_date->format('Y-m-d')) {
                throw ValidationException::withMessages([
                    'payment_date' => 'Payment date cannot be before the sale date.',
                ]);
            }

            if ((int) $sale->currency_id !== (int) $validated['currency_id']) {
                throw ValidationException::withMessages([
                    'currency_id' => 'Payment currency must match the sale currency.',
                ]);
            }

            $account = FinancialAccount::whereKey(
                $validated['financial_account_id']
            )
                ->lockForUpdate()
                ->firstOrFail();

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'The financial account is inactive.',
                ]);
            }

            if ((int) $account->currency_id !== (int) $sale->currency_id) {
                throw ValidationException::withMessages([
                    'financial_account_id' =>
                        'Financial account currency must match the sale currency.',
                ]);
            }

            $currency = Currency::findOrFail($sale->currency_id);

            if (! $currency->is_active) {
                throw ValidationException::withMessages([
                    'currency_id' => 'The currency is inactive.',
                ]);
            }

            if (
                $currency->code === 'AFN' &&
                (float) $validated['exchange_rate_to_afn'] !== 1.0
            ) {
                throw ValidationException::withMessages([
                    'exchange_rate_to_afn' => 'The AFN exchange rate must be 1.',
                ]);
            }

            // Calculate payments already recorded for this sale.
            $paidAmount = (float) SalePayment::where('sale_id', $sale->id)
                ->sum('amount');

            $remainingBalance = (float) $sale->grand_total - $paidAmount;

            if ((float) $validated['amount'] > $remainingBalance) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'Payment cannot exceed the remaining balance of '
                        . $remainingBalance . ' ' . $currency->code . '.',
                ]);
            }

            $payment = SalePayment::create([
                'sale_id' => $sale->id,
                'payment_number' => $validated['payment_number'],
                'financial_account_id' => $account->id,
                'currency_id' => $currency->id,
                'created_by' => $request->user()->id,
                'amount' => $validated['amount'],
                'exchange_rate_to_afn' =>
                    $validated['exchange_rate_to_afn'],
                'payment_date' => $validated['payment_date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $transactionGroup = (string) Str::uuid();

            // Reduce the customer's outstanding receivable balance.
            PartnerTransaction::create([
                'business_partner_id' => $sale->business_partner_id,
                'currency_id' => $sale->currency_id,
                'created_by' => $request->user()->id,
                'transaction_type' => 'payment',
                'direction' => 'credit',
                'amount' => $validated['amount'],
                'reference_type' => SalePayment::class,
                'reference_id' => $payment->id,
                'transaction_date' => $validated['payment_date'],
                'description' => 'Sale payment ' . $payment->payment_number,
            ]);

            // Record money received into the financial account.
            FinancialTransaction::create([
                'financial_account_id' => $account->id,
                'related_account_id' => null,
                'created_by' => $request->user()->id,
                'transaction_type' => 'sale_payment',
                'direction' => 'in',
                'amount' => $validated['amount'],
                'exchange_rate' => $validated['exchange_rate_to_afn'],
                'transaction_group' => $transactionGroup,
                'reference_type' => SalePayment::class,
                'reference_id' => $payment->id,
                'transaction_date' => $validated['payment_date'],
                'description' => 'Payment received for sale '
                    . $sale->sale_number
                    . ' - '
                    . $payment->payment_number,
            ]);

            $totalPaid = $paidAmount + (float) $validated['amount'];

            $sale->update([
                'payment_status' => $totalPaid >= (float) $sale->grand_total
                    ? 'paid'
                    : 'partial',
            ]);

            return $payment;
        });

        $payment->load([
            'sale.customer',
            'financialAccount',
            'currency',
            'creator',
        ]);

        return response()->json([
            'message' => 'Sale payment recorded successfully.',
            'data' => $payment,
        ], 201);
    }
}
