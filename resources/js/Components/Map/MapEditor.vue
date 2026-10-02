<script setup>
import L from 'leaflet';
import '@geoman-io/leaflet-geoman-free';
import '@geoman-io/leaflet-geoman-free/dist/leaflet-geoman.css';
import 'leaflet/dist/leaflet.css';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    mapData: {
        type: Object,
        default: () => ({
            type: 'FeatureCollection',
            features: [],
        }),
    },
    mode: {
        type: String,
        default: 'geo',
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
    mapCenter: {
        type: Array,
        default: () => [0, 0],
    },
    mapZoom: {
        type: Number,
        default: 2,
    },
    featureKind: {
        type: String,
        default: 'building',
    },
    featureType: {
        type: String,
        default: 'building',
    },
});

const emit = defineEmits(['change', 'feature-selected']);
const mapContainer = ref(null);
const editorBackgroundStyle = computed(() => ({
    backgroundImage: props.backgroundImage ? `url(${props.backgroundImage})` : 'none',
    opacity: props.backgroundVisible ? props.backgroundOpacity : 0,
    display: props.backgroundImage ? 'block' : 'none',
}));

let map = null;
let geoJsonLayer = null;
let backgroundLayer = null;
let ready = false;

const normalizeMapData = (value = props.mapData) => {
    if (!value || !Array.isArray(value.features)) {
        return {
            type: 'FeatureCollection',
            features: [],
        };
    }

    return {
        type: 'FeatureCollection',
        features: value.features,
    };
};

const isIndoorMap = (value = props.mapData) => {
    if (!value || !Array.isArray(value.features)) {
        return props.mode === 'indoor';
    }

    const hasIndoorHint = value.features.some((feature) => {
        const propsList = feature?.properties ?? {};
        return propsList.kind === 'floor' || propsList.kind === 'navigation' || propsList.kind === 'room' || propsList.type === 'room' || propsList.type === 'navigation';
    });

    let maxAbs = 0;
    const walk = (input) => {
        if (Array.isArray(input)) {
            input.forEach(walk);
            return;
        }

        if (typeof input === 'number') {
            maxAbs = Math.max(maxAbs, Math.abs(input));
        }
    };

    value.features.forEach((feature) => {
        if (feature?.geometry?.coordinates) {
            walk(feature.geometry.coordinates);
        }
    });

    return props.mode === 'indoor' || hasIndoorHint || maxAbs > 180;
};

const buildStyle = (feature) => {
    const kind = feature?.properties?.kind ?? feature?.properties?.type ?? 'feature';
    const isGeo = props.mode === 'geo';

    if (kind === 'navigation') {
        return {
            color: '#14b8a6',
            weight: 3,
            opacity: 1,
            fillOpacity: 0.18,
        };
    }

    if (kind === 'room' || kind === 'corridor' || kind === 'poi') {
        return {
            color: isGeo ? '#0284c7' : '#4338ca',
            weight: 2,
            opacity: 1,
            fillOpacity: 0.22,
        };
    }

    return {
        color: isGeo ? '#0ea5e9' : '#4f46e5',
        weight: 2,
        opacity: 1,
        fillOpacity: 0.2,
    };
};

const buildPointOptions = (feature) => {
    const type = feature?.properties?.type ?? feature?.properties?.kind ?? 'point';

    return {
        radius: 8,
        color: type === 'navigation' ? '#14b8a6' : '#4f46e5',
        fillColor: type === 'navigation' ? '#2dd4bf' : '#818cf8',
        fillOpacity: 0.95,
        weight: 2,
    };
};

const applyBackground = () => {
    if (!map) {
        return;
    }

    if (backgroundLayer) {
        map.removeLayer(backgroundLayer);
        backgroundLayer = null;
    }

    if (!props.backgroundImage) {
        return;
    }

    backgroundLayer = L.imageOverlay(props.backgroundImage, [[0, 0], [1200, 800]], {
        opacity: props.backgroundVisible ? props.backgroundOpacity : 0,
        interactive: false,
    });
    backgroundLayer.addTo(map);
};

const renderGeoJson = () => {
    if (!map) {
        return;
    }

    const data = normalizeMapData(props.mapData);

    if (geoJsonLayer) {
        map.removeLayer(geoJsonLayer);
        geoJsonLayer = null;
    }

    geoJsonLayer = L.geoJSON(data, {
        style: (feature) => buildStyle(feature),
        pointToLayer: (feature, latlng) => L.circleMarker(latlng, buildPointOptions(feature)),
        onEachFeature: (feature, layer) => {
            if (feature?.properties?.name) {
                layer.bindTooltip(feature.properties.name, { direction: 'top', opacity: 0.9 });
            }

            layer.on('click', () => {
                emit('feature-selected', feature);
            });

            layer.feature = feature;
        },
    });

    geoJsonLayer.addTo(map);

    if (isIndoorMap(data)) {
        map.setView([400, 300], 1);
        map.fitBounds([[0, 0], [1200, 800]], { padding: [20, 20] });
        nextTick(() => {
            requestAnimationFrame(() => {
                map.invalidateSize();
            });
        });
        return;
    }

    const bounds = geoJsonLayer.getBounds();
    if (bounds && bounds.isValid()) {
        map.fitBounds(bounds.pad(0.2));
    }

    nextTick(() => {
        requestAnimationFrame(() => {
            map.invalidateSize();
        });
    });
};

const collectFeatures = () => {
    const features = [];
    const seen = new Set();

    const addFeature = (feature) => {
        if (!feature || !feature.type || feature.type !== 'Feature') {
            return;
        }

        const id = feature.id ?? feature.properties?.id ?? feature.properties?.name ?? `${Date.now()}-${Math.random()}`;
        if (seen.has(id)) {
            return;
        }

        seen.add(id);
        features.push(feature);
    };

    if (geoJsonLayer) {
        geoJsonLayer.eachLayer((layer) => {
            if (layer.feature) {
                addFeature(layer.feature);
            }
        });
    }

    map.eachLayer((layer) => {
        if (layer === geoJsonLayer || layer === backgroundLayer) {
            return;
        }

        if (layer instanceof L.TileLayer || layer instanceof L.ImageOverlay) {
            return;
        }

        if (layer.feature) {
            addFeature(layer.feature);
        }
    });

    return {
        type: 'FeatureCollection',
        features,
    };
};

const initMap = () => {
    if (!mapContainer.value) {
        return;
    }

    const indoor = isIndoorMap(props.mapData);

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

    nextTick(() => {
        requestAnimationFrame(() => {
            map.invalidateSize();
        });
    });

    map.pm.addControls({
        position: 'topleft',
        drawCircle: false,
        drawRectangle: false,
        drawCircleMarker: false,
        drawMarker: true,
        drawPolygon: true,
        drawPolyline: true,
        editMode: true,
        removalMode: true,
    });

    map.on('pm:create', (event) => {
        const feature = event.layer.toGeoJSON();
        if (!feature?.type) {
            return;
        }

        feature.id = feature.id ?? `feature-${Date.now()}-${Math.random()}`;
        feature.properties = {
            ...(feature.properties ?? {}),
            name: feature.properties?.name ?? 'New feature',
            kind: feature.properties?.kind ?? props.featureKind,
            type: feature.properties?.type ?? props.featureType,
        };

        event.layer.feature = feature;
        emit('change', collectFeatures());
    });

    map.on('pm:remove', () => {
        emit('change', collectFeatures());
    });

    map.on('pm:edit', () => {
        emit('change', collectFeatures());
    });

    ready = true;
};

onMounted(() => {
    initMap();
    renderGeoJson();
    applyBackground();
});

watch(
    () => props.mapData,
    () => {
        if (!ready || !map) {
            return;
        }

        renderGeoJson();
    },
    { deep: true },
);

watch(
    () => props.backgroundImage,
    () => {
        if (!ready || !map) {
            return;
        }

        applyBackground();
    },
);

watch(
    () => props.backgroundVisible,
    () => {
        if (!ready || !map || !backgroundLayer) {
            return;
        }

        backgroundLayer.setOpacity(props.backgroundVisible ? props.backgroundOpacity : 0);
    },
);

watch(
    () => props.backgroundOpacity,
    () => {
        if (!ready || !map || !backgroundLayer) {
            return;
        }

        backgroundLayer.setOpacity(props.backgroundVisible ? props.backgroundOpacity : 0);
    },
);

onBeforeUnmount(() => {
    if (map) {
        map.remove();
        map = null;
    }
});
</script>

<template>
    <div class="relative h-[540px] w-full overflow-hidden rounded-2xl border border-slate-200 bg-slate-100">
        <div ref="mapContainer" class="h-full w-full"></div>
        <div
            v-if="backgroundImage"
            class="pointer-events-none absolute inset-0 bg-contain bg-center bg-no-repeat"
            :style="editorBackgroundStyle"
        ></div>
    </div>
</template>
