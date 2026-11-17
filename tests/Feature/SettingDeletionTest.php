<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_secretary_can_delete_a_setting_and_its_uploaded_logo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);
        $this->actingAs($user, 'sanctum');

        Storage::disk('public')->put('logo/company.png', 'logo');
        $setting = Setting::create([
            'user_id' => $user->id,
            'title_pa' => 'شرکت',
            'title_en' => 'Company',
            'phone1' => '123456789',
            'address' => 'Address',
            'image_path' => 'logo/company.png',
        ]);

        $this->deleteJson("/api/settings/{$setting->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Setting deleted successfully.');

        $this->assertDatabaseMissing('settings', ['id' => $setting->id]);
        Storage::disk('public')->assertMissing('logo/company.png');
    }

    public function test_only_secretaries_can_delete_settings(): void
    {
        $secretary = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);
        $setting = Setting::create([
            'user_id' => $secretary->id,
            'title_pa' => 'شرکت',
            'title_en' => 'Company',
            'phone1' => '123456789',
            'address' => 'Address',
        ]);
        $this->actingAs(User::factory()->create([
            'role' => 'Customer',
            'is_active' => true,
        ]), 'sanctum')
            ->deleteJson("/api/settings/{$setting->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('settings', ['id' => $setting->id]);
    }
}