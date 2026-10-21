<script setup>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    geojson: {
        type: Object,
        default: () => ({
            type: 'FeatureCollection',
            features: [],
        }),
    },
    selectedPlace: {
        type: Object,
        default: null,
    },
    userLocation: {
        type: Object,
        default: null,
    },
    mode: {
        type: String,
        default: 'geo',
    },
    routeGeometry: {
        type: Array,
        default: () => [],
    },
    completedRoute: {
        type: Array,
        default: () => [],
    },
    remainingRoute: {
        type: Array,
        default: () => [],
    },
    routeVisible: {
        type: Boolean,
        default: true,
    },
    bottomOverlayHeight: { type: Number, default: 0 },
    navigationActive: { type: Boolean, default: false },
    followUser: { type: Boolean, default: false },
    mapInsets: { type: Object, default: () => ({ top: 24, right: 24, bottom: 24, left: 24 }) },
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

const emit = defineEmits(['place-selected', 'manual-interaction']);
const mapContainer = ref(null);
let map = null;
let geoJsonLayer = null;
let userLocationMarker = null;
let routeBaseLayer = null;
let routeCompletedLayer = null;
let routeProgressLayer = null;
let floorPlanLayer = null;
let accuracyLayer = null;
let destinationMarker = null;
let overviewUntil = 0;
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const onManualInteraction = () => emit('manual-interaction');
const onTouchStart = (event) => { if (event.touches.length > 1) onManualInteraction(); };

const defaultData = () => ({
    type: 'FeatureCollection',
    features: [],
});

const isGeoMap = () => props.mode === 'geo';

const isValidUserLocation = (location) => (
    location
    && Number.isFinite(location.latitude)
    && Number.isFinite(location.longitude)
    && Math.abs(location.latitude) <= 90
    && Math.abs(location.longitude) <= 180
);

const isIndoorData = (data = props.geojson) => {
    if (!data || !Array.isArray(data.features)) {
        return false;
    }

    if (props.mode === 'indoor') {
        return true;
    }

    if (props.mode === 'geo') {
        return false;
    }

    return data.features.some((feature) => feature.properties?.kind === 'floor' || feature.properties?.kind === 'room' || feature.properties?.kind === 'navigation' || feature.properties?.type === 'room' || feature.properties?.type === 'navigation');
};

const featureStyle = (feature) => {
    const kind = feature?.properties?.kind ?? feature?.properties?.type ?? 'place';
    const selected = props.selectedPlace && String(feature?.properties?.id) === String(props.selectedPlace.id);
    if (kind === 'navigation') {
        return { color: '#8b97a5', weight: 2, opacity: props.navigationActive ? 0.18 : 0.38, interactive: false };
    }
    return {
        color: selected ? '#2563eb' : '#718297',
        weight: selected ? 3 : 1.5,
        opacity: props.selectedPlace && !selected ? 0.65 : 1,
        fillColor: selected ? '#3b82f6' : '#edf2f7',
        fillOpacity: selected ? 0.25 : 0.5,
        interactive: kind === 'place',
    };
};
const pointStyle = (feature) => ({
    ...featureStyle(feature), radius: String(feature?.properties?.id) === String(props.selectedPlace?.id) ? 7 : 5,
    fillColor: '#fff', fillOpacity: 1, weight: 2,
});
const cameraOptions = () => ({
    paddingTopLeft: [props.mapInsets.left, props.mapInsets.top],
    paddingBottomRight: [props.mapInsets.right, props.mapInsets.bottom],
    duration: reducedMotion() ? 0 : 0.65,
    animate: !reducedMotion(),
});
const focusLocation = (animate = true) => {
    if (!map || !isValidUserLocation(props.userLocation) || !isGeoMap()) return;
    const zoom = Math.max(map.getZoom(), 18);
    const offset = L.point((props.mapInsets.left - props.mapInsets.right) / 2, (props.mapInsets.top - props.mapInsets.bottom) / 2);
    const target = map.unproject(map.project([props.userLocation.latitude, props.userLocation.longitude], zoom).subtract(offset), zoom);
    map.setView(target, zoom, { animate: animate && !reducedMotion(), duration: 0.5 });
};
const centerOnUserLocation = () => { overviewUntil = 0; focusLocation(); };
const frameRoute = () => {
    if (!map || props.routeGeometry.length < 2) return;
    overviewUntil = Date.now() + 1600;
    map.flyToBounds(L.latLngBounds(props.routeGeometry.map(([lng, lat]) => [lat, lng])), { ...cameraOptions(), maxZoom: 19 });
};
const focusSelectedPlace = () => {
    if (!map || !geoJsonLayer || !props.selectedPlace) return;
    const selectedLayers = geoJsonLayer.getLayers().filter(layer => String(layer.feature?.properties?.id) === String(props.selectedPlace.id));
    const bounds = L.featureGroup(selectedLayers).getBounds();
    if (bounds.isValid()) map.flyToBounds(bounds, { ...cameraOptions(), maxZoom: 19.5 });
};
const renderDestination = () => {
    if (!map) return;
    const features = props.geojson?.features ?? [];
    const access = features.find(feature => String(feature.properties?.id) === String(props.selectedPlace?.id) && feature.properties?.kind === 'place-access');
    let point = access?.geometry?.coordinates;
    if (!point && props.selectedPlace) {
        const selected = geoJsonLayer?.getLayers().find(layer => String(layer.feature?.properties?.id) === String(props.selectedPlace.id));
        const center = selected?.getLatLng?.() ?? selected?.getBounds?.().getCenter();
        if (center) point = [center.lng, center.lat];
    }
    if (!point || !props.selectedPlace) {
        if (destinationMarker) map.removeLayer(destinationMarker);
        destinationMarker = null;
        return;
    }
    const latlng = [point[1], point[0]];
    if (destinationMarker) { destinationMarker.setLatLng(latlng); return; }
    destinationMarker = L.marker(latlng, {
        interactive: false, zIndexOffset: 800,
        icon: L.divIcon({ className: 'wayfind-destination', iconSize: [32, 40], iconAnchor: [16, 38], html: '<svg viewBox="0 0 32 40" width="32" height="40" aria-hidden="true"><path d="M16 38S2 24 2 16a14 14 0 1 1 28 0c0 8-14 22-14 22Z" fill="#2563eb" stroke="white" stroke-width="2.5"/><circle cx="16" cy="16" r="4.5" fill="white"/></svg>' }),
    }).addTo(map);
};
const renderUserLocation = () => {
    if (!map || !isGeoMap()) return;
    if (!isValidUserLocation(props.userLocation)) {
        if (userLocationMarker) map.removeLayer(userLocationMarker);
        if (accuracyLayer) map.removeLayer(accuracyLayer);
        userLocationMarker = null;
        accuracyLayer = null;
        return;
    }
    const latlng = [props.userLocation.latitude, props.userLocation.longitude];
    const accuracy = Number(props.userLocation.accuracy);
    if (Number.isFinite(accuracy) && accuracy > 0 && accuracy <= 500) {
        if (accuracyLayer) accuracyLayer.setLatLng(latlng).setRadius(accuracy);
        else accuracyLayer = L.circle(latlng, { radius: accuracy, pane: 'accuracy', color: '#3b82f6', weight: 1, opacity: 0.2, fillOpacity: 0.07, interactive: false }).addTo(map);
    } else if (accuracyLayer) { map.removeLayer(accuracyLayer); accuracyLayer = null; }
    if (userLocationMarker) userLocationMarker.setLatLng(latlng);
    else userLocationMarker = L.marker(latlng, {
        interactive: false, zIndexOffset: 1000,
        icon: L.divIcon({ className: 'user-location-marker-wrapper', html: '<span class="user-location-dot"></span>', iconSize: [20, 20], iconAnchor: [10, 10] }),
    }).addTo(map);
};

const updateRouteLayer = (layer, coordinates, options) => {
    if (!Array.isArray(coordinates) || coordinates.length < 2) {
        if (layer && map.hasLayer(layer)) {
            map.removeLayer(layer);
        }
        return null;
    }

    const latLngs = coordinates.map(([longitude, latitude]) => [latitude, longitude]);
    if (layer) {
        layer.setLatLngs(latLngs);
        if (!map.hasLayer(layer)) {
            layer.addTo(map);
        }
        return layer;
    }

    return L.polyline(latLngs, options).addTo(map);
};

const renderFloorPlan = (data) => {
    if (!map) {
        return;
    }

    if (floorPlanLayer) {
        map.removeLayer(floorPlanLayer);
        floorPlanLayer = null;
    }

    if (!props.backgroundImage || !props.backgroundVisible) {
        return;
    }

    const buildingFeatures = (data.features ?? []).filter((feature) => (
        (feature.properties?.kind ?? feature.properties?.type) === 'building'
    ));
    if (buildingFeatures.length === 0) {
        return;
    }

    const bounds = L.geoJSON({ type: 'FeatureCollection', features: buildingFeatures }).getBounds();
    if (!bounds.isValid()
        || Math.abs(bounds.getWest()) > 180
        || Math.abs(bounds.getEast()) > 180
        || Math.abs(bounds.getSouth()) > 90
        || Math.abs(bounds.getNorth()) > 90) {
        return;
    }

    floorPlanLayer = L.imageOverlay(props.backgroundImage, bounds, {
        opacity: props.backgroundOpacity,
        interactive: false,
    }).addTo(map);
};

const renderRouteLayers = () => {
    if (!map) {
        return;
    }

    if (!props.routeVisible) {
        routeBaseLayer = updateRouteLayer(routeBaseLayer, [], {});
        routeCompletedLayer = updateRouteLayer(routeCompletedLayer, [], {});
        routeProgressLayer = updateRouteLayer(routeProgressLayer, [], {});
        return;
    }

    routeBaseLayer = updateRouteLayer(routeBaseLayer, props.routeGeometry, {
        pane: 'route', interactive: false, color: '#fff', weight: 10, opacity: 0.95, lineCap: 'round', lineJoin: 'round',
    });
    routeCompletedLayer = updateRouteLayer(routeCompletedLayer, props.completedRoute, {
        pane: 'route', interactive: false, color: '#94a3b8', weight: 6, opacity: 0.8, lineCap: 'round', lineJoin: 'round',
    });
    routeProgressLayer = updateRouteLayer(routeProgressLayer, props.remainingRoute, {
        pane: 'route', interactive: false, color: '#2563eb', weight: 6, opacity: 1, lineCap: 'round', lineJoin: 'round',
    });
};

const renderMapData = () => {
    if (!map || !mapContainer.value) {
        return;
    }

    const data = props.geojson && Array.isArray(props.geojson.features) ? props.geojson : defaultData();
    renderFloorPlan(data);

    if (geoJsonLayer) {
        map.removeLayer(geoJsonLayer);
        geoJsonLayer = null;
    }

    geoJsonLayer = L.geoJSON(data, {
        filter: (feature) => !['building', 'place-access'].includes(feature?.properties?.kind ?? feature?.properties?.type),
        style: (feature) => featureStyle(feature),
        pointToLayer: (feature, latlng) => L.circleMarker(latlng, pointStyle(feature)),
        onEachFeature: (feature, layer) => {
            layer.feature = feature;

            if (feature.properties?.kind !== 'place') return;
            if (feature.properties?.name) {
                const label = document.createElement('span');
                label.textContent = feature.properties.name;
                layer.bindTooltip(label, { direction: 'top', opacity: 1, className: 'wayfind-tooltip' });
            }
            layer.on('mouseover', () => { if (layer.setStyle) layer.setStyle({ weight: 3 }); });
            layer.on('mouseout', () => { if (layer.setStyle) layer.setStyle(featureStyle(feature)); });
            layer.on('click', () => {
                if (feature.properties?.id) emit('place-selected', { ...feature.properties });
            });
        },
    }).addTo(map);

    renderUserLocation();
    renderRouteLayers();
    renderDestination();

    const bounds = L.geoJSON(data).getBounds();
    if (bounds && bounds.isValid()) {
        const indoor = isIndoorData(data);
        if (indoor) {
            map.fitBounds([[0, 0], [1200, 800]], { padding: [24, 24] });
            map.setView([400, 300], 1);
            return;
        }

        map.fitBounds(bounds.pad(0.15), { ...cameraOptions(), maxZoom: 18.5, animate: false });
    }
};

const initMap = () => {
    if (!mapContainer.value) {
        return;
    }

    const indoor = isIndoorData(props.geojson);
    map = L.map(mapContainer.value, {
        zoomControl: false,
        zoomAnimation: !reducedMotion(),
        fadeAnimation: !reducedMotion(),
        markerZoomAnimation: !reducedMotion(),
        attributionControl: true,
        maxZoom: 24,
        zoomSnap: 0.1,
        zoomDelta: 0.5,
        wheelPxPerZoomLevel: 120,
        crs: indoor ? L.CRS.Simple : L.CRS.EPSG3857,
    });

    map.createPane('accuracy').style.zIndex = 390;
    map.createPane('route').style.zIndex = 450;
    map.attributionControl.setPrefix(false);
    map.on('dragstart', onManualInteraction);
    mapContainer.value.addEventListener('wheel', onManualInteraction, { passive: true });
    mapContainer.value.addEventListener('touchstart', onTouchStart, { passive: true });
    mapContainer.value.addEventListener('keydown', onManualInteraction);

    if (!indoor) {
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 24,
            maxNativeZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(map);
    }

    map.setView(indoor ? [400, 300] : [0, 0], indoor ? 1 : 2);
    requestAnimationFrame(() => {
        map.invalidateSize();
    });
    renderMapData();
};

onMounted(() => {
    initMap();
});

watch(
    () => props.geojson,
    () => {
        if (map) {
            renderMapData();
        }
    },
    { deep: true },
);

watch(
    () => props.selectedPlace,
    () => {
        if (!map || !geoJsonLayer) {
            return;
        }

        geoJsonLayer.eachLayer((layer) => {
            if (layer.setStyle) layer.setStyle(featureStyle(layer.feature));
            if (layer.setRadius) layer.setRadius(pointStyle(layer.feature).radius);
        });
        renderDestination();
    },
    { deep: true },
);

watch(
    () => props.userLocation,
    () => {
        if (!map) {
            return;
        }

        renderUserLocation();
        if (props.navigationActive && props.followUser && Date.now() > overviewUntil) focusLocation();
    },
    { deep: true },
);

watch(
    () => [
        props.routeGeometry,
        props.completedRoute,
        props.remainingRoute,
        props.routeVisible,
    ],
    () => {
        if (map) {
            renderRouteLayers();
        }
    },
    { deep: true },
);

watch(
    () => [props.backgroundImage, props.backgroundVisible, props.backgroundOpacity],
    () => {
        if (map) {
            renderFloorPlan(props.geojson && Array.isArray(props.geojson.features) ? props.geojson : defaultData());
        }
    },
    { deep: true },
);

watch(() => props.navigationActive, () => {
    geoJsonLayer?.eachLayer(layer => { if (layer.setStyle) layer.setStyle(featureStyle(layer.feature)); });
});

const fitMap = () => {
    const bounds = L.geoJSON(props.geojson ?? defaultData()).getBounds();
    if (bounds.isValid()) map?.fitBounds(bounds.pad(0.15), { ...cameraOptions(), maxZoom: 18.5, animate: false });
};

defineExpose({
    fitMap,
    centerOnUserLocation,
    frameRoute,
    focusSelectedPlace,
    zoomIn: () => { onManualInteraction(); map?.zoomIn(); },
    zoomOut: () => { onManualInteraction(); map?.zoomOut(); },
    invalidateSize: () => map?.invalidateSize({ pan: false }),
});

onBeforeUnmount(() => {
    mapContainer.value?.removeEventListener('wheel', onManualInteraction);
    mapContainer.value?.removeEventListener('touchstart', onTouchStart);
    mapContainer.value?.removeEventListener('keydown', onManualInteraction);
    if (map) {
        map.remove();
        map = null;
    }
});
</script>

<template>
    <div class="h-full w-full overflow-hidden bg-[#eeeee9]" :style="{ '--map-overlay-bottom': `${bottomOverlayHeight + 4}px` }">
        <div ref="mapContainer" class="h-full w-full"></div>
    </div>
</template>

<style scoped>
:global(.user-location-marker-wrapper), :global(.wayfind-destination) { background: transparent; border: none; }
:global(.user-location-dot) { display: block; width: 20px; height: 20px; border-radius: 50%; border: 3px solid #fff; background: #2563eb; box-shadow: 0 1px 5px #1e3a5f66; }
:global(.wayfind-tooltip) { padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 6px; color: #202124; box-shadow: 0 2px 8px #1523380d; font-family: inherit; }
:global(.leaflet-container) { font-family: inherit; }
:deep(.leaflet-bottom.leaflet-right) { bottom: var(--map-overlay-bottom); }
:global(.leaflet-control-attribution) { font-size: 9px; background: #ffffffd9; }
</style>
