<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\FloorController;
use App\Models\Building;
use App\Models\Floor;
use Illuminate\Http\Request;
use Tests\TestCase;

class FloorNavigationGraphTest extends TestCase
{
    public function test_navigation_paths_create_shared_nodes_and_bidirectional_edges(): void
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
        $this->assertSame(6, $floor->fresh()->navigationEdges()->count());
        $this->assertSame(2, $floor->fresh()->navigationNodes()->where('x', 10)->where('y', 10)->count());
    }
}
