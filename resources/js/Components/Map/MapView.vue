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
});

const emit = defineEmits(['place-selected']);
const mapContainer = ref(null);
let map = null;
let geoJsonLayer = null;

const defaultData = () => ({
    type: 'FeatureCollection',
    features: [],
});

const isOutdoorFeature = (feature) => {
    const geometry = feature?.geometry;
    if (!geometry || !Array.isArray(geometry.coordinates)) {
        return false;
    }

    const numbers = [];
    const walk = (value) => {
        if (!Array.isArray(value)) {
            return;
        }

        value.forEach((item) => {
            if (Array.isArray(item)) {
                walk(item);
                return;
            }

            if (typeof item === 'number') {
                numbers.push(item);
            }
        });
    };

    walk(geometry.coordinates);

    if (numbers.length === 0) {
        return false;
    }

    return numbers.some((value) => Math.abs(value) <= 180 && value !== 0);
};

const isIndoorData = (data = props.geojson) => {
    if (!data || !Array.isArray(data.features)) {
        return false;
    }

    if (data.features.some((feature) => isOutdoorFeature(feature))) {
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

onBeforeUnmount(() => {
    if (map) {
        map.remove();
        map = null;
    }
});
</script>

<template>
    <div class="h-[460px] w-full overflow-hidden rounded-2xl border border-slate-200 bg-slate-100">
        <div ref="mapContainer" class="h-full w-full"></div>
    </div>
</template>
