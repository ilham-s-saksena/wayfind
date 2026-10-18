<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MapEditor from '@/Components/Map/MapEditor.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    building: {
        type: Object,
        required: true,
    },
    floor: {
        type: Object,
        required: true,
    },
    mapData: {
        type: Object,
        default: () => ({
            type: 'FeatureCollection',
            features: [],
        }),
    },
});

const editorData = ref(props.mapData ?? {
    type: 'FeatureCollection',
    features: [],
});
const backgroundImage = ref(props.floor.background_image || '');
const backgroundVisible = ref(props.floor.background_visible ?? true);
const backgroundOpacity = ref(Number(props.floor.background_opacity ?? 0.5));
const navigationPathMode = ref(true);
const validationMessage = ref('');
const mapMode = computed(() => (backgroundImage.value ? 'indoor' : 'geo'));
const buildingContext = computed(() => {
    const features = [];

    if (props.building?.geojson?.features) {
        features.push(...props.building.geojson.features.map((feature) => ({
            ...feature,
            properties: {
                ...(feature.properties ?? {}),
                kind: 'building',
                type: 'building',
            },
        })));
    }

    if (editorData.value?.features) {
        features.push(...editorData.value.features.map((feature) => ({
            ...feature,
            properties: {
                ...(feature.properties ?? {}),
                kind: feature.properties?.kind ?? 'floor',
                type: feature.properties?.type ?? 'floor',
            },
        })));
    }

    return {
        type: 'FeatureCollection',
        features,
    };
});
const selectedFeatureId = ref(null);
const selectedFeature = computed(() => editorData.value.features.find((feature) => feature.id === selectedFeatureId.value) || null);
const selectedLabel = ref('');
const selectedType = ref('room');

const pointInPolygon = (point, ring) => {
    if (!Array.isArray(ring) || ring.length === 0) {
        return false;
    }

    let inside = false;
    const [x, y] = point;

    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const xi = Number(ring[i][0]);
        const yi = Number(ring[i][1]);
        const xj = Number(ring[j][0]);
        const yj = Number(ring[j][1]);
        const intersects = ((yi > y) !== (yj > y)) && (x < ((xj - xi) * (y - yi)) / (yj - yi + 0.00000001) + xi);

        if (intersects) {
            inside = !inside;
        }
    }

    return inside;
};

const polygonContainsPoint = (polygonCoordinates, point) => {
    if (!Array.isArray(polygonCoordinates) || polygonCoordinates.length === 0) {
        return false;
    }

    const outerRing = polygonCoordinates[0] ?? [];
    return pointInPolygon(point, outerRing);
};

const buildingPolygons = computed(() => {
    if (!props.building?.geojson?.features) {
        return [];
    }

    return props.building.geojson.features
        .filter((feature) => feature?.geometry?.type === 'Polygon' || feature?.geometry?.type === 'MultiPolygon')
        .flatMap((feature) => {
            if (feature.geometry.type === 'Polygon') {
                return [feature.geometry.coordinates];
            }

            return feature.geometry.coordinates ?? [];
        });
});

const lineStaysWithinBuilding = (coordinates) => {
    if (!Array.isArray(coordinates) || coordinates.length < 2 || buildingPolygons.value.length === 0) {
        return false;
    }

    for (let index = 0; index < coordinates.length; index++) {
        const point = coordinates[index];
        if (!Array.isArray(point) || point.length < 2) {
            return false;
        }

        const isInside = buildingPolygons.value.some((polygon) => polygonContainsPoint(polygon, point));
        if (!isInside) {
            return false;
        }
    }

    for (let index = 0; index < coordinates.length - 1; index++) {
        const start = coordinates[index];
        const end = coordinates[index + 1];
        const steps = Math.max(6, Math.ceil(Math.hypot(end[0] - start[0], end[1] - start[1]) * 4));

        for (let step = 0; step <= steps; step++) {
            const ratio = step / steps;
            const sample = [
                start[0] + (end[0] - start[0]) * ratio,
                start[1] + (end[1] - start[1]) * ratio,
            ];

            const sampleInside = buildingPolygons.value.some((polygon) => polygonContainsPoint(polygon, sample));
            if (!sampleInside) {
                return false;
            }
        }
    }

    return true;
};

const validateNavigationFeatures = (value) => {
    const features = Array.isArray(value?.features) ? value.features : [];
    const invalid = features.filter((feature) => {
        const kind = feature?.properties?.kind ?? feature?.properties?.type ?? '';
        if (kind !== 'navigation') {
            return false;
        }

        const geometry = feature?.geometry ?? null;
        return geometry?.type === 'LineString' && !lineStaysWithinBuilding(geometry.coordinates ?? []);
    });

    validationMessage.value = invalid.length > 0
        ? 'Navigation paths must stay inside the building boundary. Move or redraw the path within the building polygon.'
        : '';

    return invalid.length === 0;
};

const updateSelectedFeature = () => {
    if (!selectedFeature.value) {
        return;
    }

    editorData.value = {
        ...editorData.value,
        features: editorData.value.features.map((feature) => {
            if (feature.id !== selectedFeatureId.value) {
                return feature;
            }

            return {
                ...feature,
                properties: {
                    ...(feature.properties ?? {}),
                    name: selectedLabel.value,
                    type: selectedType.value,
                    kind: feature.properties?.kind ?? selectedType.value,
                },
            };
        }),
    };
};

const onFeatureSelected = (feature) => {
    if (!feature) {
        selectedFeatureId.value = null;
        return;
    }

    selectedFeatureId.value = feature.id;
    selectedLabel.value = feature.properties?.name ?? '';
    selectedType.value = feature.properties?.type ?? feature.properties?.kind ?? 'room';
};

const onBackgroundUpload = (event) => {
    const [file] = event.target.files;
    if (!file) {
        return;
    }

    const reader = new FileReader();
    reader.onload = () => {
        backgroundImage.value = String(reader.result || '');
    };
    reader.readAsDataURL(file);
};

const onMapChange = (value) => {
    const filteredFeatures = (value?.features ?? []).filter((feature) => {
        const kind = feature?.properties?.kind ?? feature?.properties?.type ?? '';
        return kind !== 'building';
    });

    editorData.value = {
        type: 'FeatureCollection',
        features: filteredFeatures,
    };

    validateNavigationFeatures(editorData.value);
};

const saveMap = () => {
    if (!validateNavigationFeatures(editorData.value)) {
        return;
    }

    router.put(route('admin.buildings.floors.saveMapData', [props.building.id, props.floor.id]), {
        map_data: editorData.value,
        background_image: backgroundImage.value,
        background_visible: backgroundVisible.value,
        background_opacity: backgroundOpacity.value,
    }, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Map editor - ${floor.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="text-sm font-medium uppercase tracking-wide text-indigo-600">Map editor</div>
                    <h2 class="text-xl font-semibold text-gray-800">{{ building.name }} · {{ floor.name }}</h2>
                </div>
                <a :href="route('admin.buildings.show', building.id)" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Back to building
                </a>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Editor controls</p>
                        <p class="mt-1 text-sm text-slate-600">Draw rooms, corridors, points of interest, or navigation paths directly on the floor plan.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" class="inline-flex justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" @click="navigationPathMode = !navigationPathMode">
                            {{ navigationPathMode ? 'Navigation mode on' : 'Navigation mode off' }}
                        </button>
                        <button type="button" class="inline-flex justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500" @click="saveMap">
                            Save map data
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-[2fr_0.8fr]">
                <MapEditor
                    :map-data="buildingContext"
                    :mode="mapMode"
                    :draw-mode="navigationPathMode ? 'navigation' : 'mixed'"
                    :background-image="backgroundImage"
                    :background-visible="backgroundVisible"
                    :background-opacity="backgroundOpacity"
                    :map-center="[0, 0]"
                    :map-zoom="15"
                    feature-kind="navigation"
                    feature-type="navigation"
                    @change="onMapChange"
                    @feature-selected="onFeatureSelected"
                />

                <div v-if="validationMessage" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                    {{ validationMessage }}
                </div>

                <aside class="space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Floor plan background</p>
                        <label class="mt-2 block text-sm text-slate-700">
                            <span class="mb-1 block">Upload PNG/JPG</span>
                            <input type="file" accept="image/*" class="block w-full text-sm text-slate-500" @change="onBackgroundUpload" />
                        </label>
                        <div class="mt-3 flex items-center gap-3">
                            <input v-model="backgroundVisible" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            <label class="text-sm text-slate-700">Show background</label>
                        </div>
                        <div class="mt-3">
                            <label class="block text-sm font-medium text-slate-700">Opacity</label>
                            <input v-model.number="backgroundOpacity" type="range" min="0" max="1" step="0.05" class="mt-2 w-full" />
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Selected feature</p>
                        <div v-if="selectedFeature" class="mt-3 space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Name</label>
                                <input v-model="selectedLabel" type="text" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @input="updateSelectedFeature" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Type</label>
                                <select v-model="selectedType" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @change="updateSelectedFeature">
                                    <option value="room">Room</option>
                                    <option value="poi">POI</option>
                                    <option value="navigation">Navigation</option>
                                    <option value="corridor">Corridor</option>
                                </select>
                            </div>
                        </div>
                        <div v-else class="mt-3 text-sm text-slate-500">
                            Click a drawn feature to edit the room, POI, or navigation path.
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
