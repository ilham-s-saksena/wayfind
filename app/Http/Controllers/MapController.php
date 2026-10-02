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
            'selectedBuilding' => $building,
            'selectedFloor' => $floor,
            'selectedPlace' => $selectedPlace,
            'search' => $search,
            'buildingGeojson' => $this->buildBuildingsGeoJson($buildings),
            'floorGeojson' => $this->buildFloorGeoJson($floor, $places, $building),
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

    protected function buildBuildingsGeoJson($buildings): array
    {
        $features = [];

        foreach ($buildings as $building) {
            if (! is_array($building->geojson) || empty($building->geojson['features'] ?? null)) {
                continue;
            }

            foreach ($building->geojson['features'] as $feature) {
                $features[] = [
                    ...$feature,
                    'properties' => [
                        ...(isset($feature['properties']) && is_array($feature['properties']) ? $feature['properties'] : []),
                        'kind' => 'building',
                        'buildingId' => $building->id,
                        'buildingName' => $building->name,
                    ],
                ];
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    protected function buildFloorGeoJson(?Floor $floor, $places, ?Building $building = null): array
    {
        $features = [];

        if ($building && is_array($building->geojson) && ($building->geojson['features'] ?? false)) {
            foreach ($building->geojson['features'] as $feature) {
                $features[] = [
                    ...$feature,
                    'properties' => [
                        ...(isset($feature['properties']) && is_array($feature['properties']) ? $feature['properties'] : []),
                        'kind' => 'building',
                        'buildingId' => $building->id,
                        'buildingName' => $building->name,
                    ],
                ];
            }
        }

        foreach ($places as $index => $place) {
            $placeFeatures = $this->buildPlaceGeoJson($place, $index);
            foreach ($placeFeatures as $feature) {
                $features[] = $feature;
            }
        }

        if ($floor && is_array($floor->map_data) && is_array($floor->map_data['features'] ?? null)) {
            foreach ($floor->map_data['features'] as $feature) {
                $features[] = $feature;
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    protected function buildPlaceGeoJson($place, int $index): array
    {
        $mappedGeojson = is_array($place->geojson) ? $place->geojson : [];
        $features = $mappedGeojson['features'] ?? [];

        if ($features !== []) {
            foreach ($features as &$feature) {
                $feature = [
                    ...$feature,
                    'properties' => [
                        ...(isset($feature['properties']) && is_array($feature['properties']) ? $feature['properties'] : []),
                        'id' => $place->id,
                        'name' => $place->name,
                        'type' => $place->type,
                        'slug' => $place->slug,
                        'description' => $place->description,
                        'kind' => 'place',
                        'isSelected' => false,
                    ],
                ];
            }

            return $features;
        }

        $x = 140 + ($index % 4) * 230;
        $y = 150 + intdiv($index, 4) * 170;

        return [[
            'type' => 'Feature',
            'properties' => [
                'id' => $place->id,
                'name' => $place->name,
                'type' => $place->type,
                'slug' => $place->slug,
                'description' => $place->description,
                'kind' => 'place',
                'isSelected' => false,
            ],
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$x, $y],
            ],
        ]];
    }
}
