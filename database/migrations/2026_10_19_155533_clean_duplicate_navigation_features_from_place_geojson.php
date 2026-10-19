<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_geojson_cleanup_backups', function (Blueprint $table): void {
            $table->uuid('place_id')->primary();
            $table->json('original_geojson')->nullable();
        });

        DB::transaction(function (): void {
            $floors = DB::table('floors')->get(['id', 'map_data'])->keyBy('id');

            foreach (DB::table('places')->orderBy('id')->get(['id', 'floor_id', 'geojson']) as $place) {
                $floor = $floors->get($place->floor_id);
                $placeGeoJson = $this->decodeJson($place->geojson);
                $floorGeoJson = $this->decodeJson($floor?->map_data);

                if (! is_array($placeGeoJson) || ! is_array($floorGeoJson)) {
                    continue;
                }

                $authoredNavigationFeatures = collect($floorGeoJson['features'] ?? [])
                    ->filter(fn (mixed $feature): bool => is_array($feature)
                        && (($feature['properties']['kind'] ?? $feature['properties']['type'] ?? null) === 'navigation')
                        && isset($feature['id'], $feature['geometry']))
                    ->keyBy(fn (array $feature): string => (string) $feature['id']);

                if ($authoredNavigationFeatures->isEmpty()) {
                    continue;
                }

                $remainingFeatures = [];
                $removedDuplicate = false;

                foreach ($placeGeoJson['features'] ?? [] as $feature) {
                    if (! is_array($feature)) {
                        $remainingFeatures[] = $feature;

                        continue;
                    }

                    $kind = $feature['properties']['kind'] ?? $feature['properties']['type'] ?? null;
                    $featureId = isset($feature['id']) ? (string) $feature['id'] : null;
                    $authoredFeature = $featureId === null ? null : $authoredNavigationFeatures->get($featureId);
                    $isExactDuplicate = $kind === 'navigation'
                        && $authoredFeature !== null
                        && ($feature['geometry'] ?? null) == ($authoredFeature['geometry'] ?? null);

                    if ($isExactDuplicate) {
                        $removedDuplicate = true;

                        continue;
                    }

                    $remainingFeatures[] = $feature;
                }

                if (! $removedDuplicate) {
                    continue;
                }

                DB::table('place_geojson_cleanup_backups')->insert([
                    'place_id' => $place->id,
                    'original_geojson' => json_encode($placeGeoJson, JSON_THROW_ON_ERROR),
                ]);

                $placeGeoJson['features'] = array_values($remainingFeatures);
                DB::table('places')->where('id', $place->id)->update([
                    'geojson' => json_encode($placeGeoJson, JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('place_geojson_cleanup_backups')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (DB::table('place_geojson_cleanup_backups')->get(['place_id', 'original_geojson']) as $backup) {
                DB::table('places')->where('id', $backup->place_id)->update([
                    'geojson' => $backup->original_geojson,
                ]);
            }

            Schema::drop('place_geojson_cleanup_backups');
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
};
