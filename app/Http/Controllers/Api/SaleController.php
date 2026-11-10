<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\PartnerTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\ProductBatch;
use App\Models\SaleItemBatch;
use App\Models\InventoryMovementBatch;


class SaleController extends Controller
{
    public function index(): JsonResponse
    {
        $sales = Sale::with([
            'customer:id,partner_type,name,business_name',
            'warehouse:id,name',
            'currency:id,code,name,symbol',
            'creator:id,name',
            'items.product:id,name,sku,unit',
            'payments.financialAccount',
        ])
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($sales);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sale_number' => [
                'required', 'string', 'max:50',
                'unique:sales,sale_number',
            ],
            'business_partner_id' => [
                'required', 'integer',
                'exists:business_partners,id',
            ],
            'warehouse_id' => [
                'required', 'integer', 'exists:warehouses,id',
            ],
            'currency_id' => [
                'required', 'integer', 'exists:currencies,id',
            ],
            'sale_date' => ['required', 'date_format:Y-m-d'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'exchange_rate_to_afn' => [
                'required', 'numeric', 'gt:0', 'decimal:0,8',
            ],
            'discount_amount' => [
                'nullable', 'numeric', 'min:0', 'decimal:0,4',
            ],
            'notes' => ['nullable', 'string', 'max:5000'],

            'payment_type' => [
                'required', 'in:cash,credit,partial',
            ],
            'paid_amount' => [
                'nullable', 'numeric', 'min:0', 'decimal:0,4',
            ],
            'payment_number' => [
                'required_if:payment_type,cash,partial',
                'nullable', 'string', 'max:50',
                'unique:sale_payments,payment_number',
            ],
            'financial_account_id' => [
                'required_if:payment_type,cash,partial',
                'nullable', 'integer',
                'exists:financial_accounts,id',
            ],
            'payment_date' => [
                'nullable', 'date_format:Y-m-d',
            ],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required', 'integer', 'distinct',
                'exists:products,id',
            ],
            'items.*.quantity' => [
                'required', 'numeric', 'gt:0', 'decimal:0,4',
            ],
            'items.*.unit_price' => [
                'required', 'numeric', 'min:0', 'decimal:0,4',
            ],
            'items.*.discount_amount' => [
                'nullable', 'numeric', 'min:0', 'decimal:0,4',
            ],
            'items.*.notes' => [
                'nullable', 'string', 'max:1000',
            ],
        ]);

        $paidAmount = round((float) ($validated['paid_amount'] ?? 0), 4);
        $headerDiscount = round((float) ($validated['discount_amount'] ?? 0), 4);

        $sale = DB::transaction(function () use (
            $validated,
            $request,
            $paidAmount,
            $headerDiscount
        ) {
            $customer = BusinessPartner::whereKey(
                $validated['business_partner_id']
            )->lockForUpdate()->firstOrFail();

            if (
                $customer->partner_type !== 'Customer' ||
                ! $customer->is_active
            ) {
                throw ValidationException::withMessages([
                    'business_partner_id' =>
                        'The selected customer is inactive or invalid.',
                ]);
            }

            $warehouse = Warehouse::whereKey(
                $validated['warehouse_id']
            )->lockForUpdate()->firstOrFail();

            if (! $warehouse->is_active) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'The warehouse is inactive.',
                ]);
            }

            $currency = Currency::whereKey(
                $validated['currency_id']
            )->lockForUpdate()->firstOrFail();

            if (! $currency->is_active) {
                throw ValidationException::withMessages([
                    'currency_id' => 'The currency is inactive.',
                ]);
            }

            $exchangeRate = (float) $validated['exchange_rate_to_afn'];

            if ($currency->code === 'AFN' && $exchangeRate !== 1.0) {
                throw ValidationException::withMessages([
                    'exchange_rate_to_afn' =>
                        'The AFN exchange rate must be 1.',
                ]);
            }

            $account = null;

            if ($paidAmount > 0) {
                $account = FinancialAccount::whereKey(
                    $validated['financial_account_id']
                )->lockForUpdate()->firstOrFail();

                if (! $account->is_active) {
                    throw ValidationException::withMessages([
                        'financial_account_id' =>
                            'The financial account is inactive.',
                    ]);
                }

                if ((int) $account->currency_id !== (int) $currency->id) {
                    throw ValidationException::withMessages([
                        'financial_account_id' =>
                            'Account currency must match sale currency.',
                    ]);
                }
            }

            // Validate products and calculate subtotal.
            $preparedItems = [];
            $subtotal = 0;
            $itemDiscountTotal = 0;

            foreach ($validated['items'] as $item) {
                $product = Product::whereKey($item['product_id'])
                    ->where('is_active', true)
                    ->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'One or more selected products are inactive.',
                    ]);
                }

                $quantity = round((float) $item['quantity'], 4);
                $unitPrice = round((float) $item['unit_price'], 4);
                $itemDiscount = round(
                    (float) ($item['discount_amount'] ?? 0),
                    4
                );

                $gross = round($quantity * $unitPrice, 4);

                if ($itemDiscount > $gross) {
                    throw ValidationException::withMessages([
                        'items' =>
                            'An item discount cannot exceed its gross amount.',
                    ]);
                }

                $net = round($gross - $itemDiscount, 4);

                $subtotal += $gross;
                $itemDiscountTotal += $itemDiscount;

                $preparedItems[] = [
                    'product' => $product,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'gross' => $gross,
                    'item_discount' => $itemDiscount,
                    'net' => $net,
                    'notes' => $item['notes'] ?? null,
                ];
            }

            $subtotal = round($subtotal, 4);
            $itemDiscountTotal = round($itemDiscountTotal, 4);

            $availableForHeaderDiscount = round(
                $subtotal - $itemDiscountTotal,
                4
            );

            if ($headerDiscount > $availableForHeaderDiscount) {
                throw ValidationException::withMessages([
                    'discount_amount' =>
                        'The total discount cannot exceed the sale subtotal.',
                ]);
            }

            $grandTotal = round(
                $subtotal - $itemDiscountTotal - $headerDiscount,
                4
            );

            if ($grandTotal <= 0) {
                throw ValidationException::withMessages([
                    'discount_amount' =>
                        'Sale total must be greater than zero.',
                ]);
            }

            // Validate payment type against the final sale total.
            if (
                $validated['payment_type'] === 'credit' &&
                $paidAmount != 0
            ) {
                throw ValidationException::withMessages([
                    'paid_amount' =>
                        'Credit sales must have a paid amount of zero.',
                ]);
            }

            if (
                $validated['payment_type'] === 'cash' &&
                $paidAmount != $grandTotal
            ) {
                throw ValidationException::withMessages([
                    'paid_amount' =>
                        'Cash sales must be paid in full.',
                ]);
            }

            if (
                $validated['payment_type'] === 'partial' &&
                ($paidAmount <= 0 || $paidAmount >= $grandTotal)
            ) {
                throw ValidationException::withMessages([
                    'paid_amount' =>
                        'Partial payment must be greater than zero and less than the sale total.',
                ]);
            }

            if ($paidAmount > $grandTotal) {
                throw ValidationException::withMessages([
                    'paid_amount' =>
                        'Paid amount cannot exceed the sale total.',
                ]);
            }

            $paymentDate = $validated['payment_date']
                ?? $validated['sale_date'];

            if ($paymentDate < $validated['sale_date']) {
                throw ValidationException::withMessages([
                    'payment_date' =>
                        'Payment date cannot be before the sale date.',
                ]);
            }

            $paymentStatus = $paidAmount == 0
                ? 'unpaid'
                : ($paidAmount >= $grandTotal ? 'paid' : 'partial');

            $sale = Sale::create([
                'sale_number' => $validated['sale_number'],
                'business_partner_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'currency_id' => $currency->id,
                'created_by' => $request->user()->id,
                'sale_date' => $validated['sale_date'],
                'invoice_number' => $validated['invoice_number'] ?? null,
                'exchange_rate_to_afn' => $exchangeRate,
                'subtotal' => $subtotal,
                'discount_amount' => round(
                    $itemDiscountTotal + $headerDiscount,
                    4
                ),
                'grand_total' => $grandTotal,
                'grand_total_afn' => round(
                    $grandTotal * $exchangeRate,
                    4
                ),
                'payment_status' => $paymentStatus,
                'status' => 'posted',
                'notes' => $validated['notes'] ?? null,
            ]);

            // Allocate header discount proportionally.
           
// Allocate header discount proportionally.
$netSubtotal = $availableForHeaderDiscount;
$allocatedHeaderDiscount = 0;
$lastIndex = count($preparedItems) - 1;

foreach ($preparedItems as $index => $item) {
    $balance = InventoryBalance::where('warehouse_id', $warehouse->id)
        ->where('product_id', $item['product_id'])
        ->lockForUpdate()
        ->first();

    if (! $balance) {
        throw ValidationException::withMessages([
            'items' => 'Inventory balance not found for product '
                . $item['product']->name . '.',
        ]);
    }

    if ((float) $balance->quantity < $item['quantity']) {
        throw ValidationException::withMessages([
            'items' => 'Insufficient stock for product '
                . $item['product']->name
                . '. Available: ' . $balance->quantity,
        ]);
    }

    if ($index === $lastIndex) {
        $headerShare = round(
            $headerDiscount - $allocatedHeaderDiscount,
            4
        );
    } elseif ($netSubtotal > 0) {
        $headerShare = round(
            $headerDiscount * $item['net'] / $netSubtotal,
            4
        );
        $allocatedHeaderDiscount += $headerShare;
    } else {
        $headerShare = 0;
    }

    $totalItemDiscount = round(
        $item['item_discount'] + $headerShare,
        4
    );

    $lineTotal = round(
        $item['gross'] - $totalItemDiscount,
        4
    );

    // Get eligible batches using FEFO.
    $batches = ProductBatch::where('product_id', $item['product_id'])
        ->where('warehouse_id', $warehouse->id)
        ->where('remaining_quantity', '>', 0)
        ->where(function ($query) use ($validated) {
            $query->whereNull('expiry_date')
                ->orWhereDate('expiry_date', '>=', $validated['sale_date']);
        })
        ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
        ->orderBy('expiry_date')
        ->orderBy('id')
        ->lockForUpdate()
        ->get();

    $batchAvailable = (float) $batches->sum('remaining_quantity');

    if ($batchAvailable < $item['quantity']) {
        throw ValidationException::withMessages([
            'items' => 'Not enough non-expired batch stock for product '
                . $item['product']->name
                . '. Available batch stock: ' . $batchAvailable,
        ]);
    }

    // Calculate actual cost from selected batches.
    $remainingToAllocate = $item['quantity'];
    $totalCost = 0;
    $allocations = [];

    foreach ($batches as $batch) {
        if ($remainingToAllocate <= 0) {
            break;
        }

        $available = (float) $batch->remaining_quantity;
        $allocatedQty = min($available, $remainingToAllocate);
        $batchUnitCost = (float) $batch->unit_cost_afn;
        $batchTotalCost = round($allocatedQty * $batchUnitCost, 4);

        $allocations[] = [
            'batch' => $batch,
            'quantity' => $allocatedQty,
            'unit_cost_afn' => $batchUnitCost,
            'total_cost_afn' => $batchTotalCost,
        ];

        $totalCost += $batchTotalCost;
        $remainingToAllocate -= $allocatedQty;
    }

    $totalCost = round($totalCost, 4);
    $averageUnitCost = round($totalCost / $item['quantity'], 6);

    $unitPriceAfn = round(
        $item['unit_price'] * $exchangeRate,
        6
    );

    $lineTotalAfn = round(
        $lineTotal * $exchangeRate,
        4
    );

    // Create sale item using actual batch cost.
    $saleItem = SaleItem::create([
        'sale_id' => $sale->id,
        'product_id' => $item['product_id'],
        'quantity' => $item['quantity'],
        'unit_price' => $item['unit_price'],
        'discount_amount' => $totalItemDiscount,
        'line_total' => $lineTotal,
        'unit_price_afn' => $unitPriceAfn,
        'line_total_afn' => $lineTotalAfn,
        'unit_cost_afn' => $averageUnitCost,
        'total_cost_afn' => $totalCost,
        'notes' => $item['notes'],
    ]);

    // Deduct quantities from batches and record allocations.
    foreach ($allocations as $allocation) {
        $batch = $allocation['batch'];

        $batch->update([
            'remaining_quantity' => round(
                (float) $batch->remaining_quantity
                    - $allocation['quantity'],
                4
            ),
        ]);

        SaleItemBatch::create([
            'sale_item_id' => $saleItem->id,
            'product_batch_id' => $batch->id,
            'quantity' => $allocation['quantity'],
            'unit_cost_afn' => $allocation['unit_cost_afn'],
            'total_cost_afn' => $allocation['total_cost_afn'],
        ]);
    }

    // Reduce aggregate inventory balance.
    $balance->update([
        'quantity' => round(
            (float) $balance->quantity - $item['quantity'],
            4
        ),
    ]);

    // Record inventory out movement.
    $movement = InventoryMovement::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $item['product_id'],
        'movement_type' => 'sale',
        'quantity_in' => 0,
        'quantity_out' => $item['quantity'],
        'unit_cost' => $averageUnitCost,
        'total_cost' => $totalCost,
        'reference_type' => Sale::class,
        'reference_id' => $sale->id,
        'movement_date' => $validated['sale_date'],
        'notes' => 'Sale ' . $sale->sale_number,
        'created_by' => $request->user()->id,
    ]);

    // Link inventory movement to consumed batches.
    foreach ($allocations as $allocation) {
        InventoryMovementBatch::create([
            'inventory_movement_id' => $movement->id,
            'product_batch_id' => $allocation['batch']->id,
            'direction' => 'out',
            'quantity' => $allocation['quantity'],
            'unit_cost_afn' => $allocation['unit_cost_afn'],
            'total_cost_afn' => $allocation['total_cost_afn'],
        ]);
    }
}



            // Record the customer's full receivable amount.
            PartnerTransaction::create([
                'business_partner_id' => $customer->id,
                'currency_id' => $currency->id,
                'created_by' => $request->user()->id,
                'transaction_type' => 'sale',
                'direction' => 'debit',
                'amount' => $grandTotal,
                'reference_type' => Sale::class,
                'reference_id' => $sale->id,
                'transaction_date' => $validated['sale_date'],
                'description' => 'Sale ' . $sale->sale_number,
            ]);

            // Record the initial payment, if any.
            if ($paidAmount > 0) {
                $payment = SalePayment::create([
                    'sale_id' => $sale->id,
                    'payment_number' => $validated['payment_number'],
                    'financial_account_id' => $account->id,
                    'currency_id' => $currency->id,
                    'created_by' => $request->user()->id,
                    'amount' => $paidAmount,
                    'exchange_rate_to_afn' => $exchangeRate,
                    'payment_date' => $paymentDate,
                    'notes' => 'Initial payment for sale ' . $sale->sale_number,
                ]);

                PartnerTransaction::create([
                    'business_partner_id' => $customer->id,
                    'currency_id' => $currency->id,
                    'created_by' => $request->user()->id,
                    'transaction_type' => 'payment',
                    'direction' => 'credit',
                    'amount' => $paidAmount,
                    'reference_type' => SalePayment::class,
                    'reference_id' => $payment->id,
                    'transaction_date' => $paymentDate,
                    'description' =>
                        'Initial payment for sale ' . $sale->sale_number,
                ]);

                FinancialTransaction::create([
                    'financial_account_id' => $account->id,
                    'related_account_id' => null,
                    'created_by' => $request->user()->id,
                    'transaction_type' => 'sale_payment',
                    'direction' => 'in',
                    'amount' => $paidAmount,
                    'exchange_rate' => $exchangeRate,
                    'transaction_group' => (string) Str::uuid(),
                    'reference_type' => SalePayment::class,
                    'reference_id' => $payment->id,
                    'transaction_date' => $paymentDate,
                    'description' =>
                        'Payment received for sale ' . $sale->sale_number,
                ]);
            }

            return $sale;
        });

        $sale->load([
            'customer',
            'warehouse',
            'currency',
            'creator',
            'items.product',
            'payments.financialAccount',
        ]);

        return response()->json([
            'message' => 'Sale created successfully.',
            'data' => $sale,
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $sale = Sale::with([
            'customer',
            'warehouse',
            'currency',
            'creator',
            'items.product',
            'payments.financialAccount',
            'payments.currency',
        ])->findOrFail($id);

        return response()->json([
            'data' => $sale,
        ]);
    }
}
