<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Floor;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FloorController extends Controller
{
    public function index(Building $building)
    {
        $building->load('floors');

        return Inertia::render('Admin/Buildings/Show', [
            'building' => $building,
        ]);
    }

    public function create(Building $building)
    {
        return Inertia::render('Admin/Floors/Create', [
            'building' => $building,
            'floor' => null,
        ]);
    }

    public function store(Request $request, Building $building)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'level' => ['required', 'integer', 'min:0'],
        ]);

        $building->floors()->create([
            'name' => $validated['name'],
            'level' => $validated['level'],
        ]);

        return redirect()->route('admin.buildings.show', $building)->with('success', 'Floor created successfully.');
    }

    public function edit(Building $building, Floor $floor)
    {
        return Inertia::render('Admin/Floors/Edit', [
            'building' => $building,
            'floor' => $floor,
        ]);
    }

    public function editor(Building $building, Floor $floor)
    {
        return Inertia::render('Admin/Floors/Editor', [
            'building' => $building,
            'floor' => $floor,
            'mapData' => $floor->map_data ?? [
                'type' => 'FeatureCollection',
                'features' => [],
            ],
        ]);
    }

    public function update(Request $request, Building $building, Floor $floor)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'level' => ['required', 'integer', 'min:0'],
        ]);

        $floor->update([
            'name' => $validated['name'],
            'level' => $validated['level'],
        ]);

        return redirect()->route('admin.buildings.show', $building)->with('success', 'Floor updated successfully.');
    }

    public function saveMapData(Request $request, Building $building, Floor $floor)
    {
        $validated = $request->validate([
            'map_data' => ['nullable', 'array'],
            'background_image' => ['nullable', 'string'],
            'background_visible' => ['nullable', 'boolean'],
            'background_opacity' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $mapData = $validated['map_data'] ?? [
            'type' => 'FeatureCollection',
            'features' => [],
        ];

        $floor->update([
            'map_data' => $mapData,
            'background_image' => $validated['background_image'] ?? $floor->background_image,
            'background_visible' => $request->boolean('background_visible', $floor->background_visible ?? true),
            'background_opacity' => $validated['background_opacity'] ?? $floor->background_opacity ?? 0.5,
        ]);

        $this->syncNavigationGraph($floor, $mapData);

        return redirect()->route('admin.buildings.floors.editor', [$building->id, $floor->id])->with('success', 'Floor map updated successfully.');
    }

    protected function syncNavigationGraph(Floor $floor, array $mapData): void
    {
        $features = $mapData['features'] ?? [];
        $navigationFeatures = array_values(array_filter($features, fn ($feature) => ($feature['properties']['kind'] ?? null) === 'navigation' || ($feature['properties']['type'] ?? null) === 'navigation'));

        if ($navigationFeatures === []) {
            return;
        }

        $floor->navigationNodes()->delete();

        $points = [];
        foreach ($navigationFeatures as $feature) {
            $geometry = $feature['geometry'] ?? null;
            if (! is_array($geometry) || ($geometry['type'] ?? null) !== 'LineString') {
                continue;
            }

            foreach ($geometry['coordinates'] as $index => [$x, $y]) {
                $points[] = ['x' => (float) $x, 'y' => (float) $y, 'type' => $index === 0 ? 'entry' : 'junction'];
            }
        }

        $createdNodes = [];
        foreach ($points as $point) {
            $node = $floor->navigationNodes()->create([
                'type' => $point['type'],
                'x' => $point['x'],
                'y' => $point['y'],
            ]);

            $createdNodes[] = $node;
        }

        foreach (array_keys($createdNodes) as $index) {
            $current = $createdNodes[$index] ?? null;
            $next = $createdNodes[$index + 1] ?? null;

            if (! $current || ! $next) {
                continue;
            }

            $distance = hypot($next->x - $current->x, $next->y - $current->y);
            $current->outgoingEdges()->create([
                'to_node_id' => $next->id,
                'distance' => $distance,
                'accessible' => true,
            ]);
        }
    }

    public function destroy(Building $building, Floor $floor)
    {
        $floor->delete();

        return redirect()->route('admin.buildings.show', $building)->with('success', 'Floor removed.');
    }
}
