<script setup>
import { computed, ref, watch } from 'vue';
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

watch(
    () => props.selectedPlace,
    (value) => {
        activePlace.value = value || props.places[0] || null;
    },
    { immediate: true },
);

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
</script>

<template>
    <Head title="Wayfind map" />

    <div class="min-h-screen bg-slate-100">
        <header class="border-b border-slate-200 bg-white/90 backdrop-blur-sm">
            <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-[0.2em] text-indigo-600">Wayfind</p>
                        <h1 class="text-2xl font-semibold text-slate-900">Find your destination</h1>
                    </div>
                    <a :href="route('admin.buildings.index')" class="inline-flex items-center justify-center rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Admin panel
                    </a>
                </div>

                <div class="grid gap-3 md:grid-cols-[1.3fr_1fr_1.2fr_auto]">
                    <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                        <label class="mb-1 block text-[11px] font-medium uppercase tracking-[0.2em] text-slate-500">Building</label>
                        <select :value="selectedBuildingId || ''" class="w-full border-0 bg-transparent text-sm text-slate-800 focus:ring-0" @change="changeBuilding($event.target.value)">
                            <option v-for="building in buildings" :key="building.id" :value="building.id">
                                {{ building.name }}
                            </option>
                        </select>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                        <label class="mb-1 block text-[11px] font-medium uppercase tracking-[0.2em] text-slate-500">Floor</label>
                        <select :value="selectedFloorId || ''" class="w-full border-0 bg-transparent text-sm text-slate-800 focus:ring-0" @change="changeFloor($event.target.value)">
                            <option v-for="floor in floors" :key="floor.id" :value="floor.id">
                                {{ floor.name }}
                            </option>
                        </select>
                    </div>

                    <form class="flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2" @submit.prevent="onSearch">
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

                    <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Navigate
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <div class="grid gap-6 xl:grid-cols-[1.6fr_0.8fr]">
                <section class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="mb-3 flex items-center justify-between px-2">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Current location</p>
                            <h2 class="text-lg font-semibold text-slate-900">{{ selectedBuilding?.name || 'Building' }}</h2>
                        </div>
                        <div class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                            {{ selectedFloor?.name || 'Floor' }}
                        </div>
                    </div>
                    <MapView :geojson="mapGeojson" :selected-place="activePlace" @place-selected="selectPlace" />
                </section>

                <aside class="space-y-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Selected destination</p>
                        <div v-if="activePlace" class="mt-3">
                            <h3 class="text-xl font-semibold text-slate-900">{{ activePlace.name }}</h3>
                            <p class="mt-1 text-sm capitalize text-slate-500">{{ activePlace.type || 'facility' }}</p>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                {{ activePlace.description || 'This destination is available on the current floor.' }}
                            </p>
                            <div class="mt-5 flex gap-2">
                                <button type="button" class="flex-1 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                                    View details
                                </button>
                                <button type="button" class="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" disabled>
                                    Navigate
                                </button>
                            </div>
                        </div>
                        <div v-else class="mt-3 text-sm text-slate-500">
                            Select a destination on the map or in the results list.
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-3 flex items-center justify-between">
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
            </div>
        </main>
    </div>
</template>
