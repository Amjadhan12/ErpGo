<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnSettlement;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnSettlement;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessPartnerDocumentController extends Controller
{
    public function index(
        Request $request,
        BusinessPartner $businessPartner,
        string $section
    ): JsonResponse {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $isCustomer = $businessPartner->partner_type === 'Customer';
        abort_unless($isCustomer || $businessPartner->partner_type === 'Supplier', 404);

        $query = match ([$businessPartner->partner_type, $section]) {
            ['Customer', 'invoices'] => Sale::query()
                ->where('business_partner_id', $businessPartner->id)
                ->with([
                    'customer:id,partner_type,name,business_name',
                    'warehouse:id,name',
                    'currency:id,code,name,symbol',
                    'creator:id,name',
                    'items.product:id,name,sku,unit',
                ])
                ->orderByDesc('sale_date')
                ->orderByDesc('id'),
            ['Customer', 'payments'] => SalePayment::query()
                ->whereHas('sale', fn (Builder $sale) => $sale
                    ->where('business_partner_id', $businessPartner->id))
                ->with([
                    'sale:id,business_partner_id,sale_number,sale_date,currency_id,grand_total',
                    'sale.items.product:id,name,sku,unit',
                    'financialAccount:id,name,account_type',
                    'currency:id,code,name,symbol',
                    'creator:id,name',
                ])
                ->orderByDesc('payment_date')
                ->orderByDesc('id'),
            ['Customer', 'returns'] => SaleReturn::query()
                ->where('business_partner_id', $businessPartner->id)
                ->whereHas('sale', fn (Builder $sale) => $sale
                    ->where('business_partner_id', $businessPartner->id))
                ->with([
                    'sale:id,business_partner_id,sale_number,sale_date',
                    'customer:id,partner_type,name,business_name',
                    'warehouse:id,name',
                    'currency:id,code,name,symbol',
                    'items.product:id,name,sku,unit',
                    'creator:id,name',
                ])
                ->orderByDesc('return_date')
                ->orderByDesc('id'),
            ['Customer', 'settlements'] => SaleReturnSettlement::query()
                ->whereHas('saleReturn', fn (Builder $saleReturn) => $saleReturn
                    ->where('business_partner_id', $businessPartner->id)
                    ->whereHas('sale', fn (Builder $sale) => $sale
                        ->where('business_partner_id', $businessPartner->id)))
                ->with([
                    'saleReturn:id,business_partner_id,return_number,return_date,sale_id,currency_id,grand_total',
                    'saleReturn.sale:id,sale_number,sale_date',
                    'financialAccount:id,name,account_type',
                    'currency:id,code,name,symbol',
                    'creator:id,name',
                ])
                ->orderByDesc('settlement_date')
                ->orderByDesc('id'),
            ['Supplier', 'invoices'] => Purchase::query()
                ->where('business_partner_id', $businessPartner->id)
                ->with([
                    'supplier:id,partner_type,name,business_name',
                    'warehouse:id,name',
                    'currency:id,code,name,symbol',
                    'creator:id,name',
                    'items.product:id,name,sku',
                ])
                ->orderByDesc('purchase_date')
                ->orderByDesc('id'),
            ['Supplier', 'payments'] => SupplierPayment::query()
                ->where('business_partner_id', $businessPartner->id)
                ->with([
                    'supplier:id,partner_type,name,business_name',
                    'financialAccount:id,name,account_type',
                    'currency:id,code,name,symbol',
                    'creator:id,name',
                ])
                ->orderByDesc('payment_date')
                ->orderByDesc('id'),
            ['Supplier', 'returns'] => PurchaseReturn::query()
                ->where('business_partner_id', $businessPartner->id)
                ->whereHas('purchase', fn (Builder $purchase) => $purchase
                    ->where('business_partner_id', $businessPartner->id))
                ->with([
                    'purchase:id,business_partner_id,purchase_number,purchase_date',
                    'supplier:id,partner_type,name,business_name',
                    'warehouse:id,name',
                    'currency:id,code,name,symbol',
                    'items.product:id,name,sku',
                    'creator:id,name',
                ])
                ->orderByDesc('return_date')
                ->orderByDesc('id'),
            ['Supplier', 'settlements'] => PurchaseReturnSettlement::query()
                ->whereHas('purchaseReturn', fn (Builder $purchaseReturn) => $purchaseReturn
                    ->where('business_partner_id', $businessPartner->id)
                    ->whereHas('purchase', fn (Builder $purchase) => $purchase
                        ->where('business_partner_id', $businessPartner->id)))
                ->with([
                    'purchaseReturn:id,business_partner_id,return_number,return_date,purchase_id,currency_id,grand_total',
                    'purchaseReturn.purchase:id,purchase_number,purchase_date',
                    'financialAccount:id,name,account_type',
                    'currency:id,code,name,symbol',
                    'creator:id,name',
                ])
                ->orderByDesc('settlement_date')
                ->orderByDesc('id'),
            default => abort(404),
        };

        return response()->json($query->paginate($validated['per_page'] ?? 10));
    }
}
