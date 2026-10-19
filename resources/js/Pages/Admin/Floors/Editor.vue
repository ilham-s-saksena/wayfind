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
    places: {
        type: Array,
        default: () => [],
    },
    mapData: {
        type: Object,
        default: () => ({
            type: 'FeatureCollection',
            features: [],
        }),
    },
});

const editorData = ref((props.mapData?.features ?? []).reduce((collection, feature) => {
    const id = feature.id ?? `feature-${crypto.randomUUID()}`;
    const properties = { ...(feature.properties ?? {}) };
    if ((properties.kind ?? properties.type) === 'navigation' && feature.geometry?.type === 'LineString') {
        properties.start_node_id ??= crypto.randomUUID();
        properties.end_node_id ??= crypto.randomUUID();
    }
    collection.features.push({ ...feature, id, properties });
    return collection;
}, { type: 'FeatureCollection', features: [] }));
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
const isValidNavigationNodeId = (nodeId) => typeof nodeId === 'string'
    && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(nodeId);
const navigationEndpoints = computed(() => (editorData.value.features ?? []).flatMap((feature) => {
    const properties = feature.properties ?? {};
    const coordinates = feature.geometry?.coordinates ?? [];
    if ((properties.kind ?? properties.type) !== 'navigation' || feature.geometry?.type !== 'LineString' || coordinates.length < 2) {
        return [];
    }

    const endpoints = [
        { featureId: feature.id, role: 'endpoint', nodeId: properties.start_node_id, label: `${properties.name || 'Path'} · start`, coordinate: coordinates[0] },
        { featureId: feature.id, role: 'endpoint', nodeId: properties.end_node_id, label: `${properties.name || 'Path'} · end`, coordinate: coordinates.at(-1) },
    ];

    const bends = coordinates.slice(1, -1).map((coordinate, index) => ({
        featureId: feature.id,
        role: 'bend',
        nodeId: null,
        token: `${feature.id}:bend:${index + 1}`,
        coordinate,
        coordinateIndex: index + 1,
        label: `${properties.name || 'Path'} · bend ${index + 1}`,
    }));

    return [...endpoints, ...bends];
}));
const navigationConnectionTargets = computed(() => {
    const seen = new Set();
    return navigationEndpoints.value.filter((endpoint) => {
        if (endpoint.featureId === selectedFeature.value?.id) return false;
        const key = endpoint.nodeId || endpoint.token;
        if (!key || seen.has(key)) return false;
        seen.add(key);
        return true;
    });
});
const placesWithMissingAccess = computed(() => {
    const nodeIds = new Set(navigationEndpoints.value.map((endpoint) => endpoint.nodeId).filter(Boolean));

    return props.places.filter((place) => place.access_node_id && !nodeIds.has(place.access_node_id));
});
const selectedNavigation = computed(() => selectedFeature.value && (selectedFeature.value.properties?.kind ?? selectedFeature.value.properties?.type) === 'navigation');
const navigationDiagnostics = computed(() => {
    const endpoints = navigationEndpoints.value;
    const graphEndpoints = endpoints.filter((endpoint) => endpoint.role === 'endpoint');
    const knownNodes = new Set(graphEndpoints.map((endpoint) => endpoint.nodeId).filter(isValidNavigationNodeId));
    const degree = new Map();
    const adjacency = new Map();

    endpoints.forEach((endpoint) => {
        if (!endpoint.nodeId) {
            return;
        }
        degree.set(endpoint.nodeId, (degree.get(endpoint.nodeId) ?? 0) + 1);
        if (!adjacency.has(endpoint.nodeId)) adjacency.set(endpoint.nodeId, new Set());
    });
    (editorData.value.features ?? []).forEach((feature) => {
        if ((feature.properties?.kind ?? feature.properties?.type) !== 'navigation') return;
        const start = feature.properties?.start_node_id;
        const end = feature.properties?.end_node_id;
        if (!knownNodes.has(start) || !knownNodes.has(end)) return;
        adjacency.get(start)?.add(end);
        adjacency.get(end)?.add(start);
    });

    let components = 0;
    let coordinateMismatches = 0;
    let selfLoops = 0;
    let invalidGeometry = 0;
    const nodeCoordinates = new Map();
    const coincidentNodes = new Map();
    const navigationFeatures = (editorData.value.features ?? []).filter((feature) => (
        (feature.properties?.kind ?? feature.properties?.type) === 'navigation'
    ));
    navigationFeatures.forEach((feature) => {
        const coordinates = feature.geometry?.coordinates;
        if (feature.geometry?.type !== 'LineString' || !Array.isArray(coordinates) || coordinates.length < 2) {
            invalidGeometry++;
        }
    });
    endpoints.forEach((endpoint) => {
        if (!endpoint.nodeId) return;
        const existing = nodeCoordinates.get(endpoint.nodeId);
        if (existing && (Number(existing[0]) !== Number(endpoint.coordinate[0]) || Number(existing[1]) !== Number(endpoint.coordinate[1]))) {
            coordinateMismatches++;
        } else {
            nodeCoordinates.set(endpoint.nodeId, endpoint.coordinate);
        }
        const coordinateKey = `${Number(endpoint.coordinate[0]).toFixed(8)}:${Number(endpoint.coordinate[1]).toFixed(8)}`;
        const nodeIds = coincidentNodes.get(coordinateKey) ?? new Set();
        nodeIds.add(endpoint.nodeId);
        coincidentNodes.set(coordinateKey, nodeIds);
    });
    (editorData.value.features ?? []).forEach((feature) => {
        if ((feature.properties?.kind ?? feature.properties?.type) === 'navigation'
            && feature.properties?.start_node_id
            && feature.properties.start_node_id === feature.properties?.end_node_id) {
            selfLoops++;
        }
    });
    const visited = new Set();
    adjacency.forEach((_, nodeId) => {
        if (visited.has(nodeId)) return;
        components++;
        const pending = [nodeId];
        while (pending.length) {
            const current = pending.pop();
            if (visited.has(current)) continue;
            visited.add(current);
            adjacency.get(current)?.forEach((neighbor) => pending.push(neighbor));
        }
    });

    return {
        paths: navigationFeatures.length,
        dangling: [...degree.values()].filter((count) => count === 1).length,
        components,
        missingIds: graphEndpoints.filter((endpoint) => !isValidNavigationNodeId(endpoint.nodeId)).length,
        coordinateMismatches,
        selfLoops,
        invalidGeometry,
        coincidentNodes: [...coincidentNodes.values()].reduce((count, ids) => count + Math.max(0, ids.size - 1), 0),
    };
});
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

const connectSelectedEndpoint = (endpointName, nodeId) => {
    const feature = selectedFeature.value;
    if (!feature) return;
    if (!nodeId) {
        editorData.value = {
            ...editorData.value,
            features: editorData.value.features.map((item) => item.id === feature.id
                ? { ...item, properties: { ...(item.properties ?? {}), [endpointName === 'start' ? 'start_node_id' : 'end_node_id']: crypto.randomUUID() } }
                : item),
        };
        return;
    }
    let target = navigationEndpoints.value.find((endpoint) => (endpoint.nodeId === nodeId || endpoint.token === nodeId) && endpoint.featureId !== feature.id);
    if (!target) return;

    const features = [...editorData.value.features];
    if (target.coordinateIndex) {
        const sourceIndex = features.findIndex((item) => item.id === target.featureId);
        const source = features[sourceIndex];
        const coordinates = source.geometry.coordinates;
        const junctionId = crypto.randomUUID();
        const first = {
            ...source,
            geometry: { ...source.geometry, coordinates: coordinates.slice(0, target.coordinateIndex + 1) },
            properties: { ...source.properties, end_node_id: junctionId },
        };
        const second = {
            ...source,
            id: `feature-${crypto.randomUUID()}`,
            geometry: { ...source.geometry, coordinates: coordinates.slice(target.coordinateIndex) },
            properties: { ...source.properties, name: `${source.properties?.name || 'Path'} (continued)`, start_node_id: junctionId },
        };
        features.splice(sourceIndex, 1, first, second);
        target = { ...target, nodeId: junctionId };
    }

    editorData.value = {
        ...editorData.value,
        features: features.map((item) => {
            if (item.id !== feature.id) return item;
            const coordinates = [...(item.geometry.coordinates ?? [])];
            coordinates[endpointName === 'start' ? 0 : coordinates.length - 1] = [...target.coordinate];
            return {
                ...item,
                geometry: { ...item.geometry, coordinates },
                properties: {
                    ...(item.properties ?? {}),
                    [endpointName === 'start' ? 'start_node_id' : 'end_node_id']: target.nodeId || nodeId,
                },
            };
        }),
    };
    validateNavigationFeatures(editorData.value);
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
    if (placesWithMissingAccess.value.length > 0) {
        validationMessage.value = `Reconnect or clear the navigation access point for ${placesWithMissingAccess.value.map((place) => place.name).join(', ')} before removing those paths.`;
        return;
    }

    if (!validateNavigationFeatures(editorData.value)) {
        return;
    }

    if (navigationDiagnostics.value.coordinateMismatches || navigationDiagnostics.value.selfLoops || navigationDiagnostics.value.invalidGeometry) {
        validationMessage.value = 'Fix malformed paths and endpoint connections before saving. Connected endpoints must share a coordinate, and a path cannot connect to itself.';
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
                        <p class="mt-1 text-sm text-slate-600">Use the polyline tool, click along walkable corridors to add bends, then double-click to finish. Select a path and connect either endpoint to an existing endpoint or bend to create an explicit junction.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" class="inline-flex justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" @click="navigationPathMode = !navigationPathMode">
                            {{ navigationPathMode ? 'Navigation paths: on' : 'Navigation paths: off' }}
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
                    :selected-feature-id="selectedFeatureId"
                    feature-kind="navigation"
                    feature-type="navigation"
                    @change="onMapChange"
                    @feature-selected="onFeatureSelected"
                />

                <div v-if="validationMessage" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                    {{ validationMessage }}
                </div>

                <div class="mt-3 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600">
                    Navigation network: {{ navigationDiagnostics.paths }} paths · {{ navigationDiagnostics.components }} connected component(s) · {{ navigationDiagnostics.dangling }} unconnected endpoint(s).
                    <span v-if="navigationDiagnostics.missingIds"> {{ navigationDiagnostics.missingIds }} endpoint ID(s) will be assigned when saved.</span>
                    <span v-if="navigationDiagnostics.coordinateMismatches || navigationDiagnostics.selfLoops" class="font-medium text-rose-700"> {{ navigationDiagnostics.coordinateMismatches + navigationDiagnostics.selfLoops }} invalid connection(s) need repair.</span>
                    <span v-if="navigationDiagnostics.invalidGeometry" class="font-medium text-rose-700"> {{ navigationDiagnostics.invalidGeometry }} malformed path(s) need repair.</span>
                    <span v-if="navigationDiagnostics.coincidentNodes" class="font-medium text-amber-700"> {{ navigationDiagnostics.coincidentNodes }} coincident endpoint(s) have separate node IDs; connect them only if they are a real junction.</span>
                    <span v-if="navigationDiagnostics.components > 1" class="font-medium text-amber-700"> These paths form separate components; connect them if they should be reachable from one another.</span>
                    <span v-else-if="!navigationDiagnostics.missingIds"> Nearby lines remain disconnected until you connect their endpoints.</span>
                </div>
                <div v-if="placesWithMissingAccess.length" class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    {{ placesWithMissingAccess.map((place) => place.name).join(', ') }} use navigation nodes that are missing from this edited network. Reassign their access point in the Place Editor before saving.
                </div>
                <div class="mt-2 flex flex-wrap gap-4 px-1 text-xs text-slate-600">
                    <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-full border-2 border-teal-700 bg-teal-100"></span>Connected junction</span>
                    <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-full border-2 border-amber-700 bg-amber-50"></span>Unconnected endpoint</span>
                    <span class="inline-flex items-center gap-2"><span class="h-1 w-5 rounded bg-orange-600"></span>Selected path</span>
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
                            <div v-if="selectedNavigation" class="space-y-3 rounded-lg bg-teal-50 p-3">
                                <p class="text-sm font-medium text-teal-900">Path connections</p>
                                <label v-for="endpoint in ['start', 'end']" :key="endpoint" class="block text-sm text-slate-700">
                                    Connect {{ endpoint }} endpoint to
                                    <select class="mt-1 w-full rounded-md border-gray-300 text-sm" :value="selectedFeature.properties?.[`${endpoint}_node_id`] ?? ''" @change="connectSelectedEndpoint(endpoint, $event.target.value)">
                                        <option value="">Keep separate</option>
                                        <option v-for="candidate in navigationConnectionTargets" :key="candidate.nodeId || candidate.token" :value="candidate.nodeId || candidate.token">
                                            {{ candidate.label }}
                                        </option>
                                    </select>
                                </label>
                                <p class="text-xs text-teal-800">Selecting an endpoint creates an explicit shared junction. Line crossings and nearby endpoints do not connect automatically.</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Name</label>
                                <input v-model="selectedLabel" type="text" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @input="updateSelectedFeature" />
                            </div>
                            <div v-if="!selectedNavigation">
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
