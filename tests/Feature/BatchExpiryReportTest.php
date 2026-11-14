<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchExpiryReportTest extends TestCase
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

    public function test_expiry_report_accepts_numeric_string_day_values(): void
    {
        foreach (['30' => 30, '7' => 7] as $queryValue => $expectedDays) {
            $this->getJson('/api/reports/batch-expiry?days=' . $queryValue)
                ->assertOk()
                ->assertJsonPath('filters.days', $expectedDays);
        }
    }

    public function test_expiry_report_defaults_to_thirty_days(): void
    {
        $this->getJson('/api/reports/batch-expiry')
            ->assertOk()
            ->assertJsonPath('filters.days', 30);
    }

    public function test_expiry_report_rejects_invalid_day_values(): void
    {
        foreach (['0', '366', 'not-a-number'] as $days) {
            $this->getJson('/api/reports/batch-expiry?days=' . $days)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('days');
        }
    }
}
