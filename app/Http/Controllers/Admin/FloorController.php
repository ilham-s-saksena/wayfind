<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Floor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'places' => $floor->places()->get(['id', 'name', 'access_node_id']),
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
        abort_unless($floor->building_id === $building->id, 404);

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
        $this->validateNavigationGeometry($mapData);
        $mapData = $this->assignNavigationNodeIds($mapData);
        $this->validateNavigationConnectivity($mapData);
        $this->validatePlaceAccessNodes($floor, $mapData);
        $this->assertNavigationPathsWithinBuilding($building, $mapData);

        $backgroundImage = $validated['background_image'] ?? $floor->background_image;
        $usesGeographicCoordinates = $this->hasGeographicBuildingFootprint($building);

        DB::transaction(function () use ($floor, $mapData, $backgroundImage, $validated, $request, $usesGeographicCoordinates): void {
            $floor->update([
                'map_data' => $mapData,
                'background_image' => $backgroundImage === '' ? null : $backgroundImage,
                'background_visible' => $request->boolean('background_visible', $floor->background_visible ?? true),
                'background_opacity' => $validated['background_opacity'] ?? $floor->background_opacity ?? 0.5,
            ]);

            $this->syncNavigationGraph($floor, $mapData, $usesGeographicCoordinates);
        });

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

    protected function validateNavigationGeometry(array $mapData): void
    {
        foreach ($mapData['features'] ?? [] as $feature) {
            $kind = $feature['properties']['kind'] ?? $feature['properties']['type'] ?? null;
            if ($kind !== 'navigation') {
                continue;
            }

            $coordinates = $feature['geometry']['coordinates'] ?? [];
            if (($feature['geometry']['type'] ?? null) !== 'LineString' || ! is_array($coordinates) || count($coordinates) < 2) {
                throw ValidationException::withMessages([
                    'map_data' => ['Navigation paths must be LineStrings with at least two points.'],
                ]);
            }

            $hasUsableSegment = false;
            $previous = null;
            foreach ($coordinates as $point) {
                if (! is_array($point) || count($point) < 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])
                    || ! is_finite((float) $point[0]) || ! is_finite((float) $point[1])) {
                    throw ValidationException::withMessages([
                        'map_data' => ['A navigation path contains invalid coordinates.'],
                    ]);
                }

                if ($previous !== null && ((float) $previous[0] !== (float) $point[0] || (float) $previous[1] !== (float) $point[1])) {
                    $hasUsableSegment = true;
                }
                $previous = $point;
            }

            if (! $hasUsableSegment) {
                throw ValidationException::withMessages([
                    'map_data' => ['Navigation paths must have a non-zero length.'],
                ]);
            }
        }
    }

    protected function validateNavigationConnectivity(array $mapData): void
    {
        $nodeCoordinates = [];
        foreach ($mapData['features'] ?? [] as $feature) {
            $properties = $feature['properties'] ?? [];
            if (($properties['kind'] ?? $properties['type'] ?? null) !== 'navigation') {
                continue;
            }

            $startNodeId = $properties['start_node_id'] ?? null;
            $endNodeId = $properties['end_node_id'] ?? null;
            if ($startNodeId === $endNodeId) {
                throw ValidationException::withMessages([
                    'map_data' => ['A navigation path cannot connect a node to itself. Disconnect one endpoint or draw a non-looping path.'],
                ]);
            }

            $coordinates = $feature['geometry']['coordinates'];
            foreach ([[$startNodeId, $coordinates[0]], [$endNodeId, $coordinates[array_key_last($coordinates)]]] as [$nodeId, $coordinate]) {
                $coordinate = [(float) $coordinate[0], (float) $coordinate[1]];
                if (isset($nodeCoordinates[$nodeId]) && ($nodeCoordinates[$nodeId][0] !== $coordinate[0] || $nodeCoordinates[$nodeId][1] !== $coordinate[1])) {
                    throw ValidationException::withMessages([
                        'map_data' => ['Connected path endpoints must share the same coordinates. Reconnect the endpoint or move it back to the junction.'],
                    ]);
                }

                $nodeCoordinates[$nodeId] = $coordinate;
            }
        }
    }

    protected function validatePlaceAccessNodes(Floor $floor, array $mapData): void
    {
        $activeNodeIds = [];

        foreach ($mapData['features'] ?? [] as $feature) {
            $properties = $feature['properties'] ?? [];
            if (($properties['kind'] ?? $properties['type'] ?? null) !== 'navigation') {
                continue;
            }

            foreach (['start_node_id', 'end_node_id'] as $key) {
                if (Str::isUuid($properties[$key] ?? null)) {
                    $activeNodeIds[] = $properties[$key];
                }
            }
        }

        $missingAccessPlaces = $floor->places()
            ->whereNotNull('access_node_id')
            ->whereNotIn('access_node_id', array_unique($activeNodeIds) ?: ['00000000-0000-0000-0000-000000000000'])
            ->pluck('name')
            ->all();

        if ($missingAccessPlaces !== []) {
            throw ValidationException::withMessages([
                'map_data' => ['Reconnect or clear the navigation access point for '.implode(', ', $missingAccessPlaces).' before removing those paths.'],
            ]);
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

    protected function syncNavigationGraph(Floor $floor, array $mapData, bool $usesGeographicCoordinates): void
    {
        $features = $mapData['features'] ?? [];
        $navigationFeatures = array_values(array_filter($features, fn ($feature) => ($feature['properties']['kind'] ?? null) === 'navigation' || ($feature['properties']['type'] ?? null) === 'navigation'));

        $nodeCoordinates = [];
        $edgeDefinitions = [];
        foreach ($navigationFeatures as $feature) {
            $properties = $feature['properties'] ?? [];
            $coordinates = $feature['geometry']['coordinates'] ?? [];
            if (($feature['geometry']['type'] ?? null) !== 'LineString' || count($coordinates) < 2) {
                continue;
            }

            $startNodeId = $properties['start_node_id'] ?? null;
            $endNodeId = $properties['end_node_id'] ?? null;
            if (! Str::isUuid($startNodeId) || ! Str::isUuid($endNodeId) || $startNodeId === $endNodeId) {
                continue;
            }

            $start = $coordinates[0];
            $end = $coordinates[array_key_last($coordinates)];
            $nodeCoordinates[$startNodeId] ??= [(float) $start[0], (float) $start[1]];
            $nodeCoordinates[$endNodeId] ??= [(float) $end[0], (float) $end[1]];
            $edgeDefinitions[] = [(string) $feature['id'], $startNodeId, $endNodeId, $coordinates];
        }

        $activeNodeIds = array_keys($nodeCoordinates);
        $nodesById = [];
        foreach ($nodeCoordinates as $nodeId => [$x, $y]) {
            $node = $floor->navigationNodes()->firstOrNew(['id' => $nodeId]);
            $node->id = $nodeId;
            $node->type = 'junction';
            $node->x = $x;
            $node->y = $y;
            $node->save();

            $nodesById[$nodeId] = $node;
        }

        $activeEdgePairs = [];
        foreach ($edgeDefinitions as [$featureId, $startNodeId, $endNodeId, $coordinates]) {
            $start = $nodeCoordinates[$startNodeId];
            $end = $nodeCoordinates[$endNodeId];
            $coordinates[0] = $start;
            $coordinates[array_key_last($coordinates)] = $end;
            $distance = 0.0;
            for ($index = 1; $index < count($coordinates); $index++) {
                $distance += $this->navigationDistance(
                    (float) $coordinates[$index - 1][0],
                    (float) $coordinates[$index - 1][1],
                    (float) $coordinates[$index][0],
                    (float) $coordinates[$index][1],
                    $usesGeographicCoordinates,
                );
            }

            foreach ([[$startNodeId, $endNodeId, $coordinates], [$endNodeId, $startNodeId, array_reverse($coordinates)]] as [$fromId, $toId, $orientedGeometry]) {
                $activeEdgePairs[] = [$fromId, $toId, $featureId];
                $nodesById[$fromId]->outgoingEdges()->updateOrCreate(
                    ['to_node_id' => $toId, 'authored_feature_id' => $featureId],
                    ['distance' => $distance, 'accessible' => true, 'geometry' => $orientedGeometry],
                );
            }
        }

        $floor->navigationEdges()->get()->each(function ($edge) use ($activeEdgePairs): void {
            if (! in_array([$edge->from_node_id, $edge->to_node_id, $edge->authored_feature_id], $activeEdgePairs, true)) {
                $edge->delete();
            }
        });

        $floor->navigationNodes()->whereNotIn('id', $activeNodeIds ?: ['00000000-0000-0000-0000-000000000000'])->delete();
    }

    protected function assignNavigationNodeIds(array $mapData): array
    {
        $featureIds = [];
        foreach ($mapData['features'] ?? [] as $index => $feature) {
            $kind = $feature['properties']['kind'] ?? $feature['properties']['type'] ?? null;
            $coordinates = $feature['geometry']['coordinates'] ?? [];
            if ($kind !== 'navigation' || ($feature['geometry']['type'] ?? null) !== 'LineString' || count($coordinates) < 2) {
                continue;
            }

            $mapData['features'][$index]['properties']['start_node_id'] = Str::isUuid($feature['properties']['start_node_id'] ?? null)
                ? $feature['properties']['start_node_id']
                : (string) Str::uuid();
            $featureId = (string) ($feature['id'] ?? Str::uuid());
            if ($featureId === '' || isset($featureIds[$featureId])) {
                $featureId = (string) Str::uuid();
            }
            $featureIds[$featureId] = true;
            $mapData['features'][$index]['id'] = $featureId;
            $mapData['features'][$index]['properties']['end_node_id'] = Str::isUuid($feature['properties']['end_node_id'] ?? null)
                ? $feature['properties']['end_node_id']
                : (string) Str::uuid();
        }

        return $mapData;
    }

    protected function navigationDistance(float $fromX, float $fromY, float $toX, float $toY, bool $isGeographic): float
    {
        if (! $isGeographic) {
            return hypot($toX - $fromX, $toY - $fromY);
        }

        $latitudeDelta = deg2rad($toY - $fromY);
        $longitudeDelta = deg2rad($toX - $fromX);
        $fromLatitude = deg2rad($fromY);
        $toLatitude = deg2rad($toY);
        $haversine = (sin($latitudeDelta / 2) ** 2)
            + cos($fromLatitude) * cos($toLatitude) * (sin($longitudeDelta / 2) ** 2);

        return 2 * 6371008.8 * asin(min(1, sqrt($haversine)));
    }

    protected function hasGeographicBuildingFootprint(Building $building): bool
    {
        foreach ($building->geojson['features'] ?? [] as $feature) {
            $geometry = $feature['geometry'] ?? [];
            $coordinates = ($geometry['type'] ?? null) === 'Polygon'
                ? ($geometry['coordinates'][0] ?? [])
                : (($geometry['type'] ?? null) === 'MultiPolygon' ? ($geometry['coordinates'][0][0] ?? []) : []);

            if (count($coordinates) < 3) {
                continue;
            }

            $isGeographic = collect($coordinates)->every(fn (array $point): bool => (
                count($point) >= 2
                && abs((float) $point[0]) <= 180
                && abs((float) $point[1]) <= 90
            ));

            if ($isGeographic) {
                return true;
            }
        }

        return false;
    }

    public function destroy(Building $building, Floor $floor)
    {
        $floor->delete();

        return redirect()->route('admin.buildings.show', $building)->with('success', 'Floor removed.');
    }
}
