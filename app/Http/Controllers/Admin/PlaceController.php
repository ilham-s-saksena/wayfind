<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Floor;
use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PlaceController extends Controller
{
    public function index(Floor $floor)
    {
        $floor->load(['building', 'places']);

        return Inertia::render('Admin/Places/Index', [
            'floor' => $floor,
            'places' => $floor->places,
        ]);
    }

    public function create(Floor $floor)
    {
        return Inertia::render('Admin/Places/Create', [
            'floor' => $floor,
            'place' => null,
        ]);
    }

    public function store(Request $request, Floor $floor)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'is_searchable' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_searchable'] = $request->boolean('is_searchable', true);
        $validated['is_active'] = $request->boolean('is_active', true);

        $floor->places()->create($validated);

        return redirect()->route('admin.floors.places.index', $floor)->with('success', 'Place created successfully.');
    }

    public function edit(Floor $floor, Place $place)
    {
        return Inertia::render('Admin/Places/Edit', [
            'floor' => $floor,
            'place' => $place,
        ]);
    }

    public function update(Request $request, Floor $floor, Place $place)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'is_searchable' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_searchable'] = $request->boolean('is_searchable', true);
        $validated['is_active'] = $request->boolean('is_active', true);

        $place->update($validated);

        return redirect()->route('admin.floors.places.index', $floor)->with('success', 'Place updated successfully.');
    }

    public function destroy(Floor $floor, Place $place)
    {
        $place->delete();

        return redirect()->route('admin.floors.places.index', $floor)->with('success', 'Place removed.');
    }
}
