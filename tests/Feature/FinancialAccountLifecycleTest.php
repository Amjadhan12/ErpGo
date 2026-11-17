<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]), 'sanctum');
    }

    public function test_financial_account_can_be_deactivated_and_reactivated_without_losing_history(): void
    {
        $currency = Currency::create([
            'code' => 'AFN',
            'name' => 'Afghani',
            'symbol' => '؋',
            'decimal_places' => 2,
            'is_base' => true,
            'is_active' => true,
        ]);
        $account = FinancialAccount::create([
            'currency_id' => $currency->id,
            'name' => 'Lifecycle Account',
            'account_type' => 'cash',
            'holder_type' => 'company',
            'is_active' => true,
        ]);
        FinancialTransaction::create([
            'financial_account_id' => $account->id,
            'transaction_type' => 'opening_balance',
            'direction' => 'in',
            'amount' => 100,
            'transaction_date' => now(),
        ]);

        $this->patchJson("/api/financial-accounts/{$account->id}/status", [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->getJson('/api/financial-accounts/balances')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/financial-accounts/balances?include_inactive=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false);
        $this->assertDatabaseHas('financial_transactions', [
            'financial_account_id' => $account->id,
            'amount' => 100,
        ]);

        $this->patchJson("/api/financial-accounts/{$account->id}/status", [
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_only_secretaries_can_change_financial_account_status(): void
    {
        $account = FinancialAccount::create([
            'currency_id' => Currency::create([
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'decimal_places' => 2,
                'is_base' => false,
                'is_active' => true,
            ])->id,
            'name' => 'Protected Account',
            'account_type' => 'cash',
            'holder_type' => 'company',
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create([
            'role' => 'Customer',
            'is_active' => true,
        ]), 'sanctum')
            ->patchJson("/api/financial-accounts/{$account->id}/status", [
                'is_active' => false,
            ])
            ->assertForbidden();

        $this->assertTrue($account->fresh()->is_active);
    }
}
