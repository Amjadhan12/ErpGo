<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BatchExpiryReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'status' => 'nullable|in:all,expired,expiring,no_expiry',
            'days' => 'nullable|integer|min:1|max:365',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $today = Carbon::today()->toDateString();
        $days = $validated['days'] ?? 30;
        $expiryLimit = Carbon::today()->addDays($days)->toDateString();

        $baseQuery = DB::table('product_batches as pb')
            ->join('products as p', 'p.id', '=', 'pb.product_id')
            ->join('warehouses as w', 'w.id', '=', 'pb.warehouse_id')
            ->where('pb.remaining_quantity', '>', 0);

        if (!empty($validated['warehouse_id'])) {
            $baseQuery->where('pb.warehouse_id', $validated['warehouse_id']);
        }

        if (!empty($validated['product_id'])) {
            $baseQuery->where('pb.product_id', $validated['product_id']);
        }

        $summary = [
            'expired' => (clone $baseQuery)
                ->whereDate('pb.expiry_date', '<', $today)
                ->count(),

            'expiring_soon' => (clone $baseQuery)
                ->whereNotNull('pb.expiry_date')
                ->whereDate('pb.expiry_date', '>=', $today)
                ->whereDate('pb.expiry_date', '<=', $expiryLimit)
                ->count(),

            'without_expiry' => (clone $baseQuery)
                ->whereNull('pb.expiry_date')
                ->count(),

            'total_batches_with_stock' => (clone $baseQuery)->count(),
        ];

        $query = clone $baseQuery;

        $status = $validated['status'] ?? 'all';

        if ($status === 'expired') {
            $query->whereDate('pb.expiry_date', '<', $today);
        } elseif ($status === 'expiring') {
            $query->whereNotNull('pb.expiry_date')
                ->whereDate('pb.expiry_date', '>=', $today)
                ->whereDate('pb.expiry_date', '<=', $expiryLimit);
        } elseif ($status === 'no_expiry') {
            $query->whereNull('pb.expiry_date');
        }

        $batches = $query
            ->select([
                'pb.id',
                'pb.product_id',
                'pb.warehouse_id',
                'pb.batch_number',
                'pb.expiry_date',
                'pb.received_quantity',
                'pb.remaining_quantity',
                'pb.unit_cost_afn',
                'p.name as product_name',
                'p.sku as product_sku',
                'w.name as warehouse_name',
            ])
            ->selectRaw(
                'CASE WHEN pb.expiry_date IS NULL THEN NULL
                 ELSE DATEDIFF(pb.expiry_date, ?) END as days_remaining',
                [$today]
            )
            ->orderByRaw('pb.expiry_date IS NULL ASC')
            ->orderBy('pb.expiry_date')
            ->orderBy('pb.id')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'message' => 'Batch expiry report retrieved successfully.',
            'summary' => $summary,
            'filters' => [
                'status' => $status,
                'days' => $days,
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'product_id' => $validated['product_id'] ?? null,
            ],
            'data' => $batches,
        ]);
    }

public function alerts(Request $request)
{
    $validated = $request->validate([
        'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        'days' => 'nullable|integer|min:1|max:365',
    ]);

    $today = now()->toDateString();
    $days = (int) ($validated['days'] ?? 30);
    $limit = now()->addDays($days)->toDateString();

    $query = DB::table('product_batches as pb')
        ->join('products as p', 'p.id', '=', 'pb.product_id')
        ->join('warehouses as w', 'w.id', '=', 'pb.warehouse_id')
        ->where('pb.remaining_quantity', '>', 0);

    if (!empty($validated['warehouse_id'])) {
        $query->where('pb.warehouse_id', $validated['warehouse_id']);
    }

    $alerts = (clone $query)
        ->whereNotNull('pb.expiry_date')
        ->whereDate('pb.expiry_date', '<=', $limit)
        ->select([
            'pb.id',
            'pb.batch_number',
            'pb.product_id',
            'pb.warehouse_id',
            'pb.expiry_date',
            'pb.remaining_quantity',
            'pb.unit_cost_afn',
            'p.name as product_name',
            'p.sku as product_sku',
            'w.name as warehouse_name',
        ])
        ->selectRaw(
            'DATEDIFF(pb.expiry_date, ?) as days_remaining',
            [$today]
        )
        ->orderBy('pb.expiry_date')
        ->get();

    return response()->json([
        'message' => 'Batch expiry alerts retrieved successfully.',
        'days_threshold' => $days,
        'summary' => [
            'expired' => $alerts->where('days_remaining', '<', 0)->count(),
            'expiring_today' => $alerts->where('days_remaining', '=', 0)->count(),
            'expiring_soon' => $alerts->where('days_remaining', '>', 0)->count(),
        ],
        'data' => $alerts,
    ]);
}

}
