<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::with('user:id,name')
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Settings retrieved successfully.',
            'data' => $settings,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title_pa' => ['required', 'string', 'max:190'],
            'title_en' => ['required', 'string', 'max:190'],
            'phone1' => ['required', 'string', 'max:20'],
            'phone2' => ['nullable', 'string', 'max:20'],
            'address' => ['required', 'string'],
            'bill_text' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            $validated['image_path'] = $request->file('logo')
                ->store('logo', 'public');
        }

        unset($validated['logo']);

        $validated['user_id'] = $request->user()->id;

        $setting = Setting::create($validated);

        return response()->json([
            'message' => 'Setting created successfully.',
            'data' => $setting,
        ], 201);
    }

    public function show(Setting $setting)
    {
        return response()->json([
            'message' => 'Setting retrieved successfully.',
            'data' => $setting->load('user:id,name'),
        ]);
    }

    public function update(Request $request, Setting $setting)
    {
        $validated = $request->validate([
            'title_pa' => ['sometimes', 'required', 'string', 'max:190'],
            'title_en' => ['sometimes', 'required', 'string', 'max:190'],
            'phone1' => ['sometimes', 'required', 'string', 'max:20'],
            'phone2' => ['nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'required', 'string'],
            'bill_text' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            if ($setting->image_path) {
                Storage::disk('public')->delete($setting->image_path);
            }

            $validated['image_path'] = $request->file('logo')
                ->store('logo', 'public');
        }

        unset($validated['logo']);

        $setting->update($validated);

        return response()->json([
            'message' => 'Setting updated successfully.',
            'data' => $setting->fresh(),
        ]);
    }

    public function destroy(Setting $setting)
    {
        if ($setting->image_path) {
            Storage::disk('public')->delete($setting->image_path);
        }

        $setting->delete();

        return response()->json([
            'message' => 'Setting deleted successfully.',
        ]);
    }
}
