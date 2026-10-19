<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_access_node_backups', function (Blueprint $table): void {
            $table->uuid('place_id')->primary();
            $table->uuid('original_access_node_id')->nullable();
            $table->uuid('assigned_access_node_id');
            $table->string('assignment_reason');
            $table->decimal('distance_meters', 8, 3);
        });

        DB::transaction(function (): void {
            $floors = DB::table('floors')->get(['id', 'map_data'])->keyBy('id');

            foreach (DB::table('places')->whereNull('access_node_id')->orderBy('id')->get(['id', 'name', 'floor_id', 'geojson', 'access_node_id']) as $place) {
                $floor = $floors->get($place->floor_id);
                $placeGeoJson = $this->decodeJson($place->geojson);
                $floorGeoJson = $this->decodeJson($floor?->map_data);

                if (! is_array($placeGeoJson) || ! is_array($floorGeoJson)) {
                    continue;
                }

                $nodes = DB::table('navigation_nodes')
                    ->where('floor_id', $place->floor_id)
                    ->get(['id', 'x', 'y'])
                    ->keyBy('id');

                if ($nodes->isEmpty()) {
                    continue;
                }

                $geometries = $this->placeGeometries($placeGeoJson);
                if ($geometries === []) {
                    continue;
                }

                $namedCandidates = $this->namedRouteCandidates($place->name, $floorGeoJson['features'] ?? [], $nodes, $geometries);
                $candidate = $namedCandidates !== []
                    ? $this->closestCandidate($namedCandidates)
                    : $this->closestUnambiguousNode($nodes, $geometries);

                if ($candidate === null) {
                    continue;
                }

                DB::table('place_access_node_backups')->insert([
                    'place_id' => $place->id,
                    'original_access_node_id' => $place->access_node_id,
                    'assigned_access_node_id' => $candidate['node_id'],
                    'assignment_reason' => $candidate['reason'],
                    'distance_meters' => $candidate['distance_meters'],
                ]);

                DB::table('places')->where('id', $place->id)->whereNull('access_node_id')->update([
                    'access_node_id' => $candidate['node_id'],
                ]);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('place_access_node_backups')) {
            return;
        }

        $changedAssignments = DB::table('place_access_node_backups')
            ->join('places', 'places.id', '=', 'place_access_node_backups.place_id')
            ->whereColumn('places.access_node_id', '<>', 'place_access_node_backups.assigned_access_node_id')
            ->exists();

        if ($changedAssignments) {
            throw new LogicException('Place access-node assignments changed after the migration. Resolve those edits before rolling back.');
        }

        DB::transaction(function (): void {
            foreach (DB::table('place_access_node_backups')->get(['place_id', 'original_access_node_id']) as $backup) {
                DB::table('places')->where('id', $backup->place_id)->update([
                    'access_node_id' => $backup->original_access_node_id,
                ]);
            }

            Schema::drop('place_access_node_backups');
        });
    }

    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<int, array{type: string, coordinates: array}> */
    private function placeGeometries(array $geoJson): array
    {
        $geometries = [];

        foreach ($geoJson['features'] ?? [] as $feature) {
            $geometry = $feature['geometry'] ?? null;
            if (! is_array($geometry)) {
                continue;
            }

            if (in_array($geometry['type'] ?? null, ['Point', 'Polygon', 'MultiPolygon'], true)) {
                $geometries[] = ['type' => $geometry['type'], 'coordinates' => $geometry['coordinates'] ?? []];
            }
        }

        return $geometries;
    }

    /**
     * @param  array<int, mixed>  $features
     * @param  Collection<string, object>  $nodes
     * @param  array<int, array{type: string, coordinates: array}>  $geometries
     * @return array<int, array{node_id: string, distance_meters: float, reason: string}>
     */
    private function namedRouteCandidates(string $placeName, array $features, Collection $nodes, array $geometries): array
    {
        $placeNameTokens = $this->nameTokens($placeName);
        if ($placeNameTokens === []) {
            return [];
        }

        $candidates = [];
        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? [];
            $routeName = (string) ($properties['name'] ?? '');
            $routeNameTokens = $this->nameTokens($routeName);
            $coordinates = $feature['geometry']['coordinates'] ?? [];

            if (($properties['kind'] ?? $properties['type'] ?? null) !== 'navigation'
                || $routeNameTokens === []
                || ! $this->containsTokenSequence($placeNameTokens, $routeNameTokens)
                || ($feature['geometry']['type'] ?? null) !== 'LineString'
                || count($coordinates) < 2) {
                continue;
            }

            foreach ([['start_node_id', $coordinates[0]], ['end_node_id', $coordinates[array_key_last($coordinates)]]] as [$nodeKey, $coordinate]) {
                $nodeId = $properties[$nodeKey] ?? null;
                if (! is_string($nodeId) || ! $nodes->has($nodeId)) {
                    continue;
                }

                $node = $nodes->get($nodeId);
                if ($this->distanceBetweenCoordinates([(float) $node->x, (float) $node->y], $coordinate) > 0.5) {
                    continue;
                }

                $distance = $this->distanceToPlaceGeometry((float) $node->x, (float) $node->y, $geometries);
                if ($distance <= 8.0 && (! isset($candidates[$nodeId]) || $distance < $candidates[$nodeId]['distance_meters'])) {
                    $candidates[$nodeId] = [
                        'node_id' => $nodeId,
                        'distance_meters' => $distance,
                        'reason' => 'matched_route_name:'.$routeName,
                    ];
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * @param  array<int, array{node_id: string, distance_meters: float, reason: string}>  $candidates
     * @return array{node_id: string, distance_meters: float, reason: string}|null
     */
    private function closestCandidate(array $candidates): ?array
    {
        usort($candidates, fn (array $first, array $second): int => $first['distance_meters'] <=> $second['distance_meters']);

        return $candidates[0] ?? null;
    }

    /**
     * @param  Collection<string, object>  $nodes
     * @param  array<int, array{type: string, coordinates: array}>  $geometries
     * @return array{node_id: string, distance_meters: float, reason: string}|null
     */
    private function closestUnambiguousNode(Collection $nodes, array $geometries): ?array
    {
        $candidates = [];
        foreach ($nodes as $node) {
            $candidates[] = [
                'node_id' => $node->id,
                'distance_meters' => $this->distanceToPlaceGeometry((float) $node->x, (float) $node->y, $geometries),
                'reason' => 'unique_nearby_network_node',
            ];
        }

        usort($candidates, fn (array $first, array $second): int => $first['distance_meters'] <=> $second['distance_meters']);
        $closest = $candidates[0] ?? null;
        $nextClosest = $candidates[1]['distance_meters'] ?? INF;

        if ($closest === null
            || $closest['distance_meters'] > 8.0
            || $nextClosest < 20.0
            || $nextClosest < $closest['distance_meters'] * 3) {
            return null;
        }

        return $closest;
    }

    /** @param array<int, array{type: string, coordinates: array}> $geometries */
    private function distanceToPlaceGeometry(float $longitude, float $latitude, array $geometries): float
    {
        $minimumDistance = INF;
        foreach ($geometries as $geometry) {
            if ($geometry['type'] === 'Point') {
                $minimumDistance = min($minimumDistance, $this->distanceBetweenCoordinates([$longitude, $latitude], $geometry['coordinates']));

                continue;
            }

            $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];
            foreach ($polygons as $polygon) {
                $ring = $polygon[0] ?? [];
                foreach (array_slice($ring, 1) as $index => $end) {
                    $start = $ring[$index] ?? null;
                    if (is_array($start) && is_array($end)) {
                        $minimumDistance = min($minimumDistance, $this->distanceToSegment($longitude, $latitude, $start, $end));
                    }
                }
            }
        }

        return $minimumDistance;
    }

    private function distanceToSegment(float $longitude, float $latitude, array $start, array $end): float
    {
        $scaleX = 111_320 * cos(deg2rad($latitude));
        $scaleY = 111_132;
        $startX = ((float) $start[0] - $longitude) * $scaleX;
        $startY = ((float) $start[1] - $latitude) * $scaleY;
        $endX = ((float) $end[0] - $longitude) * $scaleX;
        $endY = ((float) $end[1] - $latitude) * $scaleY;
        $deltaX = $endX - $startX;
        $deltaY = $endY - $startY;
        $lengthSquared = ($deltaX ** 2) + ($deltaY ** 2);
        $ratio = $lengthSquared === 0.0
            ? 0.0
            : max(0.0, min(1.0, -(($startX * $deltaX) + ($startY * $deltaY)) / $lengthSquared));

        return hypot($startX + ($ratio * $deltaX), $startY + ($ratio * $deltaY));
    }

    private function distanceBetweenCoordinates(array $first, array $second): float
    {
        $latitudeDifference = deg2rad((float) $second[1] - (float) $first[1]);
        $longitudeDifference = deg2rad((float) $second[0] - (float) $first[0]);
        $latitudeFirst = deg2rad((float) $first[1]);
        $latitudeSecond = deg2rad((float) $second[1]);
        $haversine = (sin($latitudeDifference / 2) ** 2)
            + (cos($latitudeFirst) * cos($latitudeSecond) * (sin($longitudeDifference / 2) ** 2));

        return 6_371_000 * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine));
    }

    /** @return array<int, string> */
    private function nameTokens(string $name): array
    {
        $normalized = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $name)));

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /** @param array<int, string> $tokens
     * @param  array<int, string>  $candidate
     */
    private function containsTokenSequence(array $tokens, array $candidate): bool
    {
        if (count($candidate) > count($tokens)) {
            return false;
        }

        foreach (array_keys($tokens) as $start) {
            if (array_slice($tokens, $start, count($candidate)) === $candidate) {
                return true;
            }
        }

        return false;
    }
};
