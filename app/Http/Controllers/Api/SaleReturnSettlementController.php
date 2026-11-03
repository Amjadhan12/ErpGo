<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\OpeningBalance;
use App\Models\PartnerOpeningBalance;
use App\Models\PartnerTransaction;
use App\Models\SaleReturn;
use App\Models\SaleReturnSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleReturnSettlementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $settlements = SaleReturnSettlement::with([
            'saleReturn.customer',
            'financialAccount',
            'currency',
            'creator',
        ])
            ->when($request->filled('sale_return_id'), function ($query) use ($request) {
                $query->where(
                    'sale_return_id',
                    $request->integer('sale_return_id')
                );
            })
            ->latest('settlement_date')
            ->paginate(20);

        return response()->json($settlements);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settlement_number' => [
                'required',
                'string',
                'max:50',
                'unique:sale_return_settlements,settlement_number',
            ],
            'sale_return_id' => [
                'required',
                'integer',
                'exists:sale_returns,id',
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
            'settlement_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $settlement = DB::transaction(function () use ($validated, $request) {
            $return = SaleReturn::whereKey($validated['sale_return_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($return->status !== 'posted') {
                throw ValidationException::withMessages([
                    'sale_return_id' => 'Only posted sale returns can be settled.',
                ]);
            }

            if ($validated['settlement_date'] < $return->return_date->format('Y-m-d')) {
                throw ValidationException::withMessages([
                    'settlement_date' => 'Settlement date cannot be before the return date.',
                ]);
            }

            if ((int) $validated['currency_id'] !== (int) $return->currency_id) {
                throw ValidationException::withMessages([
                    'currency_id' => 'Settlement currency must match the sale return currency.',
                ]);
            }

            $customer = BusinessPartner::whereKey($return->business_partner_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($customer->partner_type !== 'Customer' || ! $customer->is_active) {
                throw ValidationException::withMessages([
                    'sale_return_id' => 'The customer is invalid or inactive.',
                ]);
            }

            $account = FinancialAccount::whereKey($validated['financial_account_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'The financial account is inactive.',
                ]);
            }

            $currency = Currency::findOrFail($validated['currency_id']);

            if (! $currency->is_active) {
                throw ValidationException::withMessages([
                    'currency_id' => 'The currency is inactive.',
                ]);
            }

            if ((int) $account->currency_id !== (int) $currency->id) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'Financial account currency must match settlement currency.',
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

            $previousSettlements = (float) SaleReturnSettlement::where(
                'sale_return_id',
                $return->id
            )->sum('amount');

            $remainingReturn = (float) $return->grand_total - $previousSettlements;

            if ((float) $validated['amount'] > $remainingReturn) {
                throw ValidationException::withMessages([
                    'amount' => 'Refund exceeds the remaining sale return amount of '
                        . round(max(0, $remainingReturn), 4) . ' ' . $currency->code . '.',
                ]);
            }

            // Customer balance: positive means customer owes us.
            $partnerTotals = PartnerTransaction::where(
                'business_partner_id',
                $customer->id
            )
                ->where('currency_id', $currency->id)
                ->whereDate('transaction_date', '<=', $validated['settlement_date'])
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) as total_credit,
                    COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) as total_debit
                ")
                ->first();

            $partnerOpening = (float) PartnerOpeningBalance::where(
                'business_partner_id',
                $customer->id
            )
                ->where('currency_id', $currency->id)
                ->whereDate('opening_date', '<=', $validated['settlement_date'])
                ->sum('amount');

            $customerBalance = $partnerOpening
                + (float) $partnerTotals->total_debit
                - (float) $partnerTotals->total_credit;

            // Subtract refunds already paid to this customer/currency.
            $previousRefunds = (float) SaleReturnSettlement::query()
                ->join(
                    'sale_returns',
                    'sale_returns.id',
                    '=',
                    'sale_return_settlements.sale_return_id'
                )
                ->where('sale_returns.business_partner_id', $customer->id)
                ->where('sale_returns.currency_id', $currency->id)
                ->whereDate(
                    'sale_return_settlements.settlement_date',
                    '<=',
                    $validated['settlement_date']
                )
                ->sum('sale_return_settlements.amount');

            $customerCreditAvailable = max(0, -$customerBalance - $previousRefunds);

            if ((float) $validated['amount'] > $customerCreditAvailable) {
                throw ValidationException::withMessages([
                    'amount' => 'Customer credit available for refund is only '
                        . round($customerCreditAvailable, 4) . ' ' . $currency->code . '.',
                ]);
            }

            // Check that the financial account has enough money to refund.
            $accountOpening = (float) OpeningBalance::where(
                'financial_account_id',
                $account->id
            )
                ->whereDate('opening_date', '<=', $validated['settlement_date'])
                ->sum('amount');

            $accountTotals = FinancialTransaction::where(
                'financial_account_id',
                $account->id
            )
                ->whereDate('transaction_date', '<=', $validated['settlement_date'])
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as total_in,
                    COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as total_out
                ")
                ->first();

            $accountBalance = $accountOpening
                + (float) $accountTotals->total_in
                - (float) $accountTotals->total_out;

            if ((float) $validated['amount'] > $accountBalance) {
                throw ValidationException::withMessages([
                    'amount' => 'Insufficient funds. Available account balance: '
                        . round($accountBalance, 4) . ' ' . $currency->code . '.',
                ]);
            }

            $transactionGroup = (string) Str::uuid();

            $settlement = SaleReturnSettlement::create([
                'settlement_number' => $validated['settlement_number'],
                'sale_return_id' => $return->id,
                'financial_account_id' => $account->id,
                'currency_id' => $currency->id,
                'created_by' => $request->user()->id,
                'amount' => $validated['amount'],
                'exchange_rate_to_afn' => $validated['exchange_rate_to_afn'],
                'settlement_date' => $validated['settlement_date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            FinancialTransaction::create([
                'financial_account_id' => $account->id,
                'related_account_id' => null,
                'created_by' => $request->user()->id,
                'transaction_type' => 'sale_return_refund',
                'direction' => 'out',
                'amount' => $validated['amount'],
                'exchange_rate' => $validated['exchange_rate_to_afn'],
                'transaction_group' => $transactionGroup,
                'reference_type' => SaleReturnSettlement::class,
                'reference_id' => $settlement->id,
                'transaction_date' => $validated['settlement_date'],
                'description' => 'Refund paid to customer '
                    . $customer->name . ' - ' . $settlement->settlement_number,
            ]);

            return $settlement->load([
                'saleReturn.customer',
                'financialAccount',
                'currency',
                'creator',
            ]);
        });

        return response()->json([
            'message' => 'Sale return refund paid successfully.',
            'data' => $settlement,
        ], 201);
    }
}