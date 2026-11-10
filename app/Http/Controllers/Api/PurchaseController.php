<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\PartnerTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\ProductBatch;
use App\Models\InventoryMovementBatch;

class PurchaseController extends Controller
{
    public function index(): JsonResponse
    {
        $purchases = Purchase::with([
            'supplier:id,partner_type,name,business_name',
            'warehouse:id,name',
            'currency:id,code,name,symbol',
            'creator:id,name',
            'items.product:id,name,sku',
        ])
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($purchases);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purchase_number' => ['required', 'string', 'max:50', 'unique:purchases,purchase_number'],
            'business_partner_id' => ['required', 'integer', 'exists:business_partners,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'purchase_date' => ['required', 'date_format:Y-m-d'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'exchange_rate_to_afn' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
'items.*.batch_number' => ['nullable', 'string', 'max:100'],
'items.*.expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:purchase_date'],
            'payment_type' => ['required', 'in:credit,cash'],
            'financial_account_id' => ['required_if:payment_type,cash', 'nullable', 'integer', 'exists:financial_accounts,id'],
        ]);

        $supplier = BusinessPartner::findOrFail($validated['business_partner_id']);
        $warehouse = Warehouse::findOrFail($validated['warehouse_id']);
        $currency = Currency::findOrFail($validated['currency_id']);

        if ($supplier->partner_type !== 'Supplier' || ! $supplier->is_active) {
            throw ValidationException::withMessages([
                'business_partner_id' => ['Select an active supplier.'],
            ]);
        }

        if (! $warehouse->is_active || ! $currency->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['The warehouse and currency must be active.'],
            ]);
        }

        if ($currency->code === 'AFN' && (float) $validated['exchange_rate_to_afn'] !== 1.0) {
            throw ValidationException::withMessages([
                'exchange_rate_to_afn' => ['The AFN exchange rate must be 1.'],
            ]);
        }

        if ($validated['payment_type'] === 'cash') {
            $account = FinancialAccount::findOrFail($validated['financial_account_id']);

            if (! $account->is_active || (int) $account->currency_id !== (int) $currency->id) {
                throw ValidationException::withMessages([
                    'financial_account_id' => [
                        'Choose an active financial account in the purchase currency.',
                    ],
                ]);
            }
        }

        $result = DB::transaction(function () use ($validated, $request, $currency) {
            $subtotal = 0;
            $lineData = [];

            foreach ($validated['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);

                if (! $product->is_active) {
                    throw ValidationException::withMessages([
                        'items' => ["Product {$product->name} is inactive."],
                    ]);
                }

                $quantity = (float) $item['quantity'];
                $unitCost = (float) $item['unit_cost'];
                $lineDiscount = (float) ($item['discount_amount'] ?? 0);
                $gross = round($quantity * $unitCost, 4);

                if ($lineDiscount > $gross) {
                    throw ValidationException::withMessages([
                        'items' => ['A line discount cannot exceed its gross amount.'],
                    ]);
                }

                $lineTotal = round($gross - $lineDiscount, 4);
                $subtotal += $gross;

                $lineData[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'discount_amount' => $lineDiscount,
                    'line_total' => $lineTotal,
                    'batch_number' => $item['batch_number'] ?? null,
'expiry_date' => $item['expiry_date'] ?? null,
                    'unit_cost_afn' => round($unitCost * (float) $validated['exchange_rate_to_afn'], 6),
                    'line_total_afn' => round($lineTotal * (float) $validated['exchange_rate_to_afn'], 4),
                ];
            }

            $headerDiscount = (float) ($validated['discount_amount'] ?? 0);
            $totalBeforeDiscount = array_sum(array_column($lineData, 'line_total'));

            if ($headerDiscount > $totalBeforeDiscount) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['The purchase discount cannot exceed the total.'],
                ]);
            }

            $grandTotal = round($totalBeforeDiscount - $headerDiscount, 4);
            $grandTotalAfn = round($grandTotal * (float) $validated['exchange_rate_to_afn'], 4);

            $purchase = Purchase::create([
                'purchase_number' => $validated['purchase_number'],
                'business_partner_id' => $validated['business_partner_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'currency_id' => $validated['currency_id'],
                'created_by' => $request->user()->id,
                'purchase_date' => $validated['purchase_date'],
                'invoice_number' => $validated['invoice_number'] ?? null,
                'exchange_rate_to_afn' => $validated['exchange_rate_to_afn'],
                'subtotal' => round($subtotal, 4),
                'discount_amount' => $headerDiscount,
                'grand_total' => $grandTotal,
                'grand_total_afn' => $grandTotalAfn,
                'payment_status' => $validated['payment_type'] === 'cash' ? 'paid' : 'unpaid',
                'status' => 'posted',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($lineData as $line) {
                $lineShare = $grandTotal > 0
                    ? ($line['line_total'] / $totalBeforeDiscount)
                    : 0;

                $allocatedHeaderDiscount = $totalBeforeDiscount > 0
                    ? round($headerDiscount * $lineShare, 4)
                    : 0;

                $finalLineTotal = round(
                    $line['line_total'] - $allocatedHeaderDiscount,
                    4
                );

                $finalLineTotalAfn = round(
                    $finalLineTotal * (float) $validated['exchange_rate_to_afn'],
                    4
                );

                $effectiveUnitCostAfn = $line['quantity'] > 0
                    ? round($finalLineTotalAfn / $line['quantity'], 6)
                    : 0;

                $purchaseItem = $purchase->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'discount_amount' => round(
                        $line['discount_amount'] + $allocatedHeaderDiscount,
                        4
                    ),
                    'line_total' => $finalLineTotal,
                    'unit_cost_afn' => $effectiveUnitCostAfn,
                    'line_total_afn' => $finalLineTotalAfn,
                ]);

                $balance = InventoryBalance::where('warehouse_id', $validated['warehouse_id'])
                    ->where('product_id', $line['product']->id)
                    ->lockForUpdate()
                    ->first();

                if (! $balance) {
                    $balance = InventoryBalance::create([
                        'warehouse_id' => $validated['warehouse_id'],
                        'product_id' => $line['product']->id,
                        'quantity' => 0,
                        'average_unit_cost' => 0,
                    ]);
                    $balance = InventoryBalance::whereKey($balance->id)
                        ->lockForUpdate()
                        ->first();
                }

                $oldQuantity = (float) $balance->quantity;
                $oldAverageCost = (float) $balance->average_unit_cost;
                $newQuantity = $oldQuantity + $line['quantity'];

                // Inventory average cost is stored in AFN.
                $newAverageCost = $newQuantity > 0
                    ? (
                        ($oldQuantity * $oldAverageCost)
                        + ($line['quantity'] * $effectiveUnitCostAfn)
                    ) / $newQuantity
                    : 0;

                $balance->update([
                    'quantity' => $newQuantity,
                    'average_unit_cost' => round($newAverageCost, 6),
                ]);

                $movement = InventoryMovement::create([
    'warehouse_id' => $validated['warehouse_id'],
    'product_id' => $line['product']->id,
    'movement_type' => 'purchase',
    'quantity_in' => $line['quantity'],
    'quantity_out' => 0,
    'unit_cost' => $effectiveUnitCostAfn,
    'total_cost' => $finalLineTotalAfn,
    'reference_type' => Purchase::class,
    'reference_id' => $purchase->id,
    'movement_date' => $validated['purchase_date'] . ' 00:00:00',
    'notes' => 'Purchase ' . $purchase->purchase_number,
    'created_by' => $request->user()->id,
]);

$batch = ProductBatch::create([
    'product_id' => $line['product']->id,
    'warehouse_id' => $validated['warehouse_id'],
    'batch_number' => $line['batch_number'] ?? null,
    'expiry_date' => $line['expiry_date'] ?? null,
    'received_quantity' => $line['quantity'],
    'remaining_quantity' => $line['quantity'],
    'unit_cost_afn' => $effectiveUnitCostAfn,
    'source_type' => Purchase::class,
    'source_id' => $purchase->id,
    'created_by' => $request->user()->id,
]);

InventoryMovementBatch::create([
    'inventory_movement_id' => $movement->id,
    'product_batch_id' => $batch->id,
    'direction' => 'in',
    'quantity' => $line['quantity'],
    'unit_cost_afn' => $effectiveUnitCostAfn,
    'total_cost_afn' => $finalLineTotalAfn,
]);

            PartnerTransaction::create([
                'business_partner_id' => $validated['business_partner_id'],
                'currency_id' => $currency->id,
                'created_by' => $request->user()->id,
                'transaction_type' => 'purchase',
                'direction' => 'credit',
                'amount' => $grandTotal,
                'reference_type' => Purchase::class,
                'reference_id' => $purchase->id,
                'transaction_date' => $validated['purchase_date'] . ' 00:00:00',
                'description' => 'Purchase ' . $purchase->purchase_number,
            ]);

            if ($validated['payment_type'] === 'cash') {
                $account = FinancialAccount::whereKey($validated['financial_account_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $group = (string) Str::uuid();

                $purchaseAmount = $grandTotal;

                $accountTransaction = [
                    'financial_account_id' => $account->id,
                    'related_account_id' => null,
                    'created_by' => $request->user()->id,
                    'transaction_type' => 'purchase_payment',
                    'direction' => 'out',
                    'amount' => $purchaseAmount,
                    'exchange_rate' => $validated['exchange_rate_to_afn'],
                    'transaction_group' => $group,
                    'reference_type' => Purchase::class,
                    'reference_id' => $purchase->id,
                    'transaction_date' => $validated['purchase_date'] . ' 00:00:00',
                    'description' => 'Payment for purchase ' . $purchase->purchase_number,
                ];

                \App\Models\FinancialTransaction::create($accountTransaction);

                PartnerTransaction::create([
                    'business_partner_id' => $validated['business_partner_id'],
                    'currency_id' => $currency->id,
                    'created_by' => $request->user()->id,
                    'transaction_type' => 'payment',
                    'direction' => 'debit',
                    'amount' => $purchaseAmount,
                    'reference_type' => Purchase::class,
                    'reference_id' => $purchase->id,
                    'transaction_date' => $validated['purchase_date'] . ' 00:00:00',
                    'description' => 'Payment for purchase ' . $purchase->purchase_number,
                ]);
            }

            return $purchase->load([
                'supplier',
                'warehouse',
                'currency',
                'items.product',
            ]);
        });

        return response()->json([
            'message' => 'Purchase created successfully.',
            'data' => $result,
        ], 201);
    }
}