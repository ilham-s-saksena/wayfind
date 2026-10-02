<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Place;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MapController extends Controller
{
    public function index(Request $request)
    {
        $selectedBuildingId = $request->query('building');
        $selectedFloorId = $request->query('floor');
        $search = trim((string) $request->query('q', ''));

        $buildings = Building::with('floors')->active()->orderBy('name')->get();

        $building = $selectedBuildingId
            ? $buildings->firstWhere('id', $selectedBuildingId) ?? $buildings->first()
            : $buildings->first();

        if (! $building) {
            return Inertia::render('Map/Index', [
                'buildings' => [],
                'floors' => [],
                'places' => [],
                'selectedBuildingId' => null,
                'selectedFloorId' => null,
                'search' => $search,
                'mapGeojson' => $this->emptyGeoJson(),
            ]);
        }

        $floors = $building->floors()->orderBy('level')->get();

        $floor = $selectedFloorId
            ? $floors->firstWhere('id', $selectedFloorId) ?? $floors->first()
            : $floors->first();

        $placesQuery = Place::query()
            ->with('floor.building')
            ->whereHas('floor', fn ($query) => $query->where('building_id', $building->id))
            ->active()
            ->searchable();

        if ($floor) {
            $placesQuery->where('floor_id', $floor->id);
        }

        if ($search !== '') {
            $placesQuery->where(function ($query) use ($search) {
                $query->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('slug', 'ILIKE', "%{$search}%")
                    ->orWhere('description', 'ILIKE', "%{$search}%");
            });
        }

        $places = $placesQuery->orderBy('name')->get();

        $selectedPlace = $places->first();

        return Inertia::render('Map/Index', [
            'buildings' => $buildings,
            'floors' => $floors,
            'places' => $places,
            'selectedBuildingId' => $building->id,
            'selectedFloorId' => $floor?->id,
            'selectedPlace' => $selectedPlace,
            'search' => $search,
            'mapGeojson' => $this->buildFloorGeoJson($floor, $places, $building),
        ]);
    }

    protected function emptyGeoJson(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [],
        ];
    }

    protected function buildFloorGeoJson(?Floor $floor, $places, ?Building $building = null): array
    {
        $features = [];

        if ($building && is_array($building->geojson) && ($building->geojson['features'] ?? false)) {
            foreach ($building->geojson['features'] as $feature) {
                $features[] = $feature;
            }
        }

        if ($floor) {
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'kind' => 'floor',
                    'name' => $floor->name,
                ],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [0, 0],
                        [1200, 0],
                        [1200, 800],
                        [0, 800],
                        [0, 0],
                    ]],
                ],
            ];
        }

        foreach ($places as $index => $place) {
            $x = 140 + ($index % 4) * 230;
            $y = 150 + intdiv($index, 4) * 170;

            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'id' => $place->id,
                    'name' => $place->name,
                    'type' => $place->type,
                    'slug' => $place->slug,
                    'description' => $place->description,
                    'isSelected' => false,
                ],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$x, $y],
                ],
            ];
        }

        if ($floor && is_array($floor->map_data) && ($floor->map_data['features'] ?? false)) {
            foreach ($floor->map_data['features'] as $feature) {
                $features[] = $feature;
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }
}
