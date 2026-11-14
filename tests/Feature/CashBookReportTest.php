<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\OpeningBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashBookReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_book_requires_secretary_access(): void
    {
        $this->getJson('/api/reports/cash-book')->assertUnauthorized();

        $customer = User::factory()->create([
            'role' => 'Customer',
            'is_active' => true,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/reports/cash-book')
            ->assertForbidden();
    }

    public function test_cash_book_summarizes_date_filtered_accounts_by_currency(): void
    {
        $secretary = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);

        $afn = $this->makeAccount('AFN', 'Cash AFN');
        $usd = $this->makeAccount('USD', 'Bank USD');

        OpeningBalance::create([
            'financial_account_id' => $afn->id,
            'amount' => 100,
            'opening_date' => '2025-01-01',
        ]);
        OpeningBalance::create([
            'financial_account_id' => $usd->id,
            'amount' => 50,
            'opening_date' => '2025-01-01',
        ]);

        $this->makeTransaction($afn, 'in', 20, '2025-01-05 10:00:00');
        $this->makeTransaction($afn, 'in', 30, '2025-01-10 10:00:00');
        $this->makeTransaction($afn, 'out', 5, '2025-01-11 10:00:00');
        $this->makeTransaction($usd, 'out', 2, '2025-01-10 12:00:00');

        $response = $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/reports/cash-book?from_date=2025-01-10&to_date=2025-01-10&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.summary_by_currency.0.currency', 'AFN')
            ->assertJsonPath('data.summary_by_currency.0.opening_balance', '120.0000')
            ->assertJsonPath('data.summary_by_currency.0.total_in', '30.0000')
            ->assertJsonPath('data.summary_by_currency.0.total_out', '0.0000')
            ->assertJsonPath('data.summary_by_currency.0.closing_balance', '150.0000')
            ->assertJsonPath('data.summary_by_currency.1.currency', 'USD')
            ->assertJsonPath('data.summary_by_currency.1.opening_balance', '50.0000')
            ->assertJsonPath('data.summary_by_currency.1.total_in', '0.0000')
            ->assertJsonPath('data.summary_by_currency.1.total_out', '2.0000')
            ->assertJsonPath('data.summary_by_currency.1.closing_balance', '48.0000')
            ->assertJsonPath('data.transactions.total', 2)
            ->assertJsonPath('data.transactions.last_page', 2)
            ->assertJsonPath('data.transactions.data.0.account_name', 'Cash AFN')
            ->assertJsonPath('data.transactions.data.0.running_balance', '150.0000');

        $response->assertJsonPath('data.account_summaries.0.currency', 'AFN');

        $this->getJson('/api/reports/cash-book?financial_account_id=' . $usd->id . '&currency_id=' . $usd->currency_id)
            ->assertOk()
            ->assertJsonCount(1, 'data.account_summaries')
            ->assertJsonPath('data.account_summaries.0.account_name', 'Bank USD')
            ->assertJsonPath('data.transactions.total', 1);
    }

    private function makeAccount(string $currencyCode, string $name): FinancialAccount
    {
        $currency = Currency::create([
            'code' => $currencyCode,
            'name' => $currencyCode,
            'symbol' => $currencyCode,
            'decimal_places' => 2,
            'is_base' => $currencyCode === 'AFN',
            'is_active' => true,
        ]);

        return FinancialAccount::create([
            'currency_id' => $currency->id,
            'name' => $name,
            'account_type' => str_starts_with($name, 'Bank') ? 'bank' : 'cash',
            'holder_type' => 'company',
            'is_active' => true,
        ]);
    }

    private function makeTransaction(
        FinancialAccount $account,
        string $direction,
        float $amount,
        string $date,
    ): void {
        FinancialTransaction::create([
            'financial_account_id' => $account->id,
            'transaction_type' => 'test',
            'direction' => $direction,
            'amount' => $amount,
            'transaction_date' => $date,
            'description' => null,
        ]);
    }
}
