<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseSummaryReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:from_date',
            ],
        ]);

        $fromDate = $validated['from_date'] ?? null;
        $toDate = $validated['to_date'] ?? null;

        $purchasesByCurrency = Purchase::query()
            ->join(
                'currencies',
                'currencies.id',
                '=',
                'purchases.currency_id'
            )
            ->where('purchases.status', 'posted')
            ->when(
                $fromDate,
                fn ($query) => $query->whereDate(
                    'purchases.purchase_date',
                    '>=',
                    $fromDate
                )
            )
            ->when(
                $toDate,
                fn ($query) => $query->whereDate(
                    'purchases.purchase_date',
                    '<=',
                    $toDate
                )
            )
            ->select(
                'purchases.currency_id',
                'currencies.code as currency_code'
            )
            ->selectRaw('COUNT(purchases.id) as purchase_count')
            ->selectRaw('SUM(purchases.grand_total) as gross_purchase_amount')
            ->groupBy('purchases.currency_id', 'currencies.code')
            ->orderBy('currencies.code')
            ->get();

        return response()->json([
            'message' => 'Purchase summary retrieved successfully.',
            'data' => [
                'filters' => [
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                ],
                'total_purchase_count' => (int) $purchasesByCurrency
                    ->sum('purchase_count'),
                'gross_purchases_by_currency' => $purchasesByCurrency
                    ->map(fn (object $row): array => [
                        'currency_id' => (int) $row->currency_id,
                        'currency_code' => $row->currency_code,
                        'purchase_count' => (int) $row->purchase_count,
                        'gross_purchase_amount' => round(
                            (float) $row->gross_purchase_amount,
                            4
                        ),
                    ])
                    ->values(),
                'purchase_returns_included' => false,
                'purchase_returns_note' => 'Gross posted purchases only. Purchase returns and return settlements are excluded.',
            ],
        ]);
    }
}
