<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_floor_map_editor_can_store_geojson(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $building = Building::create([
            'name' => 'Main building',
            'slug' => 'main-building',
            'description' => 'Demo building',
            'is_active' => true,
        ]);

        $floor = $building->floors()->create([
            'name' => 'Ground Floor',
            'level' => 0,
        ]);

        $mapData = [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'properties' => ['kind' => 'room', 'name' => 'Reception'],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[0, 0], [120, 0], [120, 90], [0, 90], [0, 0]]],
                ],
            ]],
        ];

        $response = $this->put(route('admin.buildings.floors.saveMapData', [$building->id, $floor->id]), [
            'map_data' => $mapData,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame($mapData, $floor->fresh()->map_data);
    }
}
