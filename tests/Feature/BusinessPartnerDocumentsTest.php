<?php

namespace Tests\Feature;

use App\Models\BusinessPartner;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnSettlement;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnSettlement;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessPartnerDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_document_sections_are_scoped_to_the_selected_customer(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]), 'sanctum');
        [$currency, $warehouse, $account] = $this->createDocumentResources();
        $first = BusinessPartner::create([
            'partner_type' => 'Customer',
            'name' => 'First customer',
        ]);
        $second = BusinessPartner::create([
            'partner_type' => 'Customer',
            'name' => 'Second customer',
        ]);
        $documents = [
            $this->createCustomerDocuments($first, 'FIRST', $currency, $warehouse, $account),
            $this->createCustomerDocuments($second, 'SECOND', $currency, $warehouse, $account),
        ];
        $foreignSale = Sale::where('sale_number', $documents[1]['invoices'])->firstOrFail();
        $crossPartnerReturn = SaleReturn::create([
            'return_number' => 'CROSS-PARTNER-SALE-RETURN',
            'sale_id' => $foreignSale->id,
            'business_partner_id' => $first->id,
            'warehouse_id' => $warehouse->id,
            'currency_id' => $currency->id,
            'return_date' => '2026-11-05',
            'exchange_rate_to_afn' => 1,
            'grand_total' => 5,
            'grand_total_afn' => 5,
            'status' => 'posted',
        ]);
        SaleReturnSettlement::create([
            'settlement_number' => 'CROSS-PARTNER-SALE-SETTLEMENT',
            'sale_return_id' => $crossPartnerReturn->id,
            'financial_account_id' => $account->id,
            'currency_id' => $currency->id,
            'amount' => 5,
            'exchange_rate_to_afn' => 1,
            'settlement_date' => '2026-11-06',
        ]);

        foreach ([
            'invoices' => 'sale_number',
            'payments' => 'payment_number',
            'returns' => 'return_number',
            'settlements' => 'settlement_number',
        ] as $section => $numberField) {
            $this->getJson("/api/business-partners/{$first->id}/documents/{$section}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath("data.0.{$numberField}", $documents[0][$section])
                ->assertJsonMissingPath("data.1.{$numberField}");
        }

        $this->getJson("/api/business-partners/{$first->id}/documents/invoices?per_page=101")
            ->assertUnprocessable();
    }

    public function test_supplier_document_sections_are_scoped_to_the_selected_supplier(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]), 'sanctum');
        [$currency, $warehouse, $account] = $this->createDocumentResources();
        $first = BusinessPartner::create([
            'partner_type' => 'Supplier',
            'name' => 'First supplier',
        ]);
        $second = BusinessPartner::create([
            'partner_type' => 'Supplier',
            'name' => 'Second supplier',
        ]);
        $documents = [
            $this->createSupplierDocuments($first, 'FIRST', $currency, $warehouse, $account),
            $this->createSupplierDocuments($second, 'SECOND', $currency, $warehouse, $account),
        ];
        $foreignPurchase = Purchase::where('purchase_number', $documents[1]['invoices'])->firstOrFail();
        $crossPartnerReturn = PurchaseReturn::create([
            'return_number' => 'CROSS-PARTNER-PURCHASE-RETURN',
            'purchase_id' => $foreignPurchase->id,
            'business_partner_id' => $first->id,
            'warehouse_id' => $warehouse->id,
            'currency_id' => $currency->id,
            'return_date' => '2026-11-05',
            'exchange_rate_to_afn' => 1,
            'grand_total' => 5,
            'grand_total_afn' => 5,
            'status' => 'posted',
        ]);
        PurchaseReturnSettlement::create([
            'settlement_number' => 'CROSS-PARTNER-PURCHASE-SETTLEMENT',
            'purchase_return_id' => $crossPartnerReturn->id,
            'financial_account_id' => $account->id,
            'currency_id' => $currency->id,
            'amount' => 5,
            'exchange_rate_to_afn' => 1,
            'settlement_date' => '2026-11-06',
        ]);

        foreach ([
            'invoices' => 'purchase_number',
            'payments' => 'payment_number',
            'returns' => 'return_number',
            'settlements' => 'settlement_number',
        ] as $section => $numberField) {
            $this->getJson("/api/business-partners/{$first->id}/documents/{$section}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath("data.0.{$numberField}", $documents[0][$section])
                ->assertJsonMissingPath("data.1.{$numberField}");
        }

        $this->getJson("/api/business-partners/{$first->id}/documents/unknown")
            ->assertNotFound();
        $this->getJson("/api/business-partners/{$first->id}/documents/payments?page=0")
            ->assertUnprocessable();
    }

    public function test_document_sections_are_available_only_to_secretaries(): void
    {
        $supplier = BusinessPartner::create([
            'partner_type' => 'Supplier',
            'name' => 'Supplier',
        ]);
        $this->actingAs(User::factory()->create([
            'role' => 'Customer',
            'is_active' => true,
        ]), 'sanctum');

        $this->getJson("/api/business-partners/{$supplier->id}/documents/invoices")
            ->assertForbidden();
    }

    private function createDocumentResources(): array
    {
        $currency = Currency::create([
            'code' => 'AFN',
            'name' => 'Afghani',
            'symbol' => '؋',
            'decimal_places' => 2,
            'is_base' => true,
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create(['name' => 'Partner documents warehouse']);
        $account = FinancialAccount::create([
            'currency_id' => $currency->id,
            'name' => 'Partner documents account',
            'account_type' => 'cash',
            'holder_type' => 'company',
            'is_active' => true,
        ]);

        return [$currency, $warehouse, $account];
    }

    private function createCustomerDocuments(
        BusinessPartner $customer,
        string $prefix,
        Currency $currency,
        Warehouse $warehouse,
        FinancialAccount $account
    ): array {
        $sale = Sale::create([
            'sale_number' => "{$prefix}-SALE",
            'business_partner_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency_id' => $currency->id,
            'sale_date' => '2026-11-01',
            'exchange_rate_to_afn' => 1,
            'grand_total' => 100,
            'grand_total_afn' => 100,
            'status' => 'posted',
        ]);
        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'payment_number' => "{$prefix}-RECEIPT",
            'financial_account_id' => $account->id,
            'currency_id' => $currency->id,
            'amount' => 25,
            'exchange_rate_to_afn' => 1,
            'payment_date' => '2026-11-02',
        ]);
        $return = SaleReturn::create([
            'return_number' => "{$prefix}-SALE-RETURN",
            'sale_id' => $sale->id,
            'business_partner_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency_id' => $currency->id,
            'return_date' => '2026-11-03',
            'exchange_rate_to_afn' => 1,
            'grand_total' => 10,
            'grand_total_afn' => 10,
            'status' => 'posted',
        ]);
        $settlement = SaleReturnSettlement::create([
            'settlement_number' => "{$prefix}-SALE-SETTLEMENT",
            'sale_return_id' => $return->id,
            'financial_account_id' => $account->id,
            'currency_id' => $currency->id,
            'amount' => 10,
            'exchange_rate_to_afn' => 1,
            'settlement_date' => '2026-11-04',
        ]);

        return [
            'invoices' => $sale->sale_number,
            'payments' => $payment->payment_number,
            'returns' => $return->return_number,
            'settlements' => $settlement->settlement_number,
        ];
    }

    private function createSupplierDocuments(
        BusinessPartner $supplier,
        string $prefix,
        Currency $currency,
        Warehouse $warehouse,
        FinancialAccount $account
    ): array {
        $purchase = Purchase::create([
            'purchase_number' => "{$prefix}-PURCHASE",
            'business_partner_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'currency_id' => $currency->id,
            'purchase_date' => '2026-11-01',
            'exchange_rate_to_afn' => 1,
            'grand_total' => 100,
            'grand_total_afn' => 100,
            'status' => 'posted',
        ]);
        $payment = SupplierPayment::create([
            'payment_number' => "{$prefix}-SUPPLIER-PAYMENT",
            'business_partner_id' => $supplier->id,
            'financial_account_id' => $account->id,
            'currency_id' => $currency->id,
            'amount' => 25,
            'exchange_rate_to_afn' => 1,
            'payment_date' => '2026-11-02',
        ]);
        $return = PurchaseReturn::create([
            'return_number' => "{$prefix}-PURCHASE-RETURN",
            'purchase_id' => $purchase->id,
            'business_partner_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'currency_id' => $currency->id,
            'return_date' => '2026-11-03',
            'exchange_rate_to_afn' => 1,
            'grand_total' => 10,
            'grand_total_afn' => 10,
            'status' => 'posted',
        ]);
        $settlement = PurchaseReturnSettlement::create([
            'settlement_number' => "{$prefix}-PURCHASE-SETTLEMENT",
            'purchase_return_id' => $return->id,
            'financial_account_id' => $account->id,
            'currency_id' => $currency->id,
            'amount' => 10,
            'exchange_rate_to_afn' => 1,
            'settlement_date' => '2026-11-04',
        ]);

        return [
            'invoices' => $purchase->purchase_number,
            'payments' => $payment->payment_number,
            'returns' => $return->return_number,
            'settlements' => $settlement->settlement_number,
        ];
    }
}
