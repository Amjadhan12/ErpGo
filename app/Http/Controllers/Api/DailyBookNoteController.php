<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyBookNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyBookNoteController extends Controller
{
    /**
     * Display all daily book notes.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $notes = DailyBookNote::query()
            ->with('creator:id,name')
            ->when($validated['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('details', 'like', "%{$search}%");
                });
            })
            ->when($validated['from_date'] ?? null, function ($query, $date) {
                $query->whereDate('note_date', '>=', $date);
            })
            ->when($validated['to_date'] ?? null, function ($query, $date) {
                $query->whereDate('note_date', '<=', $date);
            })
            ->orderByDesc('note_date')
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'message' => 'Daily book notes retrieved successfully.',
            'data' => $notes,
        ]);
    }

    /**
     * Store a new note.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'details' => ['required', 'string'],
            'note_date' => ['required', 'date'],
        ]);

        $note = DailyBookNote::create([
            'title' => $validated['title'] ?? null,
            'details' => $validated['details'],
            'note_date' => $validated['note_date'],
            'created_by' => auth()->id(),
        ]);

        $note->load('creator:id,name');

        return response()->json([
            'message' => 'Daily book note created successfully.',
            'data' => $note,
        ], 201);
    }

    /**
     * Display a single note.
     */
    public function show(DailyBookNote $dailyBookNote): JsonResponse
    {
        $dailyBookNote->load('creator:id,name');

        return response()->json([
            'message' => 'Daily book note retrieved successfully.',
            'data' => $dailyBookNote,
        ]);
    }

    /**
     * Update an existing note.
     */
    public function update(
        Request $request,
        DailyBookNote $dailyBookNote
    ): JsonResponse {
        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'details' => ['sometimes', 'required', 'string'],
            'note_date' => ['sometimes', 'required', 'date'],
        ]);

        $dailyBookNote->update($validated);

        $dailyBookNote->load('creator:id,name');

        return response()->json([
            'message' => 'Daily book note updated successfully.',
            'data' => $dailyBookNote,
        ]);
    }

    /**
     * Delete a note.
     */
    public function destroy(DailyBookNote $dailyBookNote): JsonResponse
    {
        $dailyBookNote->delete();

        return response()->json([
            'message' => 'Daily book note deleted successfully.',
        ]);
    }
}
