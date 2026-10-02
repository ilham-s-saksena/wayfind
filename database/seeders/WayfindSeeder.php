<?php

namespace Database\Seeders;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Place;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class WayfindSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $building = Building::firstOrCreate(
            ['slug' => 'tvri-kalimantan-barat-gedung-utama'],
            [
                'name' => 'TVRI Kalimantan Barat - Gedung Utama',
                'description' => 'Main administrative and visitor building for wayfinding and indoor navigation.',
                'is_active' => true,
            ],
        );

        $floorDefinitions = [
            ['name' => 'Lantai 1', 'level' => 1],
            ['name' => 'Lantai 2', 'level' => 2],
            ['name' => 'Lantai 3', 'level' => 3],
        ];

        $floors = [];

        foreach ($floorDefinitions as $definition) {
            $floors[$definition['level']] = $building->floors()->firstOrCreate(
                ['building_id' => $building->id, 'level' => $definition['level']],
                [
                    'name' => $definition['name'],
                    'map_data' => [
                        'type' => 'FeatureCollection',
                        'features' => [],
                    ],
                ],
            );
        }

        $placeDefinitions = [
            ['floor' => 1, 'name' => 'Lobby', 'type' => 'lobby'],
            ['floor' => 1, 'name' => 'Ruang Rapat', 'type' => 'room'],
            ['floor' => 1, 'name' => 'Toilet', 'type' => 'toilet'],
            ['floor' => 1, 'name' => 'Mushola', 'type' => 'mosque'],
            ['floor' => 2, 'name' => 'Ruang Kepala Stasiun', 'type' => 'office'],
            ['floor' => 2, 'name' => 'Studio', 'type' => 'studio'],
            ['floor' => 2, 'name' => 'Ruang Teknik', 'type' => 'room'],
            ['floor' => 2, 'name' => 'Ruang Transmisi', 'type' => 'room'],
            ['floor' => 3, 'name' => 'Parkir', 'type' => 'parking'],
            ['floor' => 3, 'name' => 'Entrance', 'type' => 'entrance'],
        ];

        foreach ($placeDefinitions as $placeData) {
            $floor = $floors[$placeData['floor']] ?? null;

            if (! $floor) {
                continue;
            }

            $place = $floor->places()->firstOrCreate(
                ['slug' => Str::slug($placeData['name'])],
                [
                    'name' => $placeData['name'],
                    'type' => $placeData['type'],
                    'description' => 'Demo facility for indoor wayfinding.',
                    'is_searchable' => true,
                    'is_active' => true,
                ],
            );

            if ($place->wasRecentlyCreated) {
                $place->slug = Str::slug($placeData['name']);
                $place->save();
            }
        }
    }
}
