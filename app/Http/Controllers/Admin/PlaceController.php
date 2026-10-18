<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        $floor->load('building');

        return Inertia::render('Admin/Places/Create', [
            'building' => $floor->building,
            'floor' => $floor,
            'place' => null,
        ]);
    }

    public function store(Request $request, Floor $floor)
    {
        $floor->load('building');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'geojson' => ['required', 'array'],
            'is_searchable' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $building = $floor->building;
        if (! $building) {
            throw ValidationException::withMessages([
                'building_id' => ['The selected floor does not belong to a valid building.'],
            ]);
        }

        $geojson = $this->normalizeGeoJson($validated['geojson'] ?? null);
        // $this->assertPlaceIsWithinBuilding($building, $geojson);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_searchable'] = $request->boolean('is_searchable', true);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['geojson'] = $geojson;

        $floor->places()->create($validated);

        return redirect()->route('admin.floors.places.index', $floor)->with('success', 'Place created successfully.');
    }

    public function edit(Floor $floor, Place $place)
    {
        $floor->load('building');

        return Inertia::render('Admin/Places/Edit', [
            'building' => $floor->building,
            'floor' => $floor,
            'place' => $place,
        ]);
    }

    public function update(Request $request, Floor $floor, Place $place)
    {
        $floor->load('building');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'geojson' => ['required', 'array'],
            'is_searchable' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $building = $floor->building;
        if (! $building) {
            throw ValidationException::withMessages([
                'building_id' => ['The selected floor does not belong to a valid building.'],
            ]);
        }

        $geojson = $this->normalizeGeoJson($validated['geojson'] ?? null);
        // $this->assertPlaceIsWithinBuilding($building, $geojson);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_searchable'] = $request->boolean('is_searchable', true);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['geojson'] = $geojson;

        $place->update($validated);

        return redirect()->route('admin.floors.places.index', $floor)->with('success', 'Place updated successfully.');
    }

    public function destroy(Floor $floor, Place $place)
    {
        $place->delete();

        return redirect()->route('admin.floors.places.index', $floor)->with('success', 'Place removed.');
    }

    protected function normalizeGeoJson(mixed $geojson): array
    {
        if (! is_array($geojson)) {
            throw ValidationException::withMessages([
                'geojson' => ['Please draw the place on the map before saving.'],
            ]);
        }

        $features = $geojson['features'] ?? [];
        if (! is_array($features) || $features === []) {
            throw ValidationException::withMessages([
                'geojson' => ['Please draw a valid place geometry.'],
            ]);
        }

        return [
            'type' => 'FeatureCollection',
            'features' => array_values(array_filter($features, fn ($feature) => is_array($feature))),
        ];
    }

    protected function assertPlaceIsWithinBuilding(Building $building, array $geojson): void
    {
        $buildingGeojson = is_array($building->geojson) ? $building->geojson : null;
        if (! is_array($buildingGeojson) || empty($buildingGeojson['features'] ?? null)) {
            throw ValidationException::withMessages([
                'geojson' => ['The selected building does not have a valid geometry. The place cannot be saved until the building footprint is defined.'],
            ]);
        }

        $buildingPolygons = [];
        foreach ($buildingGeojson['features'] as $feature) {
            $geometry = $feature['geometry'] ?? null;
            if (! is_array($geometry)) {
                continue;
            }

            $this->collectPolygonCoordinates($geometry, $buildingPolygons);
        }

        if ($buildingPolygons === []) {
            throw ValidationException::withMessages([
                'geojson' => ['The selected building geometry is invalid.'],
            ]);
        }

        foreach ($geojson['features'] as $feature) {
            if (! $this->featureIsInsideBuilding($feature, $buildingPolygons)) {
                throw ValidationException::withMessages([
                    'geojson' => ['The place must stay inside the selected building geometry.'],
                ]);
            }
        }
    }

    protected function featureIsInsideBuilding(array $feature, array $buildingPolygons): bool
    {
        $geometry = $feature['geometry'] ?? null;
        if (! is_array($geometry)) {
            return false;
        }

        return match ($geometry['type'] ?? null) {
            'Point' => $this->pointIsInsideAnyPolygon($geometry['coordinates'] ?? [], $buildingPolygons),
            'Polygon' => $this->polygonIsInsideAnyPolygon($geometry['coordinates'] ?? [], $buildingPolygons),
            'MultiPolygon' => $this->multiPolygonIsInsideAnyPolygon($geometry['coordinates'] ?? [], $buildingPolygons),
            'MultiPoint' => $this->multiPointIsInsideAnyPolygon($geometry['coordinates'] ?? [], $buildingPolygons),
            default => false,
        };
    }

    protected function collectPolygonCoordinates(array $geometry, array &$polygons): void
    {
        if (($geometry['type'] ?? null) === 'Polygon') {
            $polygons[] = $geometry['coordinates'] ?? [];

            return;
        }

        if (($geometry['type'] ?? null) === 'MultiPolygon') {
            foreach ($geometry['coordinates'] ?? [] as $polygonCoords) {
                $polygons[] = $polygonCoords;
            }
        }
    }

    protected function pointIsInsideAnyPolygon(array $point, array $buildingPolygons): bool
    {
        if (count($point) < 2) {
            return false;
        }

        $longitude = (float) ($point[0] ?? 0);
        $latitude = (float) ($point[1] ?? 0);

        foreach ($buildingPolygons as $polygon) {
            if ($this->pointInPolygon($longitude, $latitude, $polygon)) {
                return true;
            }
        }

        return false;
    }

    protected function polygonIsInsideAnyPolygon(array $rings, array $buildingPolygons): bool
    {
        foreach ($rings as $ring) {
            if (! is_array($ring)) {
                return false;
            }

            foreach ($ring as $coordinates) {
                if (! is_array($coordinates) || count($coordinates) < 2) {
                    continue;
                }

                if (! $this->pointIsInsideAnyPolygon($coordinates, $buildingPolygons)) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function multiPolygonIsInsideAnyPolygon(array $polygons, array $buildingPolygons): bool
    {
        foreach ($polygons as $polygonSet) {
            if (! $this->polygonIsInsideAnyPolygon($polygonSet, $buildingPolygons)) {
                return false;
            }
        }

        return true;
    }

    protected function multiPointIsInsideAnyPolygon(array $points, array $buildingPolygons): bool
    {
        foreach ($points as $point) {
            if (! $this->pointIsInsideAnyPolygon($point, $buildingPolygons)) {
                return false;
            }
        }

        return true;
    }

    protected function pointInPolygon(float $longitude, float $latitude, array $polygon): bool
    {
        $inside = false;
        $points = $polygon[0] ?? [];

        if (! is_array($points) || $points === []) {
            return false;
        }

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
}
