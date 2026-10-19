<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MapView from '@/Components/Map/MapView.vue';
import {
    coordinatesFromPlaceFeature,
    resolveGraphRoute,
    splitRouteByUserPosition,
} from '@/lib/navigationRouting';

const props = defineProps({
    buildings: {
        type: Array,
        default: () => [],
    },
    floors: {
        type: Array,
        default: () => [],
    },
    places: {
        type: Array,
        default: () => [],
    },
    selectedBuildingId: {
        type: String,
        default: null,
    },
    selectedFloorId: {
        type: String,
        default: null,
    },
    selectedPlace: {
        type: Object,
        default: null,
    },
    search: {
        type: String,
        default: '',
    },
    mapGeojson: {
        type: Object,
        default: () => ({
            type: 'FeatureCollection',
            features: [],
        }),
    },
    navigationGraph: {
        type: Object,
        default: () => ({
            nodes: [],
            edges: [],
        }),
    },
    backgroundImage: {
        type: String,
        default: '',
    },
    backgroundVisible: {
        type: Boolean,
        default: true,
    },
    backgroundOpacity: {
        type: Number,
        default: 0.5,
    },
});

const searchTerm = ref(props.search || '');
const activePlace = ref(props.selectedPlace || props.places[0] || null);
const isSidebarOpen = ref(false);
const isNavigationOpen = ref(false);
const isDestinationModalOpen = ref(true);
const routeSummary = ref('');
const currentLocation = ref(null);
const geolocationStatus = ref('not-requested');
const geolocationWatcherId = ref(null);
const mapViewRef = ref(null);
const isNavigationActive = ref(false);

const debugGeolocation = (message, meta = {}) => {
    if (import.meta.env.DEV) {
        console.info('[Wayfind geolocation]', message, meta);
    }
};

const clearGeolocationWatcher = () => {
    if (geolocationWatcherId.value !== null && navigator?.geolocation) {
        navigator.geolocation.clearWatch(geolocationWatcherId.value);
        geolocationWatcherId.value = null;
    }
};

const selectedBuilding = computed(() =>
    props.buildings.find((building) => building.id === props.selectedBuildingId) || props.buildings[0] || null,
);

const selectedFloor = computed(() =>
    props.floors.find((floor) => floor.id === props.selectedFloorId) || props.floors[0] || null,
);

const routeState = ref({
    fullPath: [],
    completedPath: [],
    remainingPath: [],
    progress: 0,
});

const getPlaceCoordinates = (place) => {
    const geojson = props.mapGeojson ?? { type: 'FeatureCollection', features: [] };
    if (place?.access_node_id) {
        const accessNode = props.navigationGraph?.nodes?.find((node) => String(node.id) === String(place.access_node_id));
        return accessNode ? [Number(accessNode.x), Number(accessNode.y)] : null;
    }

    const matchedFeature = (geojson.features ?? []).find((feature) => (
        String(feature?.properties?.id ?? '') === String(place?.id ?? '')
        && feature?.geometry?.type === 'Point'
        && feature?.properties?.kind !== 'place-access'
    ));

    const fromFeature = coordinatesFromPlaceFeature(matchedFeature);
    if (fromFeature) {
        return fromFeature;
    }

    return null;
};

const resolveRouteFromGraph = (resetProgress = false) => {
    const graph = props.navigationGraph ?? { nodes: [], edges: [] };

    if (!activePlace.value || !graph?.nodes?.length) {
        routeState.value = { fullPath: [], completedPath: [], remainingPath: [], progress: 0 };
        if (activePlace.value && !graph?.nodes?.length) {
            routeSummary.value = 'No navigation network is configured for this floor.';
        }
        return;
    }

    const placePoint = getPlaceCoordinates(activePlace.value);
    if (!placePoint) {
        routeState.value = { fullPath: [], completedPath: [], remainingPath: [], progress: 0 };
        const hasAreaGeometry = (props.mapGeojson?.features ?? []).some((feature) => (
            String(feature?.properties?.id ?? '') === String(activePlace.value?.id ?? '')
            && ['Polygon', 'MultiPolygon'].includes(feature?.geometry?.type)
        ));
        routeSummary.value = hasAreaGeometry
            ? 'This destination needs a navigation access point. Set its entrance in the Place Editor.'
            : 'This destination has no usable point geometry or navigation access point.';
        return;
    }

    const hasUserLocation = currentLocation.value
        && Number.isFinite(currentLocation.value.latitude)
        && Number.isFinite(currentLocation.value.longitude);

    if (!hasUserLocation) {
        routeState.value = { fullPath: [], completedPath: [], remainingPath: [], progress: 0 };
        routeSummary.value = 'Allow location access to calculate a route from your current position.';
        return;
    }

    const route = resolveGraphRoute(
        graph,
        [currentLocation.value.longitude, currentLocation.value.latitude],
        placePoint,
    );

    if (!route.reachable || route.fullPath.length < 2) {
        routeState.value = { fullPath: [], completedPath: [], remainingPath: [], progress: 0 };
        routeSummary.value = route.reason === 'disconnected'
            ? 'No connected navigation path reaches this destination.'
            : 'Your position or destination is too far from the mapped navigation paths.';
        return;
    }

    if (resetProgress) {
        routeState.value.progress = 0;
    }

    let completedPath = [];
    let remainingPath = [...route.fullPath];
    let progress = 0;

    if (isNavigationActive.value) {
        const split = splitRouteByUserPosition(
            route.fullPath,
            [currentLocation.value.longitude, currentLocation.value.latitude],
            resetProgress ? 0 : routeState.value.progress,
        );

        if (split.deviated) {
            routeSummary.value = 'You are off route. Recalculating from your current position.';
            routeState.value.progress = 0;
        }

        completedPath = split.completedPath;
        remainingPath = split.remainingPath;
        progress = split.deviated ? 0 : split.progress;
    } else {
        remainingPath = [...route.fullPath];
    }

    routeState.value = {
        fullPath: route.fullPath,
        completedPath,
        remainingPath,
        progress,
    };
};

const resultSummary = computed(() => {
    if (props.places.length === 0) {
        return 'No results';
    }

    return `${props.places.length} ${props.places.length === 1 ? 'destination' : 'destinations'}`;
});

const locationStatusMessage = computed(() => {
    if (geolocationStatus.value === 'requesting') {
        return 'Requesting your location…';
    }

    if (geolocationStatus.value === 'denied') {
        return 'Location access was denied. Please allow geolocation to show your position.';
    }

    if (geolocationStatus.value === 'unavailable') {
        return 'Location information is unavailable on this device right now.';
    }

    if (geolocationStatus.value === 'timeout') {
        return 'Location request timed out. Please try again.';
    }

    if (geolocationStatus.value === 'unsupported') {
        return 'This browser does not support geolocation.';
    }

    if (geolocationStatus.value === 'error') {
        return 'Unable to determine your location. Please retry.';
    }

    return '';
});

watch(
    () => props.selectedPlace,
    (value) => {
        activePlace.value = value || props.places[0] || null;
        isNavigationActive.value = false;
        routeState.value = { fullPath: [], completedPath: [], remainingPath: [], progress: 0 };
    },
    { immediate: true },
);

watch(
    [() => activePlace.value, () => currentLocation.value, () => props.navigationGraph, () => props.mapGeojson],
    ([, location], [, previousLocation]) => {
        if (isNavigationActive.value && routeState.value.fullPath.length > 1 && location && location !== previousLocation) {
            const split = splitRouteByUserPosition(
                routeState.value.fullPath,
                [location.longitude, location.latitude],
                routeState.value.progress,
            );

            if (!split.deviated) {
                routeState.value = {
                    ...routeState.value,
                    completedPath: split.completedPath,
                    remainingPath: split.remainingPath,
                    progress: split.progress,
                };
                return;
            }

            routeState.value.progress = 0;
            resolveRouteFromGraph(true);
            return;
        }
        resolveRouteFromGraph();
    },
    { deep: true },
);

const requestCurrentLocation = () => {
    if (!navigator?.geolocation) {
        geolocationStatus.value = 'unsupported';
        routeSummary.value = 'Location services are not available in this browser.';
        debugGeolocation('geolocation unsupported', { secureContext: window.isSecureContext, userAgent: navigator.userAgent });
        return;
    }

    const secureContextStatus = window.isSecureContext ? 'secure' : 'insecure';
    debugGeolocation('requesting geolocation', { secureContext: secureContextStatus });

    if (navigator.permissions?.query) {
        navigator.permissions.query({ name: 'geolocation' }).then((permissionStatus) => {
            debugGeolocation('permission state', { state: permissionStatus.state });
            permissionStatus.addEventListener?.('change', () => {
                debugGeolocation('permission changed', { state: permissionStatus.state });
            });
        }).catch((error) => {
            debugGeolocation('permission query failed', { message: error?.message ?? 'unknown permission query error' });
        });
    }

    clearGeolocationWatcher();
    geolocationStatus.value = 'requesting';
    routeSummary.value = 'Locating your current position…';

    geolocationWatcherId.value = navigator.geolocation.watchPosition(
        (position) => {
            const nextLocation = {
                latitude: position.coords.latitude,
                longitude: position.coords.longitude,
            };

            if (!Number.isFinite(nextLocation.latitude) || !Number.isFinite(nextLocation.longitude)) {
                geolocationStatus.value = 'unavailable';
                routeSummary.value = 'The device reported an invalid position. Please retry.';
                debugGeolocation('invalid coordinates returned', nextLocation);
                return;
            }

            currentLocation.value = nextLocation;
            geolocationStatus.value = 'ready';
            routeSummary.value = 'Current location is ready for route guidance.';
            debugGeolocation('location updated', nextLocation);
        },
        (error) => {
            currentLocation.value = null;
            geolocationStatus.value = error.code === 1 ? 'denied' : error.code === 2 ? 'unavailable' : error.code === 3 ? 'timeout' : 'error';

            const errors = {
                1: 'Location access was denied. Please allow geolocation to use your current position.',
                2: 'Location information is unavailable on this device or browser right now.',
                3: 'The geolocation request timed out. Please try again.',
            };

            routeSummary.value = errors[error.code] ?? `Unable to determine your location: ${error.message || 'unknown error'}`;
            debugGeolocation('geolocation error', {
                code: error.code,
                message: error.message,
                secureContext: secureContextStatus,
            });
        },
        {
            enableHighAccuracy: true,
            timeout: 20000,
            maximumAge: 30000,
        },
    );
};

const centerMapOnUser = () => {
    mapViewRef.value?.centerOnUserLocation?.();
};

onMounted(() => {
    requestCurrentLocation();
});

onBeforeUnmount(() => {
    clearGeolocationWatcher();
});

const onSearch = () => {
    router.get(
        route('map'),
        {
            building: props.selectedBuildingId,
            floor: props.selectedFloorId,
            q: searchTerm.value,
        },
        {
            preserveState: true,
            replace: true,
        },
    );
};

const changeBuilding = (buildingId) => {
    router.get(
        route('map'),
        {
            building: buildingId,
            floor: null,
            q: searchTerm.value,
        },
        {
            preserveState: true,
            replace: true,
        },
    );
};

const changeFloor = (floorId) => {
    router.get(
        route('map'),
        {
            building: props.selectedBuildingId,
            floor: floorId,
            q: searchTerm.value,
        },
        {
            preserveState: true,
            replace: true,
        },
    );
};

const selectPlace = (place, keepDestinationModalOpen = false) => {
    activePlace.value = place;
    isDestinationModalOpen.value = keepDestinationModalOpen;
    isNavigationActive.value = false;

    if (place) {
        routeSummary.value = `Destination selected: ${place.name}. Choose “Navigate Me” to follow the guided route.`;
    }

    resolveRouteFromGraph(true);
};

const startNavigation = () => {
    if (!activePlace.value) {
        routeSummary.value = 'Please select a destination before starting navigation.';
        return;
    }

    isNavigationActive.value = true;
    resolveRouteFromGraph(true);

    if (routeState.value.fullPath.length > 1 && currentLocation.value) {
        routeSummary.value = `Following route from your current location to ${activePlace.value.name}.`;
        centerMapOnUser();
        return;
    }

    if (!currentLocation.value) {
        routeSummary.value = 'Allow location access before starting navigation.';
    }
};

</script>

<template>
    <Head title="Wayfind map" />

    <div class="relative h-screen overflow-hidden bg-slate-100">
        <div v-if="isDestinationModalOpen" class="absolute inset-0 z-[1000] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm">
            <div class="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-5 shadow-2xl">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-medium uppercase tracking-[0.2em] text-slate-500">Choose destination</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-900">Where would you like to go?</h2>
                    </div>
                    <button type="button" class="rounded-full border border-slate-200 px-2 py-1 text-xs text-slate-500 hover:bg-slate-50" @click="isDestinationModalOpen = false">
                        Close
                    </button>
                </div>

                <div class="space-y-2">
                    <button
                        v-for="place in places"
                        :key="place.id"
                        type="button"
                        class="flex w-full items-center justify-between rounded-2xl border px-3 py-3 text-left transition"
                        :class="activePlace?.id === place.id ? 'border-indigo-200 bg-indigo-50 ring-2 ring-indigo-100' : 'border-slate-200 bg-slate-50 hover:border-indigo-200 hover:bg-indigo-50/60'"
                        @click="selectPlace(place, true)"
                    >
                        <div>
                            <div class="font-medium text-slate-800">{{ place.name }}</div>
                            <div class="text-xs text-slate-500">{{ place.slug }}</div>
                        </div>
                        <span class="rounded-full bg-white px-2 py-1 text-[10px] font-medium uppercase tracking-[0.2em] text-slate-500">
                            {{ place.access_node_id || getPlaceCoordinates(place) ? (place.type || 'place') : 'Access point missing' }}
                        </span>
                    </button>
                </div>

                <div v-if="routeSummary" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    {{ routeSummary }}
                </div>

                <div class="mt-4 flex items-center justify-end gap-2">
                    <button type="button" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50" @click="isDestinationModalOpen = false">
                        Later
                    </button>
                    <button
                        type="button"
                        class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-indigo-300"
                        :disabled="!activePlace || !currentLocation || routeState.fullPath.length < 2"
                        @click="startNavigation(); isDestinationModalOpen = false;"
                    >
                        Navigate Me
                    </button>
                </div>
            </div>
        </div>
        <div class="absolute inset-0 z-10">
            <MapView
                ref="mapViewRef"
                mode="geo"
                :geojson="mapGeojson"
                :selected-place="activePlace"
                :user-location="currentLocation"
                :route-geometry="routeState.fullPath"
                :completed-route="routeState.completedPath"
                :remaining-route="routeState.remainingPath"
                :route-visible="isNavigationActive || !!activePlace"
                :background-image="backgroundImage || ''"
                :background-visible="backgroundVisible"
                :background-opacity="backgroundOpacity"
                @place-selected="selectPlace"
            />
        </div>

        <button
            type="button"
            class="absolute left-4 top-20 z-40 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white/95 px-3 py-2 text-sm font-medium text-slate-700 shadow-sm backdrop-blur-sm transition hover:bg-white"
            @click="isSidebarOpen = !isSidebarOpen"
        >
            {{ isSidebarOpen ? 'Hide filters' : 'Show filters' }}
        </button>

        <div class="absolute right-4 top-4 z-40 flex items-center gap-2">
            <button
                v-if="currentLocation"
                type="button"
                class="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-medium text-sky-700 shadow-sm backdrop-blur-sm hover:bg-sky-100"
                @click="centerMapOnUser"
            >
                Center on me
            </button>
            <button
                type="button"
                class="rounded-xl border border-slate-200 bg-white/95 px-3 py-2 text-sm font-medium text-slate-700 shadow-sm backdrop-blur-sm hover:bg-white"
                @click="isNavigationOpen = !isNavigationOpen"
            >
                Navigation
            </button>
        </div>

        <div
            v-if="locationStatusMessage"
            class="absolute left-1/2 top-4 z-30 flex max-w-[calc(100vw-2rem)] -translate-x-1/2 items-center gap-3 rounded-xl border border-sky-200 bg-white/95 px-3 py-2 text-xs font-medium text-slate-700 shadow-sm backdrop-blur-sm"
            :class="geolocationStatus === 'denied' || geolocationStatus === 'unavailable' || geolocationStatus === 'timeout' || geolocationStatus === 'unsupported' || geolocationStatus === 'error' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-sky-200 bg-white/95 text-slate-700'"
        >
            <span>{{ locationStatusMessage }}</span>
            <button
                v-if="['denied', 'unavailable', 'timeout', 'error'].includes(geolocationStatus)"
                type="button"
                class="shrink-0 rounded-lg border border-amber-300 bg-white px-2 py-1 font-semibold text-amber-900 hover:bg-amber-100"
                @click="requestCurrentLocation"
            >
                Retry location
            </button>
        </div>

        <div v-if="isNavigationOpen" class="absolute right-4 top-16 z-20 w-80 rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-xl backdrop-blur-sm">
            <div class="mb-3 flex items-center justify-between">
                <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Route options</p>
            </div>

            <div class="space-y-2">
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-xl border border-slate-200 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                    :disabled="!activePlace || !currentLocation || routeState.fullPath.length < 2"
                    @click="startNavigation()"
                >
                    <span>Current location → {{ activePlace?.name || 'destination' }}</span>
                </button>

                <label class="block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Destination</label>
                <select :value="activePlace?.id || ''" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-100" @change="selectPlace(places.find((place) => String(place.id) === $event.target.value) || null)">
                    <option v-for="place in places" :key="place.id" :value="place.id">{{ place.name }}</option>
                </select>
            </div>

            <div v-if="routeSummary" class="mt-4 rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2 text-sm text-indigo-700">
                {{ routeSummary }}
            </div>
        </div>

        <aside
            v-if="isSidebarOpen"
            class="absolute left-4 top-28 z-20 w-[360px] max-w-[calc(100vw-2rem)] rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-xl backdrop-blur-sm"
        >
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Wayfind</p>
                    <h1 class="text-xl font-semibold text-slate-900">Find your destination</h1>
                </div>
                <a :href="route('admin.buildings.index')" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                    Admin
                </a>
            </div>

            <div class="mt-4 space-y-3">
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                    <label class="mb-1 block text-[11px] font-medium uppercase tracking-[0.2em] text-slate-500">Building</label>
                    <select :value="selectedBuildingId || ''" class="w-full border-0 bg-transparent text-sm text-slate-800 focus:ring-0" @change="changeBuilding($event.target.value)">
                        <option v-for="building in buildings" :key="building.id" :value="building.id">
                            {{ building.name }}
                        </option>
                    </select>
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                    <label class="mb-1 block text-[11px] font-medium uppercase tracking-[0.2em] text-slate-500">Floor</label>
                    <select :value="selectedFloorId || ''" class="w-full border-0 bg-transparent text-sm text-slate-800 focus:ring-0" @change="changeFloor($event.target.value)">
                        <option v-for="floor in floors" :key="floor.id" :value="floor.id">
                            {{ floor.name }}
                        </option>
                    </select>
                </div>

                <form class="flex items-center rounded-xl border border-slate-200 bg-slate-50 px-3 py-2" @submit.prevent="onSearch">
                    <input
                        v-model="searchTerm"
                        type="search"
                        placeholder="Search room, facility, or destination"
                        class="w-full border-0 bg-transparent text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0"
                    />
                    <button type="submit" class="ml-2 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-500">
                        Search
                    </button>
                </form>
            </div>

            <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Results</p>
                    <span class="text-xs text-slate-500">{{ resultSummary }}</span>
                </div>
                <div class="space-y-2">
                    <button
                        v-for="place in places"
                        :key="place.id"
                        type="button"
                        class="flex w-full items-center justify-between rounded-xl border px-3 py-2 text-left transition hover:border-indigo-200 hover:bg-indigo-50"
                        :class="activePlace?.id === place.id ? 'border-indigo-200 bg-indigo-50' : 'border-slate-200 bg-white'"
                        @click="selectPlace(place)"
                    >
                        <div>
                            <div class="font-medium text-slate-800">{{ place.name }}</div>
                            <div class="text-xs text-slate-500">{{ place.slug }}</div>
                        </div>
                        <span class="text-xs uppercase tracking-wide text-slate-500">{{ place.type || 'other' }}</span>
                    </button>
                    <div v-if="places.length === 0" class="rounded-xl border border-dashed border-slate-200 px-3 py-6 text-center text-sm text-slate-500">
                        No matching places found.
                    </div>
                </div>
            </div>
        </aside>

        <div class="pointer-events-none absolute bottom-4 left-4 z-20 rounded-2xl border border-slate-200 bg-white/90 px-3 py-2 shadow-sm backdrop-blur-sm">
            <div class="pointer-events-auto flex items-center gap-3">
                <div>
                    <p class="text-[10px] uppercase tracking-[0.2em] text-slate-500">Current location</p>
                    <p class="text-sm font-medium text-slate-800">{{ selectedBuilding?.name || 'Building' }}</p>
                </div>
                <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
                    {{ selectedFloor?.name || 'Floor' }}
                </span>
            </div>
        </div>
    </div>
</template>
