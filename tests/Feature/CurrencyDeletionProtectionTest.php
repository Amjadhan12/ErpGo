<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyDeletionProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_currency_deletion_is_rejected_and_the_currency_is_preserved(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]), 'sanctum');

        $currency = Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_base' => false,
            'is_active' => true,
        ]);

        $this->deleteJson("/api/currencies/{$currency->id}")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Currencies cannot be deleted because financial and inventory history may reference them. Deactivate the currency instead.'
            );

        $this->assertDatabaseHas('currencies', [
            'id' => $currency->id,
            'is_active' => true,
        ]);
    }
}