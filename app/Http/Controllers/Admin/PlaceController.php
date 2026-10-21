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
    private const GEOMETRY_TOLERANCE = 1.0E-10;

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
            'access_node_id' => ['nullable', 'uuid', 'exists:navigation_nodes,id'],
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
        $this->validateAccessNode($floor, $validated['access_node_id'] ?? null);
        $this->assertPlaceIsWithinBuilding($building, $geojson);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_searchable'] = $request->boolean('is_searchable', true);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['geojson'] = $geojson;

        $floor->places()->create($validated);

        return redirect()->route('admin.floors.places.index', $floor)->with('success', 'Place created successfully.');
    }

    public function edit(Floor $floor, Place $place)
    {
        abort_unless($place->floor_id === $floor->id, 404);

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
            'access_node_id' => ['nullable', 'uuid', 'exists:navigation_nodes,id'],
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
        abort_unless($place->floor_id === $floor->id, 404);
        $this->validateAccessNode($floor, $validated['access_node_id'] ?? null);
        $this->assertPlaceIsWithinBuilding($building, $geojson);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_searchable'] = $request->boolean('is_searchable', true);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['geojson'] = $geojson;

        $place->update($validated);

        return redirect()->route('admin.floors.places.index', $floor)->with('success', 'Place updated successfully.');
    }

    public function destroy(Floor $floor, Place $place)
    {
        abort_unless($place->floor_id === $floor->id, 404);

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

        $features = array_values(array_filter($features, function ($feature): bool {
            if (! is_array($feature)) {
                return false;
            }

            $properties = $feature['properties'] ?? [];
            $geometry = $feature['geometry'] ?? null;
            if (! is_array($properties)) {
                $properties = [];
            }

            $kind = $properties['kind'] ?? $properties['type'] ?? null;
            $geometryType = is_array($geometry) ? ($geometry['type'] ?? null) : null;

            return ! in_array($kind, ['building', 'navigation'], true)
                && ($properties['type'] ?? null) !== 'building'
                && in_array($geometryType, ['Point', 'MultiPoint', 'Polygon', 'MultiPolygon'], true);
        }));

        if ($features === []) {
            throw ValidationException::withMessages([
                'geojson' => ['Place geometry must contain a point or polygon. Navigation paths are stored on the floor map.'],
            ]);
        }

        return [
            'type' => 'FeatureCollection',
            'features' => array_map(fn (array $feature): array => [
                ...$feature,
                'properties' => [
                    ...($feature['properties'] ?? []),
                    'kind' => 'place',
                ],
            ], $features),
        ];
    }

    protected function validateAccessNode(Floor $floor, ?string $accessNodeId): void
    {
        if ($accessNodeId !== null && ! $floor->navigationNodes()->whereKey($accessNodeId)->exists()) {
            throw ValidationException::withMessages([
                'access_node_id' => ['Choose an access point connected to the selected floor navigation network.'],
            ]);
        }
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
        if (! is_array($geometry) || ! is_array($geometry['coordinates'] ?? null)) {
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
            if (is_array($geometry['coordinates'] ?? null)) {
                $polygons[] = $geometry['coordinates'];
            }

            return;
        }

        if (($geometry['type'] ?? null) === 'MultiPolygon') {
            $coordinates = $geometry['coordinates'] ?? null;
            if (! is_array($coordinates)) {
                return;
            }

            foreach ($coordinates as $polygonCoords) {
                if (is_array($polygonCoords)) {
                    $polygons[] = $polygonCoords;
                }
            }
        }
    }

    protected function pointIsInsideAnyPolygon(array $point, array $buildingPolygons): bool
    {
        if (! $this->isValidCoordinate($point)) {
            return false;
        }

        foreach ($buildingPolygons as $polygon) {
            if (is_array($polygon) && $this->pointInPolygon($point, $polygon)) {
                return true;
            }
        }

        return false;
    }

    protected function polygonIsInsideAnyPolygon(array $rings, array $buildingPolygons): bool
    {
        if (! $this->hasValidPolygonRings($rings)) {
            return false;
        }

        $outerRing = $rings[0];

        foreach ($buildingPolygons as $buildingPolygon) {
            if (! $this->hasValidPolygonRings($buildingPolygon)) {
                continue;
            }

            $inside = true;
            foreach ($outerRing as $index => $coordinate) {
                if (! $this->pointInPolygon($coordinate, $buildingPolygon)) {
                    $inside = false;
                    break;
                }

                $nextCoordinate = $outerRing[($index + 1) % (count($outerRing) - 1)];
                if (! $this->segmentIsInsidePolygon($coordinate, $nextCoordinate, $buildingPolygon)) {
                    $inside = false;
                    break;
                }
            }

            if ($inside) {
                foreach (array_slice($buildingPolygon, 1) as $buildingHole) {
                    $holeInteriorPoint = $this->ringInteriorPoint($buildingHole);
                    if ($holeInteriorPoint !== null && $this->pointInPolygon($holeInteriorPoint, $rings)) {
                        $inside = false;
                        break;
                    }
                }
            }

            if ($inside) {
                return true;
            }
        }

        return false;
    }

    protected function multiPolygonIsInsideAnyPolygon(array $polygons, array $buildingPolygons): bool
    {
        if ($polygons === []) {
            return false;
        }

        foreach ($polygons as $polygonSet) {
            if (! is_array($polygonSet) || ! $this->polygonIsInsideAnyPolygon($polygonSet, $buildingPolygons)) {
                return false;
            }
        }

        return true;
    }

    protected function multiPointIsInsideAnyPolygon(array $points, array $buildingPolygons): bool
    {
        if ($points === []) {
            return false;
        }

        foreach ($points as $point) {
            if (! is_array($point) || ! $this->pointIsInsideAnyPolygon($point, $buildingPolygons)) {
                return false;
            }
        }

        return true;
    }

    protected function pointInPolygon(array $point, array $polygon): bool
    {
        if (! $this->hasValidPolygonRings($polygon) || ! $this->isValidCoordinate($point)) {
            return false;
        }

        $outerRing = $polygon[0];
        if ($this->pointIsOnRingBoundary($point, $outerRing)) {
            return true;
        }

        if (! $this->pointIsInsideRing($point, $outerRing)) {
            return false;
        }

        foreach (array_slice($polygon, 1) as $hole) {
            if ($this->pointIsOnRingBoundary($point, $hole)) {
                return true;
            }

            if ($this->pointIsInsideRing($point, $hole)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array<int, float|int>> $ring */
    protected function pointIsInsideRing(array $point, array $ring): bool
    {
        $inside = false;
        $longitude = (float) $point[0];
        $latitude = (float) $point[1];
        $j = count($ring) - 1;

        for ($i = 0; $i < count($ring); $i++) {
            $xi = (float) $ring[$i][0];
            $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];

            $intersect = (($yi > $latitude) !== ($yj > $latitude))
                && ($longitude < (($xj - $xi) * ($latitude - $yi) / ($yj - $yi)) + $xi);

            if ($intersect) {
                $inside = ! $inside;
            }

            $j = $i;
        }

        return $inside;
    }

    /** @return array{0: float, 1: float}|null */
    protected function ringInteriorPoint(array $ring): ?array
    {
        $latitudes = array_values(array_unique(array_map(
            fn (array $coordinate): string => sprintf('%.12F', (float) $coordinate[1]),
            array_slice($ring, 0, -1),
        )));
        sort($latitudes, SORT_NUMERIC);

        for ($index = 0, $count = count($latitudes) - 1; $index < $count; $index++) {
            $latitude = ((float) $latitudes[$index] + (float) $latitudes[$index + 1]) / 2;
            $longitudes = [];

            for ($edge = 0, $edgeCount = count($ring) - 1; $edge < $edgeCount; $edge++) {
                $start = $ring[$edge];
                $end = $ring[$edge + 1];
                if (($start[1] > $latitude) === ($end[1] > $latitude)) {
                    continue;
                }

                $longitudes[] = $start[0] + (($latitude - $start[1]) * ($end[0] - $start[0]) / ($end[1] - $start[1]));
            }

            sort($longitudes, SORT_NUMERIC);
            for ($intersection = 0, $intersectionCount = count($longitudes) - 1; $intersection < $intersectionCount; $intersection += 2) {
                $point = [((float) $longitudes[$intersection] + (float) $longitudes[$intersection + 1]) / 2, $latitude];
                if ($this->pointIsInsideRing($point, $ring)) {
                    return $point;
                }
            }
        }

        return null;
    }

    protected function pointIsOnRingBoundary(array $point, array $ring): bool
    {
        for ($index = 0, $count = count($ring) - 1; $index < $count; $index++) {
            if ($this->pointIsOnSegment($point, $ring[$index], $ring[$index + 1])) {
                return true;
            }
        }

        return false;
    }

    protected function pointIsOnSegment(array $point, array $start, array $end): bool
    {
        $crossProduct = (($point[1] - $start[1]) * ($end[0] - $start[0]))
            - (($point[0] - $start[0]) * ($end[1] - $start[1]));
        $scale = max(1.0, abs($end[0] - $start[0]), abs($end[1] - $start[1]));

        if (abs($crossProduct) > self::GEOMETRY_TOLERANCE * $scale) {
            return false;
        }

        return $point[0] >= min($start[0], $end[0]) - self::GEOMETRY_TOLERANCE
            && $point[0] <= max($start[0], $end[0]) + self::GEOMETRY_TOLERANCE
            && $point[1] >= min($start[1], $end[1]) - self::GEOMETRY_TOLERANCE
            && $point[1] <= max($start[1], $end[1]) + self::GEOMETRY_TOLERANCE;
    }

    protected function isValidCoordinate(mixed $coordinate): bool
    {
        return is_array($coordinate)
            && count($coordinate) >= 2
            && is_numeric($coordinate[0])
            && is_numeric($coordinate[1])
            && is_finite((float) $coordinate[0])
            && is_finite((float) $coordinate[1]);
    }

    protected function hasValidPolygonRings(array $rings): bool
    {
        if ($rings === []) {
            return false;
        }

        foreach ($rings as $ring) {
            if (! is_array($ring) || count($ring) < 4) {
                return false;
            }

            foreach ($ring as $coordinate) {
                if (! $this->isValidCoordinate($coordinate)) {
                    return false;
                }
            }

            if (! $this->coordinatesMatch($ring[0], $ring[count($ring) - 1])) {
                return false;
            }
        }

        return true;
    }

    protected function coordinatesMatch(array $first, array $second): bool
    {
        return abs((float) $first[0] - (float) $second[0]) <= self::GEOMETRY_TOLERANCE
            && abs((float) $first[1] - (float) $second[1]) <= self::GEOMETRY_TOLERANCE;
    }

    protected function segmentIsInsidePolygon(array $start, array $end, array $polygon): bool
    {
        $parameters = [0.0, 1.0];

        foreach ($polygon as $ring) {
            for ($index = 0, $count = count($ring) - 1; $index < $count; $index++) {
                foreach ($this->segmentIntersectionParameters($start, $end, $ring[$index], $ring[$index + 1]) as $parameter) {
                    $parameters[] = $parameter;
                }
            }
        }

        sort($parameters, SORT_NUMERIC);
        $parameters = array_values(array_unique(array_map(
            fn (float $parameter): string => sprintf('%.12F', $parameter),
            $parameters,
        )));

        for ($index = 0, $count = count($parameters) - 1; $index < $count; $index++) {
            $from = (float) $parameters[$index];
            $to = (float) $parameters[$index + 1];
            if ($to - $from <= self::GEOMETRY_TOLERANCE) {
                continue;
            }

            $middle = ($from + $to) / 2;
            $sample = [
                $start[0] + ($end[0] - $start[0]) * $middle,
                $start[1] + ($end[1] - $start[1]) * $middle,
            ];

            if (! $this->pointInPolygon($sample, $polygon)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, float> */
    protected function segmentIntersectionParameters(array $start, array $end, array $edgeStart, array $edgeEnd): array
    {
        $direction = [$end[0] - $start[0], $end[1] - $start[1]];
        $edgeDirection = [$edgeEnd[0] - $edgeStart[0], $edgeEnd[1] - $edgeStart[1]];
        $offset = [$edgeStart[0] - $start[0], $edgeStart[1] - $start[1]];
        $denominator = ($direction[0] * $edgeDirection[1]) - ($direction[1] * $edgeDirection[0]);

        if (abs($denominator) <= self::GEOMETRY_TOLERANCE) {
            return [];
        }

        $parameter = (($offset[0] * $edgeDirection[1]) - ($offset[1] * $edgeDirection[0])) / $denominator;
        $edgeParameter = (($offset[0] * $direction[1]) - ($offset[1] * $direction[0])) / $denominator;

        if ($parameter < -self::GEOMETRY_TOLERANCE || $parameter > 1 + self::GEOMETRY_TOLERANCE
            || $edgeParameter < -self::GEOMETRY_TOLERANCE || $edgeParameter > 1 + self::GEOMETRY_TOLERANCE) {
            return [];
        }

        return [max(0.0, min(1.0, $parameter))];
    }
}
