<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class BuildingController extends Controller
{
    public function index()
    {
        $buildings = Building::with('floors')->orderBy('name')->get();

        return Inertia::render('Admin/Buildings/Index', [
            'buildings' => $buildings,
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Buildings/Create', [
            'building' => null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'geojson' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        Building::create($validated);

        return redirect()->route('admin.buildings.index')->with('success', 'Building created successfully.');
    }

    public function show(Building $building)
    {
        $building->load('floors');

        return Inertia::render('Admin/Buildings/Show', [
            'building' => $building,
        ]);
    }

    public function edit(Building $building)
    {
        return Inertia::render('Admin/Buildings/Edit', [
            'building' => $building,
        ]);
    }

    public function update(Request $request, Building $building)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'geojson' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $building->update($validated);

        return redirect()->route('admin.buildings.show', $building)->with('success', 'Building updated successfully.');
    }

    public function destroy(Building $building)
    {
        $building->delete();

        return redirect()->route('admin.buildings.index')->with('success', 'Building removed.');
    }

    public function toggleStatus(Building $building)
    {
        $building->update([
            'is_active' => ! $building->is_active,
        ]);

        return back()->with('success', 'Building status updated.');
    }
}
