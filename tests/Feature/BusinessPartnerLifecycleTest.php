<?php

namespace Tests\Feature;

use App\Models\BusinessPartner;
use App\Models\Currency;
use App\Models\PartnerTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessPartnerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_status_updates_the_partner_and_linked_user(): void
    {
        $secretary = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);
        $this->actingAs($secretary, 'sanctum');

        foreach (['Customer', 'Supplier'] as $type) {
            $partner = BusinessPartner::create([
                'partner_type' => $type,
                'name' => $type . ' Lifecycle Test',
            ]);
            $partnerUser = User::factory()->create([
                'role' => $type,
                'is_active' => true,
                'business_partner_id' => $partner->id,
            ]);
            $partnerUser->createToken('lifecycle-test');

            $this->patchJson("/api/business-partners/{$partner->id}", [
                'is_active' => false,
            ])->assertOk()->assertJsonPath('data.is_active', false);

            $this->assertFalse($partner->fresh()->is_active);
            $this->assertFalse($partnerUser->fresh()->is_active);
            $this->assertSame(0, $partnerUser->tokens()->count());

            $this->patchJson("/api/business-partners/{$partner->id}", [
                'is_active' => true,
            ])->assertOk()->assertJsonPath('data.is_active', true);

            $this->assertTrue($partner->fresh()->is_active);
            $this->assertTrue($partnerUser->fresh()->is_active);
        }
    }

    public function test_delete_soft_deletes_partner_and_preserves_history_and_linked_user(): void
    {
        $secretary = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);
        $partner = BusinessPartner::create([
            'partner_type' => 'Customer',
            'name' => 'Archived Customer',
        ]);
        $partnerUser = User::factory()->create([
            'role' => 'Customer',
            'is_active' => true,
            'business_partner_id' => $partner->id,
        ]);
        $partnerUser->createToken('lifecycle-test');
        $currency = Currency::create([
            'code' => 'AFN',
            'name' => 'AFN',
            'symbol' => 'AFN',
            'decimal_places' => 2,
            'is_base' => true,
            'is_active' => true,
        ]);
        $transaction = PartnerTransaction::create([
            'business_partner_id' => $partner->id,
            'currency_id' => $currency->id,
            'transaction_type' => 'sale',
            'direction' => 'debit',
            'amount' => 100,
            'transaction_date' => '2026-01-15 12:00:00',
            'description' => 'Historical sale',
        ]);

        $this->actingAs($secretary, 'sanctum')
            ->deleteJson("/api/business-partners/{$partner->id}")
            ->assertOk();

        $this->assertSoftDeleted('business_partners', ['id' => $partner->id]);
        $this->assertDatabaseHas('business_partners', [
            'id' => $partner->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('partner_transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('users', [
            'id' => $partnerUser->id,
            'business_partner_id' => $partner->id,
            'is_active' => false,
        ]);
        $this->assertSame(0, $partnerUser->tokens()->count());
    }
}
