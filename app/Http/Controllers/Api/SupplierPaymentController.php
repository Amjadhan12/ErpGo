<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\OpeningBalance;
use App\Models\PartnerTransaction;
use App\Models\SupplierPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierPaymentController extends Controller
{
    public function index(): JsonResponse
    {
        $payments = SupplierPayment::with([
            'supplier',
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
                'unique:supplier_payments,payment_number',
            ],
            'business_partner_id' => [
                'required',
                'integer',
                'exists:business_partners,id',
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
            // Lock the supplier to serialize payments for this supplier.
            $supplier = BusinessPartner::whereKey(
                $validated['business_partner_id']
            )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $supplier->partner_type !== 'Supplier' ||
                ! $supplier->is_active
            ) {
                throw ValidationException::withMessages([
                    'business_partner_id' =>
                        'The selected supplier is invalid or inactive.',
                ]);
            }

            // Lock the financial account to serialize withdrawals
            // that use this account.
            $account = FinancialAccount::whereKey(
                $validated['financial_account_id']
            )
                ->lockForUpdate()
                ->firstOrFail();

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'financial_account_id' =>
                        'The selected financial account is inactive.',
                ]);
            }

            $currency = Currency::findOrFail(
                $validated['currency_id']
            );

            if (! $currency->is_active) {
                throw ValidationException::withMessages([
                    'currency_id' =>
                        'The selected currency is inactive.',
                ]);
            }

            if ((int) $account->currency_id !== (int) $currency->id) {
                throw ValidationException::withMessages([
                    'financial_account_id' =>
                        'The financial account currency must match the payment currency.',
                ]);
            }

            if (
                $currency->code === 'AFN' &&
                (float) $validated['exchange_rate_to_afn'] !== 1.0
            ) {
                throw ValidationException::withMessages([
                    'exchange_rate_to_afn' =>
                        'The AFN exchange rate must be 1.',
                ]);
            }

            // Calculate supplier balance as of the payment date.
            $supplierTotals = PartnerTransaction::where(
                'business_partner_id',
                $supplier->id
            )
                ->where('currency_id', $currency->id)
                ->whereDate(
                    'transaction_date',
                    '<=',
                    $validated['payment_date']
                )
                ->selectRaw("
                    COALESCE(
                        SUM(CASE WHEN direction = 'credit'
                            THEN amount ELSE 0 END), 0
                    ) as total_credit,
                    COALESCE(
                        SUM(CASE WHEN direction = 'debit'
                            THEN amount ELSE 0 END), 0
                    ) as total_debit
                ")
                ->first();

           $openingBalance = (float) \App\Models\PartnerOpeningBalance::where(
    'business_partner_id',
    $supplier->id
)
    ->where('currency_id', $currency->id)
    ->whereDate('opening_date', '<=', $validated['payment_date'])
    ->sum('amount');

$outstandingBalance = $openingBalance
    + (float) $supplierTotals->total_credit
    - (float) $supplierTotals->total_debit;

            if ((float) $validated['amount'] > $outstandingBalance) {
                throw ValidationException::withMessages([
                    'amount' =>
                        "Payment cannot exceed the supplier's outstanding balance of "
                        . $outstandingBalance . ' ' . $currency->code . '.',
                ]);
            }

            // Calculate available account balance as of the payment date.
            $openingBalance = (float) OpeningBalance::where(
                'financial_account_id',
                $account->id
            )
                ->where(
                    'opening_date',
                    '<=',
                    $validated['payment_date']
                )
                ->sum('amount');

            $financialTotals = FinancialTransaction::where(
                'financial_account_id',
                $account->id
            )
                ->whereDate(
                    'transaction_date',
                    '<=',
                    $validated['payment_date']
                )
                ->selectRaw("
                    COALESCE(
                        SUM(CASE WHEN direction = 'in'
                            THEN amount ELSE 0 END), 0
                    ) as total_in,
                    COALESCE(
                        SUM(CASE WHEN direction = 'out'
                            THEN amount ELSE 0 END), 0
                    ) as total_out
                ")
                ->first();

            $accountBalance = $openingBalance
                + (float) $financialTotals->total_in
                - (float) $financialTotals->total_out;

            if ((float) $validated['amount'] > $accountBalance) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'Insufficient funds. Available balance: '
                        . $accountBalance . ' ' . $currency->code . '.',
                ]);
            }

            $transactionGroup = (string) Str::uuid();

            $payment = SupplierPayment::create([
                'payment_number' => $validated['payment_number'],
                'business_partner_id' => $supplier->id,
                'financial_account_id' => $account->id,
                'currency_id' => $currency->id,
                'amount' => $validated['amount'],
                'exchange_rate_to_afn' =>
                    $validated['exchange_rate_to_afn'],
                'payment_date' => $validated['payment_date'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // Reduce the supplier's outstanding payable balance.
            PartnerTransaction::create([
                'business_partner_id' => $supplier->id,
                'currency_id' => $currency->id,
                'created_by' => $request->user()->id,
                'transaction_type' => 'payment',
                'direction' => 'debit',
                'amount' => $validated['amount'],
                'reference_type' => SupplierPayment::class,
                'reference_id' => $payment->id,
                'transaction_date' => $validated['payment_date'],
                'description' =>
                    'Supplier payment ' . $payment->payment_number,
            ]);

            // Record money leaving the financial account.
            FinancialTransaction::create([
                'financial_account_id' => $account->id,
                'related_account_id' => null,
                'created_by' => $request->user()->id,
                'transaction_type' => 'supplier_payment',
                'direction' => 'out',
                'amount' => $validated['amount'],
                'exchange_rate' =>
                    $validated['exchange_rate_to_afn'],
                'transaction_group' => $transactionGroup,
                'reference_type' => SupplierPayment::class,
                'reference_id' => $payment->id,
                'transaction_date' => $validated['payment_date'],
                'description' =>
                    'Payment to supplier ' . $supplier->name
                    . ' - ' . $payment->payment_number,
            ]);

            return $payment;
        });

        $payment->load([
            'supplier',
            'financialAccount',
            'currency',
            'creator',
        ]);

        return response()->json([
            'message' => 'Supplier payment recorded successfully.',
            'data' => $payment,
        ], 201);
    }
}
