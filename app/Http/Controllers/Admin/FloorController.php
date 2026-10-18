<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Floor;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            'map_data' => [
                'type' => 'FeatureCollection',
                'features' => [],
            ],
            'background_image' => null,
            'background_visible' => true,
            'background_opacity' => 0.5,
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

        $this->assertNavigationPathsWithinBuilding($building, $mapData);

        $backgroundImage = $validated['background_image'] ?? $floor->background_image;

        $floor->update([
            'map_data' => $mapData,
            'background_image' => $backgroundImage === '' ? null : $backgroundImage,
            'background_visible' => $request->boolean('background_visible', $floor->background_visible ?? true),
            'background_opacity' => $validated['background_opacity'] ?? $floor->background_opacity ?? 0.5,
        ]);

        $this->syncNavigationGraph($floor, $mapData);

        return redirect()->route('admin.buildings.floors.editor', [$building->id, $floor->id])->with('success', 'Floor map updated successfully.');
    }

    protected function assertNavigationPathsWithinBuilding(Building $building, array $mapData): void
    {
        $buildingGeojson = is_array($building->geojson) ? $building->geojson : null;
        if (! is_array($buildingGeojson) || empty($buildingGeojson['features'] ?? null)) {
            return;
        }

        $buildingPolygons = [];
        foreach ($buildingGeojson['features'] as $feature) {
            $geometry = $feature['geometry'] ?? null;
            if (! is_array($geometry)) {
                continue;
            }

            if (($geometry['type'] ?? null) === 'Polygon') {
                $buildingPolygons[] = $geometry['coordinates'] ?? [];

                continue;
            }

            if (($geometry['type'] ?? null) === 'MultiPolygon') {
                foreach ($geometry['coordinates'] ?? [] as $polygon) {
                    $buildingPolygons[] = $polygon;
                }
            }
        }

        if ($buildingPolygons === []) {
            return;
        }

        foreach ($mapData['features'] ?? [] as $feature) {
            $kind = $feature['properties']['kind'] ?? $feature['properties']['type'] ?? null;
            if ($kind !== 'navigation') {
                continue;
            }

            $geometry = $feature['geometry'] ?? null;
            if (! is_array($geometry) || ($geometry['type'] ?? null) !== 'LineString') {
                continue;
            }

            $coordinates = $geometry['coordinates'] ?? [];
            if (! is_array($coordinates) || count($coordinates) < 2) {
                throw ValidationException::withMessages([
                    'map_data' => ['Navigation paths must contain at least two points inside the building polygon.'],
                ]);
            }

            foreach ($coordinates as $point) {
                if (! is_array($point) || count($point) < 2) {
                    throw ValidationException::withMessages([
                        'map_data' => ['A navigation path contains invalid coordinates.'],
                    ]);
                }

                $longitude = (float) $point[0];
                $latitude = (float) $point[1];

                $inside = false;
                foreach ($buildingPolygons as $polygon) {
                    if ($this->pointInPolygon($longitude, $latitude, $polygon)) {
                        $inside = true;
                        break;
                    }
                }

                if (! $inside) {
                    throw ValidationException::withMessages([
                        'map_data' => ['Navigation paths must stay inside the selected building boundary.'],
                    ]);
                }
            }
        }
    }

    protected function pointInPolygon(float $longitude, float $latitude, array $polygon): bool
    {
        $points = $polygon[0] ?? [];
        if (! is_array($points) || $points === []) {
            return false;
        }

        $inside = false;
        $j = count($points) - 1;

        for ($i = 0; $i < count($points); $i++) {
            $xi = (float) ($points[$i][0] ?? 0);
            $yi = (float) ($points[$i][1] ?? 0);
            $xj = (float) ($points[$j][0] ?? 0);
            $yj = (float) ($points[$j][1] ?? 0);

            $intersect = (($yi > $latitude) !== ($yj > $latitude))
                && ($longitude < (($xj - $xi) * ($latitude - $yi) / ($yj - $yi + 0.00000001)) + $xi);

            if ($intersect) {
                $inside = ! $inside;
            }

            $j = $i;
        }

        return $inside;
    }

    protected function syncNavigationGraph(Floor $floor, array $mapData): void
    {
        $features = $mapData['features'] ?? [];
        $navigationFeatures = array_values(array_filter($features, fn ($feature) => ($feature['properties']['kind'] ?? null) === 'navigation' || ($feature['properties']['type'] ?? null) === 'navigation'));

        if ($navigationFeatures === []) {
            return;
        }

        $floor->navigationNodes()->delete();
        $nodeIndex = [];

        $ensureNode = function (float $x, float $y) use ($floor, &$nodeIndex) {
            $key = sprintf('%.4f:%.4f', round($x, 4), round($y, 4));

            if (isset($nodeIndex[$key])) {
                return $nodeIndex[$key];
            }

            $node = $floor->navigationNodes()->create([
                'type' => 'junction',
                'x' => (float) $x,
                'y' => (float) $y,
            ]);

            $nodeIndex[$key] = $node;

            return $node;
        };

        foreach ($navigationFeatures as $feature) {
            $geometry = $feature['geometry'] ?? null;
            if (! is_array($geometry) || ($geometry['type'] ?? null) !== 'LineString') {
                continue;
            }

            $coordinates = $geometry['coordinates'] ?? [];
            if (! is_array($coordinates)) {
                continue;
            }

            for ($index = 0; $index < count($coordinates) - 1; $index++) {
                $from = $coordinates[$index];
                $to = $coordinates[$index + 1];

                if (! is_array($from) || ! is_array($to) || count($from) < 2 || count($to) < 2) {
                    continue;
                }

                $fromNode = $ensureNode((float) $from[0], (float) $from[1]);
                $toNode = $ensureNode((float) $to[0], (float) $to[1]);

                $distance = hypot($toNode->x - $fromNode->x, $toNode->y - $fromNode->y);
                $fromNode->outgoingEdges()->firstOrCreate([
                    'to_node_id' => $toNode->id,
                ], [
                    'distance' => $distance,
                    'accessible' => true,
                ]);

                $toNode->outgoingEdges()->firstOrCreate([
                    'to_node_id' => $fromNode->id,
                ], [
                    'distance' => $distance,
                    'accessible' => true,
                ]);
            }
        }
    }

    public function destroy(Building $building, Floor $floor)
    {
        $floor->delete();

        return redirect()->route('admin.buildings.show', $building)->with('success', 'Floor removed.');
    }
}
