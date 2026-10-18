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
});

const emit = defineEmits(['place-selected']);
const mapContainer = ref(null);
let map = null;
let geoJsonLayer = null;
let userLocationMarker = null;
let hasCenteredOnUserLocation = false;

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

    if (kind === 'building') {
        return {
            color: '#94a3b8',
            weight: 2,
            opacity: 1,
            fillColor: '#dfe7f4',
            fillOpacity: 0.72,
        };
    }

    if (kind === 'floor') {
        return {
            color: '#cbd5e1',
            weight: 2,
            opacity: 1,
            fillColor: '#f8fafc',
            fillOpacity: 0.96,
        };
    }

    if (kind === 'navigation') {
        return {
            color: '#14b8a6',
            weight: 3,
            opacity: 1,
            fillOpacity: 0.15,
        };
    }

    return {
        color: '#4f46e5',
        weight: 2,
        opacity: 1,
        fillColor: '#c7d2fe',
        fillOpacity: 0.38,
    };
};

const pointStyle = (feature) => ({
    radius: feature?.properties?.id === props.selectedPlace?.id ? 10 : 8,
    color: feature?.properties?.id === props.selectedPlace?.id ? '#0f172a' : '#4f46e5',
    fillColor: feature?.properties?.id === props.selectedPlace?.id ? '#0f172a' : '#818cf8',
    fillOpacity: 1,
    weight: 2,
});

const centerOnUserLocation = () => {
    if (!map || !isValidUserLocation(props.userLocation) || !isGeoMap()) {
        return;
    }

    hasCenteredOnUserLocation = true;
    map.flyTo([props.userLocation.latitude, props.userLocation.longitude], Math.max(map.getZoom(), 16), { animate: true });
};

const renderUserLocation = () => {
    if (!map || !isGeoMap()) {
        return;
    }

    if (userLocationMarker) {
        map.removeLayer(userLocationMarker);
        userLocationMarker = null;
    }

    if (!isValidUserLocation(props.userLocation)) {
        return;
    }

    const icon = L.divIcon({
        className: 'user-location-marker-wrapper',
        html: '<div class="user-location-marker"><span class="user-location-dot"></span></div>',
        iconSize: [22, 22],
        iconAnchor: [11, 11],
    });

    userLocationMarker = L.marker([props.userLocation.latitude, props.userLocation.longitude], {
        icon,
        zIndexOffset: 1000,
    }).addTo(map);
};

const renderMapData = () => {
    if (!map || !mapContainer.value) {
        return;
    }

    const data = props.geojson && Array.isArray(props.geojson.features) ? props.geojson : defaultData();

    if (geoJsonLayer) {
        map.removeLayer(geoJsonLayer);
        geoJsonLayer = null;
    }

    geoJsonLayer = L.geoJSON(data, {
        style: (feature) => featureStyle(feature),
        pointToLayer: (feature, latlng) => L.circleMarker(latlng, pointStyle(feature)),
        onEachFeature: (feature, layer) => {
            if (feature?.properties?.name) {
                layer.bindTooltip(feature.properties.name, { direction: 'top', opacity: 0.9 });
            }

            layer.on('click', () => {
                if (feature?.properties?.id) {
                    emit('place-selected', {
                        id: feature.properties.id,
                        name: feature.properties.name,
                        slug: feature.properties.slug,
                        type: feature.properties.type,
                        description: feature.properties.description,
                    });
                }
            });

            layer.feature = feature;
        },
    }).addTo(map);

    renderUserLocation();

    if (isValidUserLocation(props.userLocation) && !hasCenteredOnUserLocation && isGeoMap()) {
        centerOnUserLocation();
        return;
    }

    const bounds = geoJsonLayer.getBounds();
    if (bounds && bounds.isValid()) {
        const indoor = isIndoorData(data);
        if (indoor) {
            map.fitBounds([[0, 0], [1200, 800]], { padding: [24, 24] });
            map.setView([400, 300], 1);
            return;
        }

        map.fitBounds(bounds.pad(0.2), { maxZoom: 18 });
    }
};

const initMap = () => {
    if (!mapContainer.value) {
        return;
    }

    const indoor = isIndoorData(props.geojson);
    map = L.map(mapContainer.value, {
        zoomControl: true,
        attributionControl: false,
        crs: indoor ? L.CRS.Simple : L.CRS.EPSG3857,
    });

    if (!indoor) {
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
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
            const match = layer.feature?.properties?.id === props.selectedPlace?.id;

            if (layer.setStyle) {
                layer.setStyle({
                    color: match ? '#0f172a' : featureStyle(layer.feature).color,
                    fillColor: match ? '#0f172a' : featureStyle(layer.feature).fillColor,
                    fillOpacity: featureStyle(layer.feature).fillOpacity,
                });
            }

            if (layer.setRadius) {
                layer.setRadius(match ? 10 : 8);
                layer.setStyle({
                    color: match ? '#0f172a' : '#4f46e5',
                    fillColor: match ? '#0f172a' : '#818cf8',
                });
            }
        });
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

        if (isValidUserLocation(props.userLocation) && !hasCenteredOnUserLocation && isGeoMap()) {
            centerOnUserLocation();
        }
    },
    { deep: true },
);

defineExpose({
    centerOnUserLocation,
});

onBeforeUnmount(() => {
    if (map) {
        map.remove();
        map = null;
    }
});
</script>

<template>
    <div class="h-screen w-full overflow-hidden bg-slate-100">
        <div ref="mapContainer" class="h-full w-full"></div>
    </div>
</template>

<style scoped>
:global(.user-location-marker-wrapper) {
    background: transparent !important;
    border: none !important;
}

:global(.user-location-marker) {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: visible;
}

:global(.user-location-marker::before) {
    content: '';
    position: absolute;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(14, 165, 233, 0.2);
    animation: user-location-pulse 1.8s ease-out infinite;
}

:global(.user-location-dot) {
    position: relative;
    display: block;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    box-sizing: border-box;
    border-radius: 50%;
    background-color: #0ea5e9;
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px rgba(14, 165, 233, 0.35);
}

@keyframes user-location-pulse {
    0% {
        transform: scale(0.8);
        opacity: 0.8;
    }

    100% {
        transform: scale(2.2);
        opacity: 0;
    }
}
</style>
