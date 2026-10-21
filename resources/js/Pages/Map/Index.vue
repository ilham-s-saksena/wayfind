<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MapView from '@/Components/Map/MapView.vue';
import MapIcon from '@/Components/Map/MapIcon.vue';
import NavigationSheet from '@/Components/Map/NavigationSheet.vue';
import { coordinatesFromPlaceFeature, distanceBetweenPoints, resolveGraphRoute, routeLength, splitRouteByUserPosition } from '@/lib/navigationRouting';
import { confirmArrival, formatDistance, NAVIGATION_SETTINGS } from '@/lib/navigationGuidance';

const props = defineProps({
    buildings: { type: Array, default: () => [] },
    floors: { type: Array, default: () => [] },
    places: { type: Array, default: () => [] },
    selectedBuildingId: { type: String, default: null },
    selectedFloorId: { type: String, default: null },
    selectedPlace: { type: Object, default: null },
    search: { type: String, default: '' },
    mapGeojson: { type: Object, default: () => ({ type: 'FeatureCollection', features: [] }) },
    navigationGraph: { type: Object, default: () => ({ nodes: [], edges: [] }) },
    backgroundImage: { type: String, default: '' },
    backgroundVisible: { type: Boolean, default: true },
    backgroundOpacity: { type: Number, default: 0.5 },
});

const mode = ref('discover');
const presentation = ref('half');
const activePlace = ref(props.selectedPlace || props.places[0] || null);
const searchTerm = ref(props.search);
const category = ref('');
const highlightedResult = ref(-1);
const searchInput = ref(null);
const titleRef = ref(null);
const mapViewRef = ref(null);
const contextOpen = ref(false);
const loadingContext = ref(false);
const contextError = ref('');
const desktop = ref(typeof window !== 'undefined' && window.innerWidth >= 768);
const sheetHeight = ref(0);
const currentLocation = ref(null);
const geolocationStatus = ref('idle');
const pendingStart = ref(false);
let pendingLocate = false;
let cameraTimer = null;
const following = ref(false);
const routeFeedback = ref('');
const routeState = ref({ fullPath: [], completedPath: [], remainingPath: [], progress: 0 });
let geolocationWatcherId = null;
const arrivalState = ref(null);
let lastReroute = 0;
let lastReliableLocation = null;
let media = null;
let requestGeneration = 0;
let searchCancelToken = null;

const isNavigationActive = computed(() => mode.value === 'navigating');
const selectedBuilding = computed(() => props.buildings.find(item => item.id === props.selectedBuildingId) || props.buildings[0]);
const selectedFloor = computed(() => props.floors.find(item => item.id === props.selectedFloorId) || props.floors[0]);
const destinationContext = computed(() => [activePlace.value?.floor?.building?.name || selectedBuilding.value?.name, activePlace.value?.floor?.name || selectedFloor.value?.name].filter(Boolean).join(' · '));
const contextLabel = computed(() => [selectedBuilding.value?.name, selectedFloor.value?.name].filter(Boolean).join(' · ') || 'Peta kompleks');
const typeLabel = (type) => ({ room: 'Ruangan', facility: 'Fasilitas', office: 'Kantor', building: 'Gedung', toilet: 'Toilet', parking: 'Parkir', entrance: 'Pintu masuk' })[type] || type || 'Tempat';
const categories = computed(() => [...new Set(props.places.map(place => place.type).filter(Boolean))]);
const results = computed(() => {
    const query = searchTerm.value.trim().toLocaleLowerCase('id-ID');
    const places = props.places.filter(place => !category.value || place.type === category.value);
    if (!query) return places;
    const rank = (item) => {
        const name = item.name?.toLocaleLowerCase('id-ID') || '';
        if (name.startsWith(query)) return 0;
        if (name.includes(query)) return 1;
        if (item.slug?.toLocaleLowerCase('id-ID').includes(query)) return 2;
        if (item.description?.toLocaleLowerCase('id-ID').includes(query)) return 3;
        return typeLabel(item.type).toLocaleLowerCase('id-ID').includes(query) ? 4 : Infinity;
    };
    const buildings = category.value ? [] : props.buildings
        .filter(building => building.name?.toLocaleLowerCase('id-ID').includes(query))
        .map(building => ({ ...building, resultKind: 'building', type: 'building' }));
    return [...buildings, ...places].filter(item => Number.isFinite(rank(item))).sort((first, second) => rank(first) - rank(second));
});
const remainingDistance = computed(() => routeLength(routeState.value.remainingPath));
const walkingMinutes = computed(() => Math.max(1, Math.ceil(remainingDistance.value / NAVIGATION_SETTINGS.walkingSpeed / 60)));
const mapInsets = computed(() => desktop.value
    ? { top: 48, right: 84, bottom: 36, left: 436 }
    : { top: 100, right: 24, bottom: Math.min(sheetHeight.value + 24, window.innerHeight - 200), left: 24 });
const sheetCollapsed = computed(() => !desktop.value && presentation.value === 'collapsed');
const controlsStyle = computed(() => desktop.value ? {} : { bottom: `${sheetHeight.value + 16}px` });
const hasRoute = computed(() => routeState.value.fullPath.length > 1);
const canLocate = computed(() => !['requesting'].includes(geolocationStatus.value));
const locationMessage = computed(() => ({
    requesting: 'Mencari lokasi Anda. Tunggu sebentar…',
    denied: 'Izin lokasi belum diberikan. Izinkan lokasi di pengaturan browser, lalu coba lagi.',
    unavailable: 'Lokasi belum tersedia. Coba dari area yang lebih terbuka.',
    timeout: 'Pencarian lokasi terlalu lama. Silakan coba lagi.',
    unsupported: 'Browser ini belum mendukung lokasi. Gunakan browser yang mendukung GPS.',
    inaccurate: `Lokasi belum cukup akurat${currentLocation.value?.accuracy ? ` (±${Math.round(currentLocation.value.accuracy)} m)` : ''}. Coba bergerak ke area terbuka.`,
    error: 'Lokasi belum dapat ditemukan. Silakan coba lagi.',
})[geolocationStatus.value] || '');
const statusMessage = computed(() => locationMessage.value || routeFeedback.value);
const accessIssue = computed(() => {
    if (!activePlace.value) return '';
    if (activePlace.value.floor_id && activePlace.value.floor_id !== props.selectedFloorId) return 'Tujuan berada di lantai lain. Pilih lantai tujuan untuk melihat rutenya.';
    if (!getPlaceCoordinates(activePlace.value)) return 'Akses masuk tujuan ini belum dipetakan. Silakan hubungi petugas untuk bantuan arah.';
    if (!props.navigationGraph?.edges?.length) return 'Rute pada lantai ini belum tersedia. Silakan hubungi petugas.';
    return '';
});
const primaryLabel = computed(() => pendingStart.value ? 'Mencari lokasi…' : geolocationStatus.value === 'inaccurate' ? 'Perbarui lokasi' : 'Arahkan Saya');

function getPlaceCoordinates(place) {
    if (place?.access_node_id) {
        const node = props.navigationGraph?.nodes?.find(item => String(item.id) === String(place.access_node_id));
        return node && [node.x, node.y].every(value => Number.isFinite(Number(value))) ? [Number(node.x), Number(node.y)] : null;
    }
    const feature = props.mapGeojson?.features?.find(item => String(item.properties?.id) === String(place?.id)
        && item.properties?.kind === 'place' && item.geometry?.type === 'Point');
    return coordinatesFromPlaceFeature(feature);
}
function clearRoute() {
    routeState.value = { fullPath: [], completedPath: [], remainingPath: [], progress: 0 };
    arrivalState.value = null;
}
function calculateRoute() {
    clearRoute();
    routeFeedback.value = '';
    if (accessIssue.value || !currentLocation.value || geolocationStatus.value !== 'ready') return false;
    const destination = getPlaceCoordinates(activePlace.value);
    const result = resolveGraphRoute(props.navigationGraph, [currentLocation.value.longitude, currentLocation.value.latitude], destination);
    if (!result.reachable || result.fullPath.length < 2) {
        routeFeedback.value = result.reason === 'disconnected'
            ? 'Belum ada jalur yang terhubung ke tujuan ini. Silakan hubungi petugas.'
            : 'Lokasi Anda atau akses tujuan berada di luar jangkauan jalur yang dipetakan. Dekati area kompleks, lalu coba lagi.';
        return false;
    }
    routeState.value = { fullPath: result.fullPath, completedPath: [], remainingPath: [...result.fullPath], progress: 0 };
    return true;
}
function beginNavigation() {
    pendingStart.value = false;
    if (!calculateRoute()) { mode.value = 'destination'; return; }
    lastReliableLocation = currentLocation.value;
    mode.value = 'navigating';
    following.value = true;
    presentation.value = 'half';
    scheduleCamera('frameRoute');
}
function startNavigation() {
    if (!activePlace.value || accessIssue.value) return;
    const fresh = currentLocation.value && Date.now() - currentLocation.value.timestamp <= NAVIGATION_SETTINGS.maximumPositionAge;
    if (geolocationStatus.value === 'ready' && fresh) beginNavigation();
    else { pendingStart.value = true; requestCurrentLocation(); }
}
function stopNavigation() {
    pendingStart.value = false;
    following.value = false;
    mode.value = 'destination';
    calculateRoute();
}
function selectResult(result) {
    if (result.resultKind !== 'building') { selectPlace(result); return; }
    if (result.id !== props.selectedBuildingId) { changeContext(result.id, null); return; }
    searchTerm.value = '';
    category.value = '';
    searchInput.value?.blur();
    presentation.value = 'half';
    scheduleCamera('fitMap');
}
function selectPlace(place) {
    const canonical = props.places.find(item => String(item.id) === String(place?.id));
    if (!canonical) return;
    activePlace.value = canonical;
    mode.value = 'destination';
    pendingStart.value = false;
    following.value = false;
    presentation.value = 'half';
    highlightedResult.value = -1;
    contextOpen.value = false;
    calculateRoute();
    scheduleCamera('focusSelectedPlace');
    nextTick(() => titleRef.value?.focus({ preventScroll: true }));
}
function scheduleCamera(method, initialOverview = false) {
    clearTimeout(cameraTimer);
    cameraTimer = setTimeout(() => {
        if (initialOverview && currentLocation.value) return;
        mapViewRef.value?.[method]?.({ initialOverview });
    }, desktop.value ? 0 : 260);
}
function exploreMap() {
    if (desktop.value) mapViewRef.value?.fitMap();
    else presentation.value = presentation.value === 'collapsed' ? 'half' : 'collapsed';
}
function onSheetResize(height) {
    const initial = sheetHeight.value === 0 && height > 0;
    sheetHeight.value = height;
    if (initial && mode.value === 'discover') scheduleCamera('fitMap', true);
}
function onMapInteraction() {
    following.value = false;
    clearTimeout(cameraTimer);
}
function openSearch() {
    mode.value = 'discover';
    pendingStart.value = false;
    following.value = false;
    searchTerm.value = '';
    presentation.value = 'half';
    nextTick(() => searchInput.value?.focus({ preventScroll: true }));
}
function onSearchKeydown(event) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (!results.value.length) return;
        const direction = event.key === 'ArrowDown' ? 1 : -1;
        highlightedResult.value = highlightedResult.value < 0
            ? (direction > 0 ? 0 : results.value.length - 1)
            : (highlightedResult.value + direction + results.value.length) % results.value.length;
        nextTick(() => document.getElementById(`destination-result-${highlightedResult.value}`)?.scrollIntoView({ block: 'nearest' }));
    } else if (event.key === 'Enter') {
        event.preventDefault();
        if (results.value.length) selectResult(results.value[Math.max(0, highlightedResult.value)]);
    } else if (event.key === 'Escape') {
        searchInput.value?.blur();
        presentation.value = 'half';
    }
}
function requestCurrentLocation() {
    if (!navigator.geolocation) { geolocationStatus.value = 'unsupported'; pendingStart.value = false; return; }
    if (geolocationWatcherId !== null) navigator.geolocation.clearWatch(geolocationWatcherId);
    const generation = ++requestGeneration;
    geolocationStatus.value = 'requesting';
    geolocationWatcherId = navigator.geolocation.watchPosition(position => {
        if (generation !== requestGeneration) return;
        const { latitude, longitude, accuracy } = position.coords;
        if (![latitude, longitude, accuracy, position.timestamp].every(Number.isFinite) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180 || accuracy <= 0
            || Date.now() - position.timestamp > NAVIGATION_SETTINGS.maximumPositionAge || position.timestamp > Date.now() + 1000) {
            geolocationStatus.value = 'unavailable'; pendingStart.value = false; arrivalState.value = null; return;
        }
        currentLocation.value = { latitude, longitude, accuracy, timestamp: position.timestamp };
        geolocationStatus.value = accuracy <= NAVIGATION_SETTINGS.maximumAccuracy ? 'ready' : 'inaccurate';
        if (geolocationStatus.value === 'inaccurate') pendingStart.value = false;
        if (pendingLocate && geolocationStatus.value === 'ready') { pendingLocate = false; following.value = true; nextTick(() => mapViewRef.value?.centerOnUserLocation()); }
        if (pendingStart.value && geolocationStatus.value === 'ready') beginNavigation();
        else if (!isNavigationActive.value && mode.value !== 'arrived') calculateRoute();
    }, error => {
        if (generation !== requestGeneration) return;
        geolocationStatus.value = ({ 1: 'denied', 2: 'unavailable', 3: 'timeout' })[error.code] || 'error';
        pendingStart.value = false;
        arrivalState.value = null;
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 5000 });
}
function recenter() {
    if (!currentLocation.value || geolocationStatus.value !== 'ready' || Date.now() - currentLocation.value.timestamp > NAVIGATION_SETTINGS.maximumPositionAge) { pendingLocate = true; requestCurrentLocation(); return; }
    following.value = true;
    mapViewRef.value?.centerOnUserLocation();
}
function changeContext(buildingId, floorId) {
    if (loadingContext.value) return;
    loadingContext.value = true;
    contextError.value = '';
    pendingStart.value = false;
    following.value = false;
    const previousId = activePlace.value?.id;
    router.get(route('map'), { building: buildingId, floor: floorId }, {
        preserveState: true, preserveScroll: true, replace: true,
        onSuccess: page => {
            activePlace.value = page.props.places.find(place => place.id === previousId) || page.props.selectedPlace || page.props.places[0] || null;
            mode.value = 'discover';
            searchTerm.value = '';
            category.value = '';
            contextOpen.value = false;
            clearRoute();
        },
        onError: () => { contextError.value = 'Peta belum dapat dimuat. Silakan coba lagi.'; },
        onFinish: () => { loadingContext.value = false; },
    });
}
watch([searchTerm, category], () => { highlightedResult.value = -1; });
watch(() => props.places, places => {
    activePlace.value = places.find(place => place.id === activePlace.value?.id) || props.selectedPlace || places[0] || null;
    clearRoute();
});
watch(currentLocation, (location) => {
    if (!isNavigationActive.value || !location || location.accuracy > NAVIGATION_SETTINGS.maximumAccuracy) { arrivalState.value = null; return; }
    const movement = lastReliableLocation ? distanceBetweenPoints([lastReliableLocation.longitude, lastReliableLocation.latitude], [location.longitude, location.latitude]) : 0;
    lastReliableLocation = location;
    const split = splitRouteByUserPosition(routeState.value.fullPath, [location.longitude, location.latitude], routeState.value.progress, { maximumAdvanceMeters: Math.max(20, movement * 1.5 + location.accuracy) });
    if (split.deviated) {
        arrivalState.value = null;
        routeFeedback.value = 'Anda keluar dari rute. Mencari jalur dari lokasi terbaru…';
        if (Date.now() - lastReroute > 8000) {
            lastReroute = Date.now();
            if (calculateRoute()) routeFeedback.value = 'Rute diperbarui dari lokasi Anda. Ikuti jalur biru.';
            else { mode.value = 'destination'; following.value = false; }
        }
        return;
    }
    routeFeedback.value = '';
    routeState.value = { ...routeState.value, completedPath: split.completedPath, remainingPath: split.remainingPath, progress: split.progress };
    arrivalState.value = confirmArrival(arrivalState.value, {
        remainingDistance: routeLength(split.remainingPath),
        destinationDistance: distanceBetweenPoints([location.longitude, location.latitude], getPlaceCoordinates(activePlace.value)),
        progress: split.progress, accuracy: location.accuracy, timestamp: location.timestamp,
    });
    if (arrivalState.value.arrived) { mode.value = 'arrived'; following.value = false; presentation.value = 'half'; }
});
const onBreakpointChange = () => {
    desktop.value = media.matches;
    scheduleCamera(mode.value === 'discover' ? 'fitMap' : isNavigationActive.value ? 'frameRoute' : 'focusSelectedPlace');
};
onMounted(() => {
    media = window.matchMedia('(min-width: 768px)');
    media.addEventListener('change', onBreakpointChange);
    requestCurrentLocation();
    if (props.search) {
        // Load the complete floor once so clearing a server-side query restores all destinations.
        router.get(route('map'), { building: props.selectedBuildingId, floor: props.selectedFloorId }, { preserveState: true, replace: true, onCancelToken: token => { searchCancelToken = token; } });
    }
});
onBeforeUnmount(() => {
    ++requestGeneration;
    if (geolocationWatcherId !== null) navigator.geolocation?.clearWatch(geolocationWatcherId);
    media?.removeEventListener('change', onBreakpointChange);
    searchCancelToken?.cancel();
    clearTimeout(cameraTimer);
});
</script>

<template>
    <div lang="id" class="wayfind-public relative isolate h-screen w-full overflow-hidden bg-[#eeeee9] text-[#202124]">
        <Head title="Wayfind · TVRI Kalimantan Barat" />
        <div class="absolute inset-0 z-0">
            <MapView ref="mapViewRef" mode="geo" :geojson="mapGeojson" :selected-place="activePlace" :user-location="currentLocation" :route-geometry="routeState.fullPath" :completed-route="routeState.completedPath" :remaining-route="routeState.remainingPath" :route-visible="mode !== 'discover' && hasRoute" :navigation-active="isNavigationActive" :follow-user="following && geolocationStatus === 'ready'" :map-insets="mapInsets" :bottom-overlay-height="sheetHeight" :background-image="backgroundImage || ''" :background-visible="backgroundVisible" :background-opacity="backgroundOpacity" @place-selected="selectPlace" @manual-interaction="onMapInteraction" />
        </div>

        <div class="mobile-brand absolute left-4 right-4 top-4 z-20 flex items-center justify-between gap-3 md:hidden">
            <div class="flex items-center gap-2 rounded-lg border border-white/80 bg-white px-3 py-2 shadow-sm">
                <MapIcon name="navigation" class="h-5 w-5 text-blue-600" />
                <span class="text-sm font-bold tracking-tight">wayfind<span class="text-blue-600">.</span></span>
            </div>
            <button type="button" class="context-button max-w-[58%]" :aria-expanded="contextOpen" @click="contextOpen = !contextOpen; presentation = 'half'">
                <MapIcon name="layers" class="h-4 w-4 shrink-0 text-slate-500" /><span class="truncate">{{ contextLabel }}</span><MapIcon name="down" class="h-3.5 w-3.5 shrink-0" />
            </button>
        </div>

        <NavigationSheet v-model:presentation="presentation" :label="mode === 'discover' ? 'Pencarian tujuan' : 'Informasi tujuan dan navigasi'" @resize="onSheetResize">
            <template #header>
                <div class="mb-5 hidden items-center justify-between md:flex">
                    <div class="flex items-center gap-2">
                        <MapIcon name="navigation" class="h-5 w-5 text-blue-600" />
                        <span class="text-xl font-bold tracking-tight">wayfind<span class="text-blue-600">.</span></span>
                    </div>
                    <span class="text-right text-[10px] font-semibold leading-4 tracking-[0.08em] text-slate-500">TVRI<br />KALIMANTAN BARAT</span>
                </div>
                <template v-if="mode === 'discover'">
                    <p v-show="!sheetCollapsed" class="mb-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-blue-600">Panduan pengunjung</p>
                    <h1 class="font-semibold leading-tight tracking-[-0.035em]" :class="sheetCollapsed ? 'text-xl' : 'text-[26px]'">Mau ke mana hari ini?</h1>
                    <p v-show="!sheetCollapsed" class="mt-2 text-[13px] leading-5 text-slate-500">Temukan gedung, ruangan, atau fasilitas<br class="hidden md:block" /> yang ingin Anda tuju.</p>
                    <div :class="sheetCollapsed ? 'mt-3' : 'mt-5'" class="search-field flex h-12 items-center gap-3 rounded-xl border border-slate-200 bg-[#f7f8fa] px-3 focus-within:border-blue-500 focus-within:bg-white focus-within:ring-2 focus-within:ring-blue-100">
                        <MapIcon name="search" class="h-[18px] w-[18px] shrink-0 text-slate-500" />
                        <input ref="searchInput" v-model="searchTerm" type="search" role="combobox" aria-label="Cari gedung, ruangan, atau fasilitas" aria-autocomplete="list" aria-controls="destination-results" :aria-expanded="mode === 'discover'" :aria-activedescendant="highlightedResult >= 0 ? `destination-result-${highlightedResult}` : undefined" placeholder="Cari gedung, ruangan, fasilitas…" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm placeholder:text-slate-400 focus:ring-0" autocomplete="off" @focus="presentation = 'expanded'" @keydown="onSearchKeydown" />
                        <button v-if="searchTerm" type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100" aria-label="Hapus pencarian" @click="searchTerm = ''; searchInput?.focus()"><MapIcon name="close" class="h-4 w-4" /></button>
                    </div>
                </template>
                <template v-else>
                    <div v-show="!sheetCollapsed" class="mb-4 flex items-center justify-between">
                        <button type="button" class="inline-flex min-h-[32px] items-center gap-2 text-xs font-medium text-slate-500 hover:text-blue-600" @click="openSearch"><MapIcon name="back" class="h-4 w-4" />{{ isNavigationActive ? 'Ganti tujuan' : 'Cari tujuan lain' }}</button>
                        <span v-if="isNavigationActive" class="inline-flex items-center gap-1.5 text-[11px] font-medium text-blue-600"><span class="h-1.5 w-1.5 rounded-full bg-blue-600"></span>Navigasi aktif</span>
                        <span v-else-if="mode === 'arrived'" class="text-xs font-medium text-emerald-700">Selesai</span>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="mode === 'arrived' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-600'"><MapIcon :name="mode === 'arrived' ? 'check' : 'pin'" /></div>
                        <div class="min-w-0">
                            <p v-if="mode === 'arrived'" class="mb-1 text-xs font-medium text-emerald-700">Anda sudah sampai</p>
                            <h1 ref="titleRef" tabindex="-1" class="text-[22px] font-semibold leading-7 tracking-[-0.025em] outline-none">{{ activePlace?.name }}</h1>
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ sheetCollapsed && isNavigationActive ? `${formatDistance(remainingDistance)} · ${walkingMinutes} mnt berjalan kaki` : destinationContext }}</p>
                        </div>
                    </div>
                </template>
            </template>

            <template v-if="contextOpen">
                <div class="mb-4 border-y border-slate-100 py-4">
                    <div class="mb-3 flex items-center justify-between"><h2 class="text-sm font-semibold">Lokasi yang ditampilkan</h2><button type="button" class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-slate-100" aria-label="Tutup pilihan lokasi" @click="contextOpen = false"><MapIcon name="close" class="h-4 w-4" /></button></div>
                    <label for="building-picker" class="mb-1.5 block text-xs text-slate-500">Gedung</label>
                    <select id="building-picker" :value="selectedBuildingId || ''" :disabled="loadingContext" class="context-select" @change="changeContext($event.target.value, null)"><option v-for="building in buildings" :key="building.id" :value="building.id">{{ building.name }}</option></select>
                    <template v-if="floors.length > 1"><label for="floor-picker" class="mb-1.5 mt-3 block text-xs text-slate-500">Lantai</label><select id="floor-picker" :value="selectedFloorId || ''" :disabled="loadingContext" class="context-select" @change="changeContext(selectedBuildingId, $event.target.value)"><option v-for="floor in floors" :key="floor.id" :value="floor.id">{{ floor.name }}</option></select></template>
                    <p v-if="loadingContext || contextError" class="mt-3 text-xs text-slate-500" role="status">{{ contextError || 'Memuat peta…' }}</p>
                </div>
            </template>

            <template v-if="mode === 'discover'">
                <button type="button" class="mb-5 hidden w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-xs md:flex" :aria-expanded="contextOpen" @click="contextOpen = !contextOpen"><span class="flex items-center gap-2"><MapIcon name="building" class="h-4 w-4 text-slate-500" />{{ contextLabel }}</span><MapIcon name="down" class="h-3.5 w-3.5 text-slate-500" /></button>
                <div v-if="categories.length > 1" class="mb-5 flex flex-wrap gap-2" aria-label="Kategori tujuan"><button type="button" class="category-chip" :class="{ selected: !category }" :aria-pressed="!category" @click="category = ''">Semua</button><button v-for="type in categories" :key="type" type="button" class="category-chip" :class="{ selected: category === type }" :aria-pressed="category === type" @click="category = type">{{ typeLabel(type) }}</button></div>
                <div class="mb-2 flex items-center justify-between"><h2 class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{{ searchTerm ? 'Hasil pencarian' : 'Tujuan di sekitar' }}</h2><span class="text-[11px] text-slate-400" aria-live="polite">{{ results.length }} tempat</span></div>
                <ul id="destination-results" role="listbox" aria-label="Hasil pencarian tujuan" class="-mx-2">
                    <li v-for="(place, index) in results" :id="`destination-result-${index}`" :key="place.id" role="option" :aria-selected="highlightedResult === index">
                        <button type="button" class="destination-row group flex w-full items-center gap-3 rounded-lg px-2 py-3.5 text-left" :class="highlightedResult === index ? 'bg-blue-50' : 'hover:bg-slate-50'" @click="selectResult(place)">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#f3f5f7] text-slate-500 group-hover:bg-blue-50 group-hover:text-blue-600"><MapIcon :name="place.resultKind === 'building' ? 'building' : 'pin'" class="h-[18px] w-[18px]" /></span>
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-slate-800">{{ place.name }}</span><span class="mt-0.5 block truncate text-xs text-slate-500">{{ place.resultKind === 'building' ? `Gedung · ${place.floors?.length || 0} lantai · Lihat tujuan` : `${typeLabel(place.type)} · ${place.floor?.name || selectedFloor?.name || ''}` }}</span></span>
                            <MapIcon name="chevron" class="h-4 w-4 text-slate-400" />
                        </button>
                    </li>
                </ul>
                <div v-if="!results.length" class="py-8 text-center" role="status"><MapIcon name="search" class="mx-auto mb-3 h-6 w-6 text-slate-400" /><p class="text-sm font-medium">{{ searchTerm ? 'Tujuan belum ditemukan' : 'Belum ada tujuan di lantai ini' }}</p><p class="mt-1 text-xs leading-5 text-slate-500">{{ searchTerm ? 'Coba nama lain atau periksa ejaan pencarian.' : 'Pilih gedung atau lantai lain untuk menjelajah.' }}</p><button v-if="searchTerm" type="button" class="mt-3 text-xs font-medium text-blue-600" @click="searchTerm = ''; category = ''">Lihat semua tujuan</button></div>
                <div class="mt-4 flex gap-2 border-t border-slate-100 pt-4 text-[11px] leading-5 text-slate-500"><MapIcon name="info" class="mt-0.5 h-3.5 w-3.5 shrink-0" /><p>Pilih tujuan di daftar atau sentuh tempat pada peta.<br />Lokasi Anda hanya digunakan untuk panduan arah.</p></div>
            </template>

            <template v-else>
                <div v-if="mode === 'arrived'" class="mb-5 rounded-xl bg-emerald-50 px-4 py-4"><p class="text-sm font-medium text-emerald-900">Tujuan ada di dekat Anda.</p><p class="mt-1 text-xs leading-5 text-emerald-800">Perhatikan pintu masuk dan penanda lokasi untuk melanjutkan ke {{ activePlace?.name }}.</p></div>
                <div v-if="hasRoute && mode !== 'arrived'" class="route-metrics mb-5 flex items-center gap-5 border-y border-slate-100 py-4">
                    <div><p class="flex items-baseline gap-1.5"><span class="text-[28px] font-semibold leading-none tracking-[-0.04em] text-blue-600">{{ walkingMinutes }}</span><span class="text-sm font-medium text-slate-600">mnt</span></p><p class="mt-2 text-[11px] text-slate-500">Perkiraan berjalan kaki</p></div><div class="h-9 w-px bg-slate-200"></div><div><p class="text-lg font-semibold tracking-tight">{{ formatDistance(remainingDistance) }}</p><p class="mt-1 text-[11px] text-slate-500">{{ isNavigationActive ? 'Jarak tersisa' : 'Panjang rute' }}</p></div><MapIcon name="walk" class="ml-auto h-6 w-6 text-slate-400" />
                </div>
                <div v-if="isNavigationActive" class="mb-5">
                    <p class="flex items-center gap-2 text-sm font-medium"><MapIcon name="route" class="h-4 w-4 text-blue-600" />{{ arrivalState?.count ? 'Memastikan lokasi tujuan…' : 'Ikuti jalur biru pada peta' }}</p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">Jalur abu-abu menunjukkan bagian yang telah dilalui.</p>
                    <div class="mt-4 h-1 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="Math.round(routeState.progress * 100)" aria-valuemin="0" aria-valuemax="100" aria-label="Kemajuan perjalanan"><div class="h-full rounded-full bg-blue-600 transition-[width] duration-300 motion-reduce:transition-none" :style="{ width: `${routeState.progress * 100}%` }"></div></div>
                </div>
                <div v-if="activePlace?.description && !isNavigationActive" class="mb-5"><h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Tentang tujuan</h2><p class="whitespace-pre-line text-sm leading-6 text-slate-600">{{ activePlace.description }}</p></div>
                <div v-if="!isNavigationActive && mode !== 'arrived'" class="flex gap-3 text-xs leading-5 text-slate-500"><MapIcon name="walk" class="mt-0.5 h-4 w-4 shrink-0" /><p>{{ hasRoute ? 'Rute berjalan kaki mengikuti jalur yang telah dipetakan di kompleks TVRI.' : 'Aktifkan lokasi Anda untuk melihat rute berjalan kaki menuju tujuan.' }}</p></div>
            </template>

            <template #footer>
                <template v-if="mode === 'discover'">
                    <div class="flex items-center justify-between text-[11px] text-slate-500"><span class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>TVRI Kalimantan Barat</span><button type="button" class="min-h-[28px] font-medium text-blue-600 hover:text-blue-800" @click="exploreMap">{{ desktop ? 'Lihat area' : presentation === 'collapsed' ? 'Lihat tujuan' : 'Jelajahi peta' }}</button></div>
                </template>
                <template v-else>
                    <div v-if="!sheetCollapsed && (accessIssue || statusMessage)" class="mb-3 flex items-start gap-2 rounded-lg px-3 py-2.5 text-xs leading-5" :class="geolocationStatus === 'requesting' ? 'bg-blue-50 text-blue-800' : 'bg-amber-50 text-amber-900'" role="status" aria-live="polite"><span v-if="geolocationStatus === 'requesting'" class="loading-spinner mt-0.5 h-4 w-4 shrink-0"></span><MapIcon v-else name="info" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ accessIssue || statusMessage }}</span></div>
                    <button v-if="mode === 'arrived'" type="button" class="primary-action" @click="openSearch"><MapIcon name="search" class="h-[18px] w-[18px]" />Cari tujuan lain</button>
                    <button v-else-if="isNavigationActive" type="button" class="secondary-action" @click="stopNavigation"><span class="h-2.5 w-2.5 rounded-sm bg-slate-600"></span>Akhiri Navigasi</button>
                    <button v-else type="button" class="primary-action" :disabled="!!accessIssue || pendingStart" @click="startNavigation"><span v-if="pendingStart" class="loading-spinner h-[18px] w-[18px]"></span><MapIcon v-else name="navigation" class="h-[18px] w-[18px]" />{{ primaryLabel }}</button>
                    <p v-if="!sheetCollapsed && mode === 'destination' && !accessIssue && !statusMessage" class="mt-2 text-center text-[10px] text-slate-400">{{ hasRoute ? 'Estimasi waktu dapat berubah sesuai kondisi perjalanan.' : 'Izinkan akses lokasi saat diminta oleh browser.' }}</p>
                </template>
            </template>
        </NavigationSheet>

        <div v-show="desktop || presentation !== 'expanded'" class="map-controls absolute right-4 z-20 flex flex-col items-end gap-3 md:bottom-8 md:right-6" :style="controlsStyle">
            <div v-if="floors.length > 1" class="floor-control flex max-h-44 flex-col overflow-auto rounded-xl border border-slate-200 bg-white p-1 shadow-sm" aria-label="Pilihan lantai"><button v-for="floor in floors" :key="floor.id" type="button" class="min-h-[40px] min-w-[40px] rounded-lg px-2 text-xs font-medium" :class="floor.id === selectedFloorId ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-50'" :aria-pressed="floor.id === selectedFloorId" :disabled="loadingContext" @click="changeContext(selectedBuildingId, floor.id)">{{ floor.name }}</button></div>
            <button v-if="isNavigationActive && !following" type="button" class="flex h-11 items-center gap-2 rounded-xl border border-blue-100 bg-white px-3 text-xs font-semibold text-blue-600 shadow-sm" @click="recenter"><MapIcon name="navigation" class="h-4 w-4" />Pusatkan</button>
            <button v-else type="button" class="map-control" :class="{ 'text-blue-600': following && currentLocation }" :disabled="!canLocate" :aria-label="currentLocation ? 'Pusatkan ke lokasi saya' : 'Temukan lokasi saya'" @click="recenter"><span v-if="!canLocate" class="loading-spinner h-5 w-5"></span><MapIcon v-else name="locate" /></button>
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"><button type="button" class="map-control !rounded-none !border-0 !shadow-none" aria-label="Perbesar peta" @click="mapViewRef?.zoomIn()"><MapIcon name="plus" /></button><div class="mx-2 h-px bg-slate-100"></div><button type="button" class="map-control !rounded-none !border-0 !shadow-none" aria-label="Perkecil peta" @click="mapViewRef?.zoomOut()"><MapIcon name="minus" /></button></div>
            <button v-if="hasRoute && mode !== 'discover'" type="button" class="map-control" aria-label="Tampilkan seluruh rute" @click="following = false; mapViewRef?.frameRoute()"><MapIcon name="expand" /></button>
        </div>
        <div v-if="mode === 'discover' && locationMessage" class="location-notice absolute right-4 top-20 z-20 max-w-[270px] rounded-xl border border-slate-200 bg-white p-3 text-xs leading-5 text-slate-600 shadow-sm md:right-6 md:top-6" role="status"><p>{{ locationMessage }}</p><button v-if="geolocationStatus !== 'requesting'" type="button" class="mt-2 font-semibold text-blue-600" @click="requestCurrentLocation">Coba lagi</button></div>
        <div class="pointer-events-none absolute right-5 top-7 z-10 hidden text-right md:block"><p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-600">Peta pengunjung</p><p class="mt-1 text-xs text-slate-500">{{ contextLabel }}</p></div>
    </div>
</template>

<style scoped>
.wayfind-public { height: 100dvh; font-family: 'Figtree', ui-sans-serif, system-ui, sans-serif; }
.wayfind-public :deep(button), .wayfind-public :deep(input), .wayfind-public :deep(select) { -webkit-tap-highlight-color: transparent; }
.wayfind-public :deep(button:focus-visible), .wayfind-public :deep(select:focus-visible) { outline: 2px solid #2563eb; outline-offset: 3px; }
.wayfind-public :deep(button) { transition: color 160ms, background-color 160ms, border-color 160ms; }
.wayfind-public :deep(button:disabled) { cursor: not-allowed; opacity: 0.5; }
.context-button { display: inline-flex; align-items: center; gap: 7px; min-height: 40px; padding: 8px 10px; border-radius: 9px; border: 1px solid #e5e7eb; background: white; font-size: 11px; font-weight: 500; box-shadow: 0 1px 2px #15233808; }
.context-select { width: 100%; min-height: 44px; border: 1px solid #e2e6eb; border-radius: 8px; font-size: 13px; padding: 8px 30px 8px 10px; }
.primary-action, .secondary-action { display: flex; align-items: center; justify-content: center; gap: 9px; width: 100%; min-height: 48px; border-radius: 10px; font-size: 14px; font-weight: 600; }
.primary-action { color: #fff; background: #2563eb; }
.primary-action:hover:not(:disabled) { background: #1d4ed8; }
.secondary-action { color: #475569; background: #f7f8fa; border: 1px solid #e2e6eb; }
.secondary-action:hover { background: #eef1f5; }
.category-chip { border: 1px solid #e5e7eb; border-radius: 7px; padding: 6px 11px; min-height: 32px; font-size: 12px; color: #667085; }
.category-chip.selected { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe; }
.map-control { width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e6eb; border-radius: 11px; background: #fff; color: #475569; box-shadow: 0 1px 3px #1523380d; }
.map-control:hover { background: #f8fafc; }
.map-controls { transition: bottom 220ms ease; }
.loading-spinner { display: inline-block; border-radius: 50%; border: 2px solid currentColor; border-right-color: transparent; animation: spin 850ms linear infinite; }
.search-field input::-webkit-search-cancel-button { display: none; }
.mobile-brand { top: max(16px, env(safe-area-inset-top)); }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .wayfind-public :deep(*), .map-controls { transition: none !important; animation-duration: 0s !important; } }
</style>
