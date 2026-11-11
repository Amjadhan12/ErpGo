<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleItem;
use App\Models\SaleReturnItem;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Models\StockWastageItem;

class ProfitLossReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        $fromDate = $validated['from_date'] ?? null;
        $toDate = $validated['to_date'] ?? null;

        // 1. Gross Sales
        $salesQuery = Sale::query()
            ->where('status', 'posted');

        $this->applyDateFilters($salesQuery, 'sale_date', $fromDate, $toDate);

        $grossSales = (float) $salesQuery->sum('grand_total_afn');

        // 2. Sales Returns
        $returnsQuery = SaleReturn::query()
            ->where('status', 'posted');

        $this->applyDateFilters($returnsQuery, 'return_date', $fromDate, $toDate);

        $salesReturns = (float) $returnsQuery->sum('grand_total_afn');

        // 3. Cost of Goods Sold
        $cogsQuery = SaleItem::query()
            ->whereHas('sale', function (Builder $query) use ($fromDate, $toDate) {
                $query->where('status', 'posted');

                $this->applyDateFilters(
                    $query,
                    'sale_date',
                    $fromDate,
                    $toDate
                );
            });

        $costOfGoodsSold = (float) $cogsQuery->sum('total_cost_afn');

        // 4. Cost of Returned Goods
        $returnedCostQuery = SaleReturnItem::query()
            ->whereHas('saleReturn', function (Builder $query) use ($fromDate, $toDate) {
                $query->where('status', 'posted');

                $this->applyDateFilters(
                    $query,
                    'return_date',
                    $fromDate,
                    $toDate
                );
            });

        $returnedGoodsCost = (float) $returnedCostQuery->sum('total_cost_afn');

        // 5. Operating Expenses
        $expensesQuery = Expense::query()
            ->with('category:id,name');

        $this->applyDateFilters(
            $expensesQuery,
            'expense_date',
            $fromDate,
            $toDate
        );

        $expenses = $expensesQuery->get();

        $operatingExpenses = (float) $expenses->sum(function ($expense) {
            return (float) $expense->amount
                * (float) $expense->exchange_rate_to_afn;
        });
        // 6. Stock Wastage Cost
$wastageQuery = StockWastageItem::whereHas('stockWastage', function (Builder $query) use ($fromDate, $toDate) {
    $query->where('status', 'posted');

    $this->applyDateFilters(
        $query,
        'wastage_date',
        $fromDate,
        $toDate
    );
});

$stockWastageCost = (float) $wastageQuery->sum('total_cost_afn');

        // 6. Expense Category Breakdown
        $expensesByCategory = $expenses
            ->groupBy(function ($expense) {
                return $expense->expense_category_id ?? 'uncategorized';
            })
            ->map(function ($categoryExpenses) {
                $firstExpense = $categoryExpenses->first();

                $total = $categoryExpenses->sum(function ($expense) {
                    return (float) $expense->amount
                        * (float) $expense->exchange_rate_to_afn;
                });

                return [
                    'category_id' => $firstExpense->expense_category_id,
                    'category_name' => $firstExpense->category?->name
                        ?? 'Uncategorized',
                    'total_afn' => round($total, 2),
                ];
            })
            ->values();

        // 7. Profit Calculations
        $netRevenue = $grossSales - $salesReturns;

        $netCostOfGoodsSold = $costOfGoodsSold - $returnedGoodsCost;

        $grossProfit = $netRevenue - $netCostOfGoodsSold;

        $netProfit = $grossProfit - $operatingExpenses - $stockWastageCost;

        return response()->json([
            'message' => 'Profit and loss report retrieved successfully.',

            'data' => [
                'filters' => [
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    
                ],

                'currency' => 'AFN',

                'revenue' => [
                    'gross_sales' => round($grossSales, 2),
                    'sales_returns' => round($salesReturns, 2),
                    'net_revenue' => round($netRevenue, 2),
                ],

                'cost_of_goods_sold' => [
                    'total_cost' => round($costOfGoodsSold, 2),
                    'returned_goods_cost' => round($returnedGoodsCost, 2),
                    'net_cost' => round($netCostOfGoodsSold, 2),
                ],
'profit' => [
    'gross_profit' => round($grossProfit, 2),
    'operating_expenses' => round($operatingExpenses, 2),
    'stock_wastage_cost' => round($stockWastageCost, 2),
    'net_profit' => round($netProfit, 2),
],

                'expenses_by_category' => $expensesByCategory,
            ],
        ]);
    }

    private function applyDateFilters(
        Builder $query,
        string $column,
        ?string $fromDate,
        ?string $toDate
    ): void {
        if ($fromDate) {
            $query->whereDate($column, '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate($column, '<=', $toDate);
        }
    }
}
