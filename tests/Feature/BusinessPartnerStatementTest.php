<?php

namespace Tests\Feature;

use App\Models\BusinessPartner;
use App\Models\Currency;
use App\Models\PartnerOpeningBalance;
use App\Models\PartnerTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessPartnerStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_statement_calculates_month_opening_and_totals_independent_of_pagination(): void
    {
        $secretary = User::factory()->create(['role' => 'Secretary', 'is_active' => true]);
        $customer = $this->makePartner('Customer');
        $afn = $this->makeCurrency('AFN');
        $usd = $this->makeCurrency('USD');
        $this->makeCurrency('PKR');
        PartnerOpeningBalance::create(['business_partner_id' => $customer->id, 'currency_id' => $afn->id, 'amount' => 100, 'opening_date' => '2025-01-01']);
        $this->transaction($customer, $afn, 'sale', 'debit', 40, '2025-01-15');
        $this->transaction($customer, $afn, 'sale', 'debit', 20, '2025-02-05');
        $this->transaction($customer, $afn, 'payment', 'credit', 5, '2025-02-08');
        $this->transaction($customer, $afn, 'sale_return', 'credit', 3, '2025-02-09');
        $this->transaction($customer, $afn, 'sale_return_refund', 'debit', 2, '2025-02-10');
        $this->transaction($customer, $usd, 'sale', 'debit', 10, '2025-02-10');
        $this->transaction($customer, $afn, 'sale', 'debit', 7, '2025-03-01');

        $response = $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/business-partners/' . $customer->id . '/statement?year=2025&month=2&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.partner.partner_type', 'Customer')
            ->assertJsonPath('data.currency_summaries.0.currency', 'AFN')
            ->assertJsonPath('data.currency_summaries.0.opening_balance', 140)
            ->assertJsonPath('data.currency_summaries.0.period_debit_total', 22)
            ->assertJsonPath('data.currency_summaries.0.period_credit_total', 8)
            ->assertJsonPath('data.currency_summaries.0.sales_or_purchases_total', 20)
            ->assertJsonPath('data.currency_summaries.0.returns_total', 3)
            ->assertJsonPath('data.currency_summaries.0.payments_total', 5)
            ->assertJsonPath('data.currency_summaries.0.return_settlements_total', 2)
            ->assertJsonPath('data.currency_summaries.0.closing_balance', 154)
            ->assertJsonPath('data.transactions.total', 5)
            ->assertJsonPath('data.transactions.last_page', 5)
            ->assertJsonPath('data.transactions.data.0.currency', 'USD')
            ->assertJsonPath('data.transactions.per_page', 1);

        $this->assertCount(1, $response->json('data.transactions.data'));
        $this->assertEquals(10, (float) collect($response->json('data.currency_summaries'))->firstWhere('currency', 'USD')['closing_balance']);
        $this->assertEquals(0, (float) collect($response->json('data.currency_summaries'))->firstWhere('currency', 'PKR')['closing_balance']);

        $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/business-partners/' . $customer->id . '/statement?year=2025&per_page=100')
            ->assertOk()
            ->assertJsonPath('data.currency_summaries.0.opening_balance', 0)
            ->assertJsonPath('data.currency_summaries.0.period_opening_balance_entries', 100)
            ->assertJsonPath('data.currency_summaries.0.closing_balance', 161)
            ->assertJsonPath('data.period.month', null);
    }

    public function test_supplier_statement_uses_supplier_balance_direction_and_purchase_returns(): void
    {
        $secretary = User::factory()->create(['role' => 'Secretary', 'is_active' => true]);
        $supplier = $this->makePartner('Supplier');
        $afn = $this->makeCurrency('AFN');
        PartnerOpeningBalance::create(['business_partner_id' => $supplier->id, 'currency_id' => $afn->id, 'amount' => 80, 'opening_date' => '2024-12-01']);
        $this->transaction($supplier, $afn, 'purchase', 'credit', 50, '2025-01-02');
        $this->transaction($supplier, $afn, 'payment', 'debit', 20, '2025-01-03');
        $this->transaction($supplier, $afn, 'purchase_return', 'debit', 4, '2025-01-04');
        $this->transaction($supplier, $afn, 'purchase_return_refund', 'credit', 1, '2025-01-05');

        $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/business-partners/' . $supplier->id . '/statement?year=2025&month=1')
            ->assertOk()
            ->assertJsonPath('data.currency_summaries.0.opening_balance', 80)
            ->assertJsonPath('data.currency_summaries.0.period_credit_total', 51)
            ->assertJsonPath('data.currency_summaries.0.period_debit_total', 24)
            ->assertJsonPath('data.currency_summaries.0.sales_or_purchases_total', 50)
            ->assertJsonPath('data.currency_summaries.0.returns_total', 4)
            ->assertJsonPath('data.currency_summaries.0.payments_total', 20)
            ->assertJsonPath('data.currency_summaries.0.return_settlements_total', 1)
            ->assertJsonPath('data.currency_summaries.0.closing_balance', 107)
            ->assertJsonPath('data.currency_summaries.0.balance_type', 'we_owe_supplier');

        $this->getJson('/api/business-partners/' . $supplier->id . '/statement?year=2025')
            ->assertOk()
            ->assertJsonPath('data.currency_summaries.0.opening_balance', 80)
            ->assertJsonPath('data.currency_summaries.0.closing_balance', 107)
            ->assertJsonPath('data.period.month', null);
    }

    public function test_statement_requires_secretary_and_validates_period_and_partner(): void
    {
        $customer = $this->makePartner('Customer');
        $this->getJson('/api/business-partners/' . $customer->id . '/statement?year=2025')->assertUnauthorized();

        $customerUser = User::factory()->create(['role' => 'Customer', 'is_active' => true]);
        $this->actingAs($customerUser, 'sanctum')
            ->getJson('/api/business-partners/' . $customer->id . '/statement?year=2025')
            ->assertForbidden();

        $secretary = User::factory()->create(['role' => 'Secretary', 'is_active' => true]);
        $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/business-partners/' . $customer->id . '/statement?year=2025&month=13')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');

        $this->getJson('/api/business-partners/999999/statement?year=2025')->assertNotFound();

        $invalidPartner = $this->makePartner('Other');
        $this->getJson('/api/business-partners/' . $invalidPartner->id . '/statement?year=2025')->assertNotFound();
    }

    private function makePartner(string $type): BusinessPartner
    {
        return BusinessPartner::create(['partner_type' => $type, 'name' => $type . ' Test']);
    }

    private function makeCurrency(string $code): Currency
    {
        return Currency::create(['code' => $code, 'name' => $code, 'symbol' => $code, 'decimal_places' => 2, 'is_base' => $code === 'AFN', 'is_active' => true]);
    }

    private function transaction(BusinessPartner $partner, Currency $currency, string $type, string $direction, float $amount, string $date): void
    {
        PartnerTransaction::create([
            'business_partner_id' => $partner->id,
            'currency_id' => $currency->id,
            'transaction_type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'transaction_date' => $date . ' 12:00:00',
            'description' => $type,
        ]);
    }
}
