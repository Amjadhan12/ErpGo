<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Document::with('uploader:id,name');

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('original_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $documents = $query
            ->latest()
            ->paginate(min((int) $request->input('per_page', 20), 100));

        return response()->json([
            'message' => 'Documents retrieved successfully.',
            'data' => $documents,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:100'],
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,doc,docx,xlsx',
                'max:10240',
            ],
        ]);

        $file = $request->file('file');

        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();

        $path = $file->storeAs('documents', $filename, 'local');

        if (!$path) {
            throw ValidationException::withMessages([
                'file' => 'The file could not be uploaded.',
            ]);
        }

        $document = Document::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'] ?? null,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'data' => $document->load('uploader:id,name'),
        ], 201);
    }

    public function show(Document $document): JsonResponse
    {
        return response()->json([
            'message' => 'Document retrieved successfully.',
            'data' => $document->load('uploader:id,name'),
        ]);
    }

    public function download(Document $document)
    {
        if (!Storage::disk('local')->exists($document->file_path)) {
            return response()->json([
                'message' => 'Document file not found.',
            ], 404);
        }

        return Storage::disk('local')->download(
            $document->file_path,
            $document->original_name
        );
    }

    public function destroy(Document $document): JsonResponse
    {
        Storage::disk('local')->delete($document->file_path);

        $document->delete();

        return response()->json([
            'message' => 'Document deleted successfully.',
        ]);
    }
}