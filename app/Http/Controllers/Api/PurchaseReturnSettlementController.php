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
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseReturnSettlementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $settlements = PurchaseReturnSettlement::with([
            'purchaseReturn.supplier',
            'financialAccount',
            'currency',
            'creator',
        ])
            ->when($request->filled('purchase_return_id'), function ($query) use ($request) {
                $query->where(
                    'purchase_return_id',
                    $request->integer('purchase_return_id')
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
                'unique:purchase_return_settlements,settlement_number',
            ],
            'purchase_return_id' => [
                'required',
                'integer',
                'exists:purchase_returns,id',
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
            $return = PurchaseReturn::whereKey($validated['purchase_return_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($return->status !== 'posted') {
                throw ValidationException::withMessages([
                    'purchase_return_id' => 'Only posted purchase returns can be settled.',
                ]);
            }

            if ($validated['settlement_date'] < $return->return_date->format('Y-m-d')) {
                throw ValidationException::withMessages([
                    'settlement_date' => 'Settlement date cannot be before the return date.',
                ]);
            }

            if ((int) $validated['currency_id'] !== (int) $return->currency_id) {
                throw ValidationException::withMessages([
                    'currency_id' => 'Settlement currency must match the purchase return currency.',
                ]);
            }

            $supplier = BusinessPartner::whereKey($return->business_partner_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($supplier->partner_type !== 'Supplier' || ! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'purchase_return_id' => 'The supplier is invalid or inactive.',
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

            $previousSettlements = (float) PurchaseReturnSettlement::where(
                'purchase_return_id',
                $return->id
            )->sum('amount');

            $remainingReturn = (float) $return->grand_total - $previousSettlements;

            if ((float) $validated['amount'] > $remainingReturn) {
                throw ValidationException::withMessages([
                    'amount' => 'Settlement exceeds the remaining purchase return amount of '
                        . round(max(0, $remainingReturn), 4) . ' ' . $currency->code . '.',
                ]);
            }

            // Supplier balance: positive means company owes supplier.
            $partnerTotals = PartnerTransaction::where(
                'business_partner_id',
                $supplier->id
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
                $supplier->id
            )
                ->where('currency_id', $currency->id)
                ->whereDate('opening_date', '<=', $validated['settlement_date'])
                ->sum('amount');

            $supplierPayable = $partnerOpening
                + (float) $partnerTotals->total_credit
                - (float) $partnerTotals->total_debit;

            // Subtract refunds already received for this supplier/currency.
            $previousRefunds = (float) PurchaseReturnSettlement::query()
                ->join(
                    'purchase_returns',
                    'purchase_returns.id',
                    '=',
                    'purchase_return_settlements.purchase_return_id'
                )
                ->where('purchase_returns.business_partner_id', $supplier->id)
                ->where('purchase_returns.currency_id', $currency->id)
                ->whereDate(
                    'purchase_return_settlements.settlement_date',
                    '<=',
                    $validated['settlement_date']
                )
                ->sum('purchase_return_settlements.amount');

            $supplierCreditAvailable = max(0, -$supplierPayable - $previousRefunds);

            if ((float) $validated['amount'] > $supplierCreditAvailable) {
                throw ValidationException::withMessages([
                    'amount' => 'Supplier credit available for refund is only '
                        . round($supplierCreditAvailable, 4) . ' ' . $currency->code . '.',
                ]);
            }

            // Incoming refund increases the financial account balance.
            $transactionGroup = (string) Str::uuid();

            $settlement = PurchaseReturnSettlement::create([
                'settlement_number' => $validated['settlement_number'],
                'purchase_return_id' => $return->id,
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
                'transaction_type' => 'purchase_return_refund',
                'direction' => 'in',
                'amount' => $validated['amount'],
                'exchange_rate' => $validated['exchange_rate_to_afn'],
                'transaction_group' => $transactionGroup,
                'reference_type' => PurchaseReturnSettlement::class,
                'reference_id' => $settlement->id,
                'transaction_date' => $validated['settlement_date'],
                'description' => 'Refund received from supplier '
                    . $supplier->name . ' - ' . $settlement->settlement_number,
            ]);

            return $settlement->load([
                'purchaseReturn.supplier',
                'financialAccount',
                'currency',
                'creator',
            ]);
        });

        return response()->json([
            'message' => 'Purchase return refund received successfully.',
            'data' => $settlement,
        ], 201);
    }
}