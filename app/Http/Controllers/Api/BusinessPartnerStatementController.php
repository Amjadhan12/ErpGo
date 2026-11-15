<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Currency;
use App\Models\PartnerOpeningBalance;
use App\Models\PartnerTransaction;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class BusinessPartnerStatementController extends Controller
{
    public function show(Request $request, BusinessPartner $businessPartner): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if (!in_array($businessPartner->partner_type, ['Customer', 'Supplier'], true)) {
            return response()->json(['message' => 'Statements are available for customers and suppliers only.'], 404);
        }

        $year = (int) ($validated['year'] ?? now()->year);
        $month = isset($validated['month']) ? (int) $validated['month'] : null;
        $periodStart = CarbonImmutable::create($year, $month ?? 1, 1)->startOfDay();
        $periodEnd = $month
            ? $periodStart->endOfMonth()->endOfDay()
            : $periodStart->endOfYear()->endOfDay();
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        $usedCurrencyIds = PartnerTransaction::query()
                ->where('business_partner_id', $businessPartner->id)
                ->whereDate('transaction_date', '<=', $periodEnd->toDateString())
                ->pluck('currency_id')
            ->merge(PartnerOpeningBalance::query()
                    ->where('business_partner_id', $businessPartner->id)
                    ->whereDate('opening_date', '<=', $periodEnd->toDateString())
                    ->pluck('currency_id'))
            ->unique()
            ->values();
        $currencies = Currency::query()
            ->where(function ($query) use ($usedCurrencyIds) {
                $query->whereIn('id', $usedCurrencyIds)
                    ->orWhereIn('code', ['AFN', 'USD', 'PKR']);
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'symbol'])
            ->keyBy('id');

        $transactions = $businessPartner->transactions()
            ->with('currency:id,code,name,symbol')
            ->whereDate('transaction_date', '<=', $periodEnd->toDateString())
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
        $openings = $businessPartner->openingBalances()
            ->with('currency:id,code,name,symbol')
            ->whereDate('opening_date', '<=', $periodEnd->toDateString())
            ->orderBy('opening_date')
            ->orderBy('id')
            ->get();

        $isCustomer = $businessPartner->partner_type === 'Customer';
        $debtDirection = $isCustomer ? 'debit' : 'credit';
        $creditDirection = $isCustomer ? 'credit' : 'debit';
        $eventsByCurrency = collect();

        foreach ($currencies as $currencyId => $currency) {
            $events = collect();
            foreach ($openings->where('currency_id', $currencyId) as $opening) {
                $date = CarbonImmutable::parse($opening->opening_date)->startOfDay();
                $events->push([
                    'id' => 'opening-' . $opening->id,
                    'transaction_id' => null,
                    'transaction_date' => $date->toDateString(),
                    'transaction_type' => 'opening_balance',
                    'direction' => $debtDirection,
                    'amount' => (float) $opening->amount,
                    'currency_id' => (int) $currencyId,
                    'currency' => $currency->code,
                    'currency_name' => $currency->name,
                    'currency_symbol' => $currency->symbol,
                    'description' => $opening->notes,
                    'reference_type' => null,
                    'reference_id' => null,
                    'source_document_number' => null,
                    '_date' => $date,
                    '_priority' => 0,
                ]);
            }
            foreach ($transactions->where('currency_id', $currencyId) as $transaction) {
                $date = CarbonImmutable::parse($transaction->transaction_date);
                $events->push([
                    'id' => 'transaction-' . $transaction->id,
                    'transaction_id' => (int) $transaction->id,
                    'transaction_date' => $date->toDateTimeString(),
                    'transaction_type' => $transaction->transaction_type,
                    'direction' => $transaction->direction,
                    'amount' => (float) $transaction->amount,
                    'currency_id' => (int) $currencyId,
                    'currency' => $transaction->currency?->code,
                    'currency_name' => $transaction->currency?->name,
                    'currency_symbol' => $transaction->currency?->symbol,
                    'description' => $transaction->description,
                    'reference_type' => $transaction->reference_type,
                    'reference_id' => $transaction->reference_id,
                    'source_document_number' => null,
                    '_date' => $date,
                    '_priority' => 1,
                ]);
            }
            $events = $events->sort(function (array $a, array $b) {
                return [$a['_date']->getTimestamp(), $a['_priority'], $a['transaction_id'] ?? (int) str_replace('opening-', '', $a['id'])]
                    <=> [$b['_date']->getTimestamp(), $b['_priority'], $b['transaction_id'] ?? (int) str_replace('opening-', '', $b['id'])];
            })->values();
            $eventsByCurrency->put($currencyId, $events);
        }

        $this->attachSourceNumbers($eventsByCurrency);

        $summaries = [];
        $periodRows = collect();
        foreach ($eventsByCurrency as $currencyId => $events) {
            $openingBalance = 0.0;
            $periodDebit = 0.0;
            $periodCredit = 0.0;
            $salesOrPurchases = 0.0;
            $returns = 0.0;
            $payments = 0.0;
            $returnSettlements = 0.0;
            $periodOpeningEntries = 0.0;
            $runningBalance = 0.0;
            foreach ($events as $event) {
                $date = $event['_date'];
                $isBefore = $date->lt($periodStart);
                $isInPeriod = $date->betweenIncluded($periodStart, $periodEnd);
                $signed = $event['direction'] === $debtDirection ? $event['amount'] : -$event['amount'];
                if ($isBefore) {
                    $openingBalance += $signed;
                } elseif ($isInPeriod) {
                    if ($event['transaction_type'] === 'opening_balance') {
                        $periodOpeningEntries += $event['amount'];
                    } else {
                        if ($event['direction'] === 'debit') $periodDebit += $event['amount'];
                        if ($event['direction'] === 'credit') $periodCredit += $event['amount'];
                        if (in_array($event['transaction_type'], ['sale', 'purchase'], true)) $salesOrPurchases += $event['amount'];
                        if (in_array($event['transaction_type'], ['sale_return', 'purchase_return'], true)) $returns += $event['amount'];
                        if ($event['transaction_type'] === 'payment') $payments += $event['amount'];
                        if (in_array($event['transaction_type'], ['sale_return_refund', 'purchase_return_refund'], true)) $returnSettlements += $event['amount'];
                    }
                }
            }
            $runningBalance = $openingBalance;
            foreach ($events as $event) {
                if ($event['_date']->lt($periodStart)) continue;
                if ($event['_date']->gt($periodEnd)) continue;
                $runningBalance += $event['direction'] === $debtDirection ? $event['amount'] : -$event['amount'];
                $event['running_balance'] = round($runningBalance, 4);
                $periodRows->push($event);
            }
            $currency = $currencies->get($currencyId);
            $summaries[] = [
                'currency_id' => (int) $currencyId,
                'currency' => $currency?->code,
                'currency_name' => $currency?->name,
                'currency_symbol' => $currency?->symbol,
                'opening_balance' => round($openingBalance, 4),
                'period_debit_total' => round($periodDebit, 4),
                'period_credit_total' => round($periodCredit, 4),
                'sales_or_purchases_total' => round($salesOrPurchases, 4),
                'returns_total' => round($returns, 4),
                'payments_total' => round($payments, 4),
                'return_settlements_total' => round($returnSettlements, 4),
                'period_opening_balance_entries' => round($periodOpeningEntries, 4),
                'closing_balance' => round($runningBalance, 4),
                'balance_type' => $runningBalance > 0 ? ($isCustomer ? 'customer_owes_us' : 'we_owe_supplier') : ($runningBalance < 0 ? ($isCustomer ? 'we_owe_customer' : 'supplier_owes_us') : 'settled'),
            ];
        }

        $periodRows = $periodRows->sort(function (array $a, array $b) {
            return [$b['_date']->getTimestamp(), $b['_priority'], $b['transaction_id'] ?? 0]
                <=> [$a['_date']->getTimestamp(), $a['_priority'], $a['transaction_id'] ?? 0];
        })->values();
        $total = $periodRows->count();
        $rows = $periodRows->forPage($page, $perPage)->map(function (array $row) {
            unset($row['_date'], $row['_priority'], $row['currency_id']);
            return $row;
        })->values();

        return response()->json([
            'data' => [
                'partner' => [
                    'id' => $businessPartner->id,
                    'partner_type' => $businessPartner->partner_type,
                    'name' => $businessPartner->name,
                    'business_name' => $businessPartner->business_name,
                    'phone' => $businessPartner->phone,
                    'email' => $businessPartner->email,
                    'address' => $businessPartner->address,
                    'tax_number' => $businessPartner->tax_number,
                    'notes' => $businessPartner->notes,
                ],
                'period' => ['year' => $year, 'month' => $month, 'from' => $periodStart->toDateString(), 'to' => $periodEnd->toDateString()],
                'currency_summaries' => $summaries,
                'transactions' => [
                    'data' => $rows,
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
            ],
        ]);
    }

    private function attachSourceNumbers(Collection $eventsByCurrency): void
    {
        $sources = [
            Sale::class => ['model' => Sale::class, 'field' => 'sale_number'],
            Purchase::class => ['model' => Purchase::class, 'field' => 'purchase_number'],
            SaleReturn::class => ['model' => SaleReturn::class, 'field' => 'return_number'],
            PurchaseReturn::class => ['model' => PurchaseReturn::class, 'field' => 'return_number'],
            SalePayment::class => ['model' => SalePayment::class, 'field' => 'payment_number'],
            SupplierPayment::class => ['model' => SupplierPayment::class, 'field' => 'payment_number'],
        ];
        $references = $eventsByCurrency->flatten(1)->filter(fn (array $row) => $row['reference_type'] && $row['reference_id']);
        $numbersByType = collect();
        foreach ($references->groupBy('reference_type') as $type => $rows) {
            if (!isset($sources[$type])) continue;
            $source = $sources[$type];
            $numbersByType->put($type, $source['model']::query()
                ->whereIn('id', $rows->pluck('reference_id')->unique())
                ->pluck($source['field'], 'id'));
        }
        $eventsByCurrency->transform(fn (Collection $events) => $events->map(function (array $event) use ($numbersByType) {
            $event['source_document_number'] = $numbersByType
                ->get($event['reference_type'], collect())
                ->get($event['reference_id']);
            return $event;
        }));
    }
}
