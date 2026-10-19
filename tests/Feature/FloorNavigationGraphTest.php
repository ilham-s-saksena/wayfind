<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\FloorController;
use App\Models\Building;
use App\Models\Floor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class FloorNavigationGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_paths_preserve_bends_without_turning_each_vertex_into_a_junction(): void
    {
        $building = Building::create([
            'name' => 'Test Building',
            'slug' => 'test-building',
            'description' => 'Example',
            'geojson' => [
                'type' => 'FeatureCollection',
                'features' => [],
            ],
            'is_active' => true,
        ]);

        $floor = Floor::create([
            'building_id' => $building->id,
            'name' => 'Level 1',
            'level' => 1,
            'map_data' => [
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'properties' => ['kind' => 'navigation', 'type' => 'navigation'],
                        'geometry' => [
                            'type' => 'LineString',
                            'coordinates' => [[0, 0], [10, 10], [20, 0]],
                        ],
                    ],
                    [
                        'type' => 'Feature',
                        'properties' => ['kind' => 'navigation', 'type' => 'navigation'],
                        'geometry' => [
                            'type' => 'LineString',
                            'coordinates' => [[10, 10], [10, 20]],
                        ],
                    ],
                ],
            ],
            'background_image' => null,
            'background_visible' => true,
            'background_opacity' => 0.5,
        ]);

        app(FloorController::class)->saveMapData(new Request([
            'map_data' => $floor->map_data,
        ]), $building, $floor);

        $this->assertSame(4, $floor->fresh()->navigationNodes()->count());
        $this->assertSame(4, $floor->fresh()->navigationEdges()->count());
        $this->assertSame(0, $floor->fresh()->navigationNodes()->where('x', 10)->where('y', 10)->count());
        $this->assertTrue($floor->fresh()->navigationEdges()->get()->contains(
            fn ($edge) => count($edge->geometry ?? []) === 3,
        ));
    }

    public function test_only_explicitly_shared_endpoint_ids_create_a_junction(): void
    {
        $building = Building::create([
            'name' => 'Junction Building',
            'slug' => 'junction-building',
            'is_active' => true,
        ]);
        $junctionId = '11111111-1111-4111-8111-111111111111';
        $floor = $building->floors()->create([
            'name' => 'Level 1',
            'level' => 1,
            'map_data' => ['type' => 'FeatureCollection', 'features' => []],
        ]);
        $mainPath = $this->navigationFeature([[0, 0], [1, 0], [2, 0]], '22222222-2222-4222-8222-222222222222', $junctionId);
        $branchPath = $this->navigationFeature([[2, 0], [2, 1]], $junctionId, '33333333-3333-4333-8333-333333333333');
        $mapData = ['type' => 'FeatureCollection', 'features' => [
            $mainPath,
            $branchPath,
        ]];

        app(FloorController::class)->saveMapData(new Request(['map_data' => $mapData]), $building, $floor);

        $saved = $floor->fresh();
        $this->assertSame(3, $saved->navigationNodes()->count());
        $this->assertSame(4, $saved->navigationEdges()->count());
        $this->assertSame(1, $saved->navigationNodes()->whereKey($junctionId)->count());
        $this->assertTrue($saved->navigationEdges()->where('from_node_id', $junctionId)->get()->contains(
            fn ($edge) => count($edge->geometry ?? []) === 3,
        ));

        app(FloorController::class)->saveMapData(new Request([
            'map_data' => ['type' => 'FeatureCollection', 'features' => [$mainPath]],
        ]), $building, $saved);

        $this->assertSame(2, $saved->fresh()->navigationNodes()->count());
        $this->assertSame(2, $saved->fresh()->navigationEdges()->count());
    }

    public function test_nearby_navigation_coordinates_remain_separate_nodes(): void
    {
        $building = Building::create([
            'name' => 'Test Building',
            'slug' => 'nearby-test-building',
            'is_active' => true,
        ]);
        $floor = $building->floors()->create([
            'name' => 'Level 1',
            'level' => 1,
            'map_data' => ['type' => 'FeatureCollection', 'features' => []],
        ]);
        $mapData = [
            'type' => 'FeatureCollection',
            'features' => [
                $this->navigationFeature([[10.00001, 10], [10.001, 10]]),
                $this->navigationFeature([[10.00001, 10], [10.001, 10.001]]),
            ],
        ];

        app(FloorController::class)->saveMapData(new Request(['map_data' => $mapData]), $building, $floor);

        $this->assertSame(4, $floor->fresh()->navigationNodes()->count());
        $this->assertSame(2, $floor->fresh()->navigationNodes()
            ->where('y', 10)
            ->whereBetween('x', [10.00001, 10.00002])
            ->count());
    }

    public function test_removing_all_navigation_paths_removes_the_old_graph(): void
    {
        $building = Building::create([
            'name' => 'Test Building',
            'slug' => 'empty-graph-test-building',
            'is_active' => true,
        ]);
        $floor = $building->floors()->create([
            'name' => 'Level 1',
            'level' => 1,
            'map_data' => ['type' => 'FeatureCollection', 'features' => []],
        ]);
        $withNavigation = ['type' => 'FeatureCollection', 'features' => [$this->navigationFeature([[0, 0], [1, 1]])]];
        $withoutNavigation = ['type' => 'FeatureCollection', 'features' => []];

        app(FloorController::class)->saveMapData(new Request(['map_data' => $withNavigation]), $building, $floor);
        app(FloorController::class)->saveMapData(new Request(['map_data' => $withoutNavigation]), $building, $floor->fresh());

        $this->assertSame(0, $floor->fresh()->navigationNodes()->count());
        $this->assertSame(0, $floor->fresh()->navigationEdges()->count());
    }

    private function navigationFeature(array $coordinates, ?string $startNodeId = null, ?string $endNodeId = null): array
    {
        return [
            'type' => 'Feature',
            'properties' => array_filter([
                'kind' => 'navigation',
                'start_node_id' => $startNodeId,
                'end_node_id' => $endNodeId,
            ]),
            'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
        ];
    }
}
