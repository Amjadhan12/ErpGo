<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartnerAgingReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_type' => ['nullable', Rule::in(['Customer', 'Supplier'])],
            'search' => ['nullable', 'string', 'max:255'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $asOfDate = Carbon::parse(
            $validated['as_of_date'] ?? now()->toDateString()
        )->startOfDay();

        $partnerQuery = BusinessPartner::query()
            ->where('is_active', true)
            ->orderBy('name');

        if (!empty($validated['partner_type'])) {
            $partnerQuery->where('partner_type', $validated['partner_type']);
        }

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $partnerQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $partners = $partnerQuery->paginate($validated['per_page'] ?? 50);
        $partnerIds = $partners->getCollection()->pluck('id');

        if ($partnerIds->isEmpty()) {
            return response()->json([
                'filters' => [
                    'partner_type' => $validated['partner_type'] ?? null,
                    'currency_id' => $validated['currency_id'] ?? null,
                    'as_of_date' => $asOfDate->toDateString(),
                    'allocation_method' => 'FIFO',
                    'aging_basis' => 'transaction_date',
                ],
                'data' => $partners,
            ]);
        }

        $openingQuery = DB::table('partner_opening_balances')
            ->join(
                'currencies',
                'currencies.id',
                '=',
                'partner_opening_balances.currency_id'
            )
            ->whereIn('partner_opening_balances.business_partner_id', $partnerIds)
            ->whereDate(
                'partner_opening_balances.opening_date',
                '<=',
                $asOfDate->toDateString()
            );

        $transactionQuery = DB::table('partner_transactions')
            ->join(
                'currencies',
                'currencies.id',
                '=',
                'partner_transactions.currency_id'
            )
            ->whereIn('partner_transactions.business_partner_id', $partnerIds)
            ->whereDate(
                'partner_transactions.transaction_date',
                '<=',
                $asOfDate->toDateString()
            );

        if (!empty($validated['currency_id'])) {
            $openingQuery->where(
                'partner_opening_balances.currency_id',
                $validated['currency_id']
            );

            $transactionQuery->where(
                'partner_transactions.currency_id',
                $validated['currency_id']
            );
        }

        $openingRows = $openingQuery->select([
            'partner_opening_balances.id',
            'partner_opening_balances.business_partner_id',
            'partner_opening_balances.currency_id',
            'partner_opening_balances.amount',
            'partner_opening_balances.opening_date',
            'partner_opening_balances.notes',
            'currencies.code as currency_code',
            'currencies.name as currency_name',
            'currencies.symbol as currency_symbol',
        ])->get()->groupBy('business_partner_id');

        $transactionRows = $transactionQuery->select([
            'partner_transactions.id',
            'partner_transactions.business_partner_id',
            'partner_transactions.currency_id',
            'partner_transactions.transaction_type',
            'partner_transactions.direction',
            'partner_transactions.amount',
            'partner_transactions.reference_type',
            'partner_transactions.reference_id',
            'partner_transactions.transaction_date',
            'partner_transactions.description',
            'currencies.code as currency_code',
            'currencies.name as currency_name',
            'currencies.symbol as currency_symbol',
        ])->get()->groupBy('business_partner_id');

        $partners->getCollection()->transform(function (BusinessPartner $partner) use (
            $openingRows,
            $transactionRows,
            $asOfDate
        ) {
            $openings = $openingRows->get($partner->id, collect());
            $transactions = $transactionRows->get($partner->id, collect());

            $currencyIds = $openings->pluck('currency_id')
                ->merge($transactions->pluck('currency_id'))
                ->unique()
                ->values();

            $currencies = $currencyIds->map(function ($currencyId) use (
                $partner,
                $openings,
                $transactions,
                $asOfDate
            ) {
                $currencyOpenings = $openings->where('currency_id', $currencyId);
                $currencyTransactions = $transactions->where('currency_id', $currencyId);
                $currency = $currencyOpenings->first()
                    ?? $currencyTransactions->first();

                $isCustomer = $partner->partner_type === 'Customer';
                $debtDirection = $isCustomer ? 'debit' : 'credit';
                $creditDirection = $isCustomer ? 'credit' : 'debit';

                $events = [];

                foreach ($currencyOpenings as $opening) {
                    $events[] = [
                        'date' => Carbon::parse($opening->opening_date)->startOfDay(),
                        'priority' => 0,
                        'id' => (int) $opening->id,
                        'kind' => 'opening_balance',
                        'direction' => $debtDirection,
                        'amount' => (float) $opening->amount,
                        'transaction_type' => 'opening_balance',
                        'reference_type' => null,
                        'reference_id' => null,
                        'description' => $opening->notes,
                    ];
                }

                foreach ($currencyTransactions as $transaction) {
                    $events[] = [
                        'date' => Carbon::parse($transaction->transaction_date),
                        'priority' => 1,
                        'id' => (int) $transaction->id,
                        'kind' => 'transaction',
                        'direction' => $transaction->direction,
                        'amount' => (float) $transaction->amount,
                        'transaction_type' => $transaction->transaction_type,
                        'reference_type' => $transaction->reference_type,
                        'reference_id' => $transaction->reference_id,
                        'description' => $transaction->description,
                    ];
                }

                usort($events, function (array $a, array $b) {
                    $dateComparison = $a['date']->getTimestamp()
                        <=> $b['date']->getTimestamp();

                    if ($dateComparison !== 0) {
                        return $dateComparison;
                    }

                    return [$a['priority'], $a['id']]
                        <=> [$b['priority'], $b['id']];
                });

                // Apply receipts/payments to the oldest outstanding debt first.
                $outstanding = [];
                $unappliedCredit = 0.0;

                foreach ($events as $event) {
                    $remaining = $event['amount'];

                    if ($event['direction'] === $debtDirection) {
                        // A previous advance/credit offsets new debt first.
                        $offset = min($remaining, $unappliedCredit);
                        $remaining -= $offset;
                        $unappliedCredit -= $offset;

                        if ($remaining > 0.00005) {
                            $outstanding[] = [
                                'source' => $event['kind'],
                                'transaction_type' => $event['transaction_type'],
                                'reference_type' => $event['reference_type'],
                                'reference_id' => $event['reference_id'],
                                'description' => $event['description'],
                                'transaction_date' => $event['date']->toDateString(),
                                'amount' => $remaining,
                            ];
                        }
                    } elseif ($event['direction'] === $creditDirection) {
                        foreach ($outstanding as &$item) {
                            if ($remaining <= 0.00005) {
                                break;
                            }

                            $offset = min($item['amount'], $remaining);
                            $item['amount'] -= $offset;
                            $remaining -= $offset;
                        }
                        unset($item);

                        $outstanding = array_values(array_filter(
                            $outstanding,
                            fn (array $item) => $item['amount'] > 0.00005
                        ));

                        if ($remaining > 0.00005) {
                            $unappliedCredit += $remaining;
                        }
                    }
                }

                $buckets = [
                    '0_30_days' => 0.0,
                    '31_60_days' => 0.0,
                    '61_90_days' => 0.0,
                    'over_90_days' => 0.0,
                ];

                $openItems = [];

                foreach ($outstanding as $item) {
                    $ageDays = (int) Carbon::parse($item['transaction_date'])
                        ->startOfDay()
                        ->diffInDays($asOfDate);

                    if ($ageDays <= 30) {
                        $bucket = '0_30_days';
                    } elseif ($ageDays <= 60) {
                        $bucket = '31_60_days';
                    } elseif ($ageDays <= 90) {
                        $bucket = '61_90_days';
                    } else {
                        $bucket = 'over_90_days';
                    }

                    $buckets[$bucket] += $item['amount'];

                    $openItems[] = [
                        ...$item,
                        'age_days' => $ageDays,
                        'aging_bucket' => $bucket,
                        'amount' => round($item['amount'], 4),
                    ];
                }

                foreach ($buckets as $key => $value) {
                    $buckets[$key] = round($value, 4);
                }

                return [
                    'currency_id' => (int) $currencyId,
                    'currency' => $currency->currency_code,
                    'currency_name' => $currency->currency_name,
                    'currency_symbol' => $currency->currency_symbol,
                    'aging' => $buckets,
                    'total_outstanding' => round(array_sum($buckets), 4),
                    'unapplied_credit' => round($unappliedCredit, 4),
                    'open_items' => $openItems,
                ];
            })->values();

            return [
                'id' => $partner->id,
                'partner_type' => $partner->partner_type,
                'name' => $partner->name,
                'business_name' => $partner->business_name,
                'phone' => $partner->phone,
                'currencies' => $currencies,
            ];
        });

        return response()->json([
            'filters' => [
                'partner_type' => $validated['partner_type'] ?? null,
                'currency_id' => $validated['currency_id'] ?? null,
                'as_of_date' => $asOfDate->toDateString(),
                'allocation_method' => 'FIFO',
                'aging_basis' => 'transaction_date',
            ],
            'data' => $partners,
        ]);
    }
}
