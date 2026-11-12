<?php

namespace Tests\Feature;

use App\Models\BusinessPartner;
use App\Models\Currency;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseSummaryReportTest extends TestCase
{
    use RefreshDatabase;

    private User $secretary;

    private BusinessPartner $supplier;

    private Warehouse $warehouse;

    /** @var array<string, Currency> */
    private array $currencies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->secretary = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);

        $this->supplier = BusinessPartner::create([
            'partner_type' => 'Supplier',
            'name' => 'Purchase Summary Test Supplier',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Purchase Summary Test Warehouse',
            'is_active' => true,
        ]);

        foreach ([
            'AFN' => ['Afghan Afghani', '؋'],
            'USD' => ['US Dollar', '$'],
            'PKR' => ['Pakistani Rupee', 'Rs'],
        ] as $code => [$name, $symbol]) {
            $this->currencies[$code] = Currency::create([
                'code' => $code,
                'name' => $name,
                'symbol' => $symbol,
                'decimal_places' => 2,
                'is_base' => $code === 'AFN',
                'is_active' => true,
            ]);
        }
    }

    public function test_it_aggregates_posted_purchases_separately_by_currency(): void
    {
        $this->createPurchase('AFN', '100.2500');
        $this->createPurchase('AFN', '25.7500');
        $this->createPurchase('USD', '12.5000');
        $this->createPurchase('PKR', '900.0000');
        $this->createPurchase('AFN', '50.0000', 'draft');
        $this->createPurchase('USD', '80.0000')->delete();
        $this->createPurchaseReturn('AFN', '40.0000');

        $response = $this->actingAs($this->secretary, 'sanctum')
            ->getJson('/api/reports/purchase-summary');

        $response
            ->assertOk()
            ->assertJsonPath('data.filters.from_date', null)
            ->assertJsonPath('data.filters.to_date', null)
            ->assertJsonPath('data.total_purchase_count', 4)
            ->assertJsonPath('data.purchase_returns_included', false)
            ->assertJsonPath(
                'data.purchase_returns_note',
                'Gross posted purchases only. Purchase returns and return settlements are excluded.'
            )
            ->assertJsonCount(3, 'data.gross_purchases_by_currency')
            ->assertJsonPath('data.gross_purchases_by_currency.0.currency_code', 'AFN')
            ->assertJsonPath(
                'data.gross_purchases_by_currency.0.currency_id',
                $this->currencies['AFN']->id
            )
            ->assertJsonPath('data.gross_purchases_by_currency.0.purchase_count', 2)
            ->assertJsonPath(
                'data.gross_purchases_by_currency.0.gross_purchase_amount',
                126
            )
            ->assertJsonPath('data.gross_purchases_by_currency.1.currency_code', 'PKR')
            ->assertJsonPath(
                'data.gross_purchases_by_currency.1.gross_purchase_amount',
                900
            )
            ->assertJsonPath('data.gross_purchases_by_currency.2.currency_code', 'USD')
            ->assertJsonPath(
                'data.gross_purchases_by_currency.2.gross_purchase_amount',
                12.5
            );
    }

    public function test_it_applies_inclusive_purchase_date_filters(): void
    {
        $this->createPurchase('AFN', '10.0000', 'posted', '2026-10-31');
        $this->createPurchase('AFN', '20.0000', 'posted', '2026-11-01');
        $this->createPurchase('USD', '30.0000', 'posted', '2026-11-12');
        $this->createPurchase('PKR', '40.0000', 'posted', '2026-11-13');

        $response = $this->actingAs($this->secretary, 'sanctum')
            ->getJson('/api/reports/purchase-summary?from_date=2026-11-01&to_date=2026-11-12');

        $response
            ->assertOk()
            ->assertJsonPath('data.total_purchase_count', 2)
            ->assertJsonPath(
                'data.gross_purchases_by_currency.0.gross_purchase_amount',
                20
            )
            ->assertJsonPath(
                'data.gross_purchases_by_currency.1.gross_purchase_amount',
                30
            );
    }

    public function test_it_returns_an_empty_group_list_when_no_posted_purchases_match(): void
    {
        $this->createPurchase('AFN', '10.0000', 'draft');

        $this->actingAs($this->secretary, 'sanctum')
            ->getJson('/api/reports/purchase-summary')
            ->assertOk()
            ->assertJsonPath('data.total_purchase_count', 0)
            ->assertJsonPath('data.gross_purchases_by_currency', []);
    }

    public function test_it_rejects_invalid_dates_and_reversed_ranges(): void
    {
        $this->actingAs($this->secretary, 'sanctum')
            ->getJson('/api/reports/purchase-summary?from_date=not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_date');

        $this->actingAs($this->secretary, 'sanctum')
            ->getJson('/api/reports/purchase-summary?from_date=2026-11-13&to_date=2026-11-12')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to_date');
    }

    public function test_it_requires_secretary_authentication(): void
    {
        $this->getJson('/api/reports/purchase-summary')
            ->assertUnauthorized();

        $customer = User::factory()->create([
            'role' => 'Customer',
            'is_active' => true,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/reports/purchase-summary')
            ->assertForbidden();
    }

    public function test_report_totals_match_posted_purchase_rows_and_exclude_returns(): void
    {
        $this->createPurchase('USD', '15.1250');
        $this->createPurchase('USD', '4.8750');
        $this->createPurchaseReturn('USD', '7.0000');

        $databaseTotal = Purchase::query()
            ->where('status', 'posted')
            ->where('currency_id', $this->currencies['USD']->id)
            ->sum('grand_total');

        $response = $this->actingAs($this->secretary, 'sanctum')
            ->getJson('/api/reports/purchase-summary');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.gross_purchases_by_currency.0.gross_purchase_amount',
                (int) $databaseTotal
            )
            ->assertJsonPath(
                'data.gross_purchases_by_currency.0.purchase_count',
                2
            );
    }

    private function createPurchase(
        string $currencyCode,
        string $amount,
        string $status = 'posted',
        string $purchaseDate = '2026-11-12'
    ): Purchase {
        static $purchaseNumber = 0;
        $purchaseNumber++;

        return Purchase::create([
            'purchase_number' => sprintf('SUMMARY-TEST-%04d', $purchaseNumber),
            'business_partner_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_id' => $this->currencies[$currencyCode]->id,
            'created_by' => $this->secretary->id,
            'purchase_date' => $purchaseDate,
            'exchange_rate_to_afn' => 1,
            'grand_total' => $amount,
            'grand_total_afn' => $amount,
            'status' => $status,
        ]);
    }

    private function createPurchaseReturn(string $currencyCode, string $amount): void
    {
        $purchase = Purchase::query()
            ->where('currency_id', $this->currencies[$currencyCode]->id)
            ->where('status', 'posted')
            ->first();

        if (! $purchase) {
            $purchase = $this->createPurchase($currencyCode, '0.0000');
        }

        DB::table('purchase_returns')->insert([
            'return_number' => 'SUMMARY-RETURN-'.strtoupper($currencyCode),
            'purchase_id' => $purchase->id,
            'business_partner_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_id' => $this->currencies[$currencyCode]->id,
            'created_by' => $this->secretary->id,
            'return_date' => '2026-11-12',
            'exchange_rate_to_afn' => 1,
            'subtotal' => $amount,
            'discount_amount' => 0,
            'grand_total' => $amount,
            'grand_total_afn' => $amount,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
