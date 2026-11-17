<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseCategoryReactivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_expense_category_can_be_reactivated_and_persisted(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]), 'sanctum');
        $category = ExpenseCategory::create([
            'name' => 'Reactivation test category',
            'is_active' => false,
        ]);

        $this->patchJson("/api/expense-categories/{$category->id}", [
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('expense_categories', [
            'id' => $category->id,
            'is_active' => true,
        ]);
    }

    public function test_only_secretaries_can_reactivate_expense_categories(): void
    {
        $category = ExpenseCategory::create([
            'name' => 'Protected inactive category',
            'is_active' => false,
        ]);
        $this->actingAs(User::factory()->create([
            'role' => 'Customer',
            'is_active' => true,
        ]), 'sanctum')
            ->patchJson("/api/expense-categories/{$category->id}", [
                'is_active' => true,
            ])
            ->assertForbidden();

        $this->assertFalse($category->fresh()->is_active);
    }
}
