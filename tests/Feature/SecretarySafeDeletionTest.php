<?php

namespace Tests\Feature;

use App\Models\DailyBookNote;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecretarySafeDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_secretary_can_delete_a_daily_note_and_document_file(): void
    {
        Storage::fake('local');
        $secretary = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);
        $this->actingAs($secretary, 'sanctum');

        $note = new DailyBookNote([
            'title' => 'Meeting note',
            'details' => 'Meeting details',
            'note_date' => '2026-11-17',
        ]);
        $note->created_by = $secretary->id;
        $note->save();

        Storage::disk('local')->put('documents/company.pdf', 'document');
        $document = Document::create([
            'title' => 'Company document',
            'original_name' => 'company.pdf',
            'file_path' => 'documents/company.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 8,
            'uploaded_by' => $secretary->id,
        ]);

        $this->deleteJson("/api/daily-book-notes/{$note->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Daily book note deleted successfully.');
        $this->deleteJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Document deleted successfully.');

        $this->assertDatabaseMissing('daily_book_notes', ['id' => $note->id]);
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing('documents/company.pdf');
    }

    public function test_only_secretaries_can_delete_daily_notes_and_documents(): void
    {
        $secretary = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);
        $note = new DailyBookNote([
            'title' => 'Protected note',
            'details' => 'Protected details',
            'note_date' => '2026-11-17',
        ]);
        $note->created_by = $secretary->id;
        $note->save();
        $document = Document::create([
            'title' => 'Protected document',
            'original_name' => 'protected.pdf',
            'file_path' => 'documents/protected.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 0,
            'uploaded_by' => $secretary->id,
        ]);
        $this->actingAs(User::factory()->create([
            'role' => 'Customer',
            'is_active' => true,
        ]), 'sanctum');

        $this->deleteJson("/api/daily-book-notes/{$note->id}")->assertForbidden();
        $this->deleteJson("/api/documents/{$document->id}")->assertForbidden();

        $this->assertDatabaseHas('daily_book_notes', ['id' => $note->id]);
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }
}