<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MapView from '@/Components/Map/MapView.vue';

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
});

const searchTerm = ref(props.search || '');
const activePlace = ref(props.selectedPlace || props.places[0] || null);
const isSidebarOpen = ref(false);
const isNavigationOpen = ref(false);
const routePreset = ref('');
const routeSummary = ref('');
const currentLocation = ref(null);
const geolocationStatus = ref('not-requested');
const geolocationWatcherId = ref(null);
const mapViewRef = ref(null);

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

const destinationOptions = computed(() => props.places.map((place) => ({
    id: place.id,
    label: place.name,
    value: place.id,
})));

watch(
    () => props.selectedPlace,
    (value) => {
        activePlace.value = value || props.places[0] || null;
    },
    { immediate: true },
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

const selectPlace = (place) => {
    activePlace.value = place;
};

const setRoutePreset = (preset) => {
    routePreset.value = preset;

    if (preset === 'security-to-office') {
        routeSummary.value = 'Route set: Security post → Station Head office.';
        return;
    }

    if (currentLocation.value) {
        routeSummary.value = `Route set: current location → ${activePlace?.value?.name ?? 'selected destination'}.`;
        return;
    }

    routeSummary.value = 'Current location is not available. Allow browser location access or choose the security-post route.';
};
</script>

<template>
    <Head title="Wayfind map" />

    <div class="relative h-screen overflow-hidden bg-slate-100">
        <div class="absolute inset-0 z-10">
            <MapView ref="mapViewRef" mode="geo" :geojson="mapGeojson" :selected-place="activePlace" :user-location="currentLocation" @place-selected="selectPlace" />
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
            class="absolute left-1/2 top-4 z-30 -translate-x-1/2 rounded-xl border border-sky-200 bg-white/95 px-3 py-2 text-xs font-medium text-slate-700 shadow-sm backdrop-blur-sm"
            :class="geolocationStatus === 'denied' || geolocationStatus === 'unavailable' || geolocationStatus === 'timeout' || geolocationStatus === 'unsupported' || geolocationStatus === 'error' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-sky-200 bg-white/95 text-slate-700'"
        >
            {{ locationStatusMessage }}
        </div>

        <div v-if="isNavigationOpen" class="absolute right-4 top-16 z-20 w-80 rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-xl backdrop-blur-sm">
            <div class="mb-3 flex items-center justify-between">
                <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Route options</p>
            </div>

            <div class="space-y-2">
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-xl border border-slate-200 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                    :class="routePreset === 'security-to-office' ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : ''"
                    @click="setRoutePreset('security-to-office')"
                >
                    <span>Security post → Station Head office</span>
                </button>

                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-xl border border-slate-200 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                    :class="routePreset === 'current-to-place' ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : ''"
                    :disabled="!currentLocation || !activePlace"
                    @click="setRoutePreset('current-to-place')"
                >
                    <span>Current location → {{ activePlace?.name || 'destination' }}</span>
                </button>

                <label class="block text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Destination</label>
                <select v-model="activePlace" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                    <option v-for="place in places" :key="place.id" :value="place">{{ place.name }}</option>
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
