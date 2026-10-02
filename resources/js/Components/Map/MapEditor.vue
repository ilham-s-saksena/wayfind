<script setup>
import * as maplibregl from 'maplibre-gl';
import MapboxDraw from 'maplibre-gl-draw';
import 'maplibre-gl/dist/maplibre-gl.css';
import 'maplibre-gl-draw/dist/mapbox-gl-draw.css';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

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
});

const emit = defineEmits(['change', 'feature-selected']);
const mapContainer = ref(null);
const editorBackgroundStyle = computed(() => ({
    backgroundImage: props.backgroundImage ? `url(${props.backgroundImage})` : 'none',
    opacity: props.backgroundVisible ? props.backgroundOpacity : 0,
    display: props.backgroundImage ? 'block' : 'none',
}));

let map = null;
let draw = null;

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

const syncMapData = () => {
    if (!map || !map.isStyleLoaded()) {
        return;
    }

    const data = normalizeMapData(props.mapData);

    if (map.getSource('wayfind-editor')) {
        map.getSource('wayfind-editor').setData(data);
    } else {
        map.addSource('wayfind-editor', {
            type: 'geojson',
            data,
        });

        map.addLayer({
            id: 'editor-fill',
            type: 'fill',
            source: 'wayfind-editor',
            paint: {
                'fill-color': props.mode === 'geo' ? '#0ea5e9' : '#4f46e5',
                'fill-opacity': 0.24,
            },
        });

        map.addLayer({
            id: 'editor-line',
            type: 'line',
            source: 'wayfind-editor',
            paint: {
                'line-color': props.mode === 'geo' ? '#0284c7' : '#3730a3',
                'line-width': 2,
            },
        });
    }

    if (draw) {
        draw.set(data);
    }
};

const emitDrawData = () => {
    if (!draw) {
        return;
    }

    const payload = normalizeMapData(draw.getAll());
    emit('change', payload);
};

const emitSelection = (feature) => {
    if (feature) {
        emit('feature-selected', feature);
    }
};

onMounted(() => {
    map = new maplibregl.Map({
        container: mapContainer.value,
        style: 'https://demotiles.maplibre.org/style.json',
        center: props.mapCenter,
        zoom: props.mapZoom,
        attributionControl: false,
    });

    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

    map.on('load', () => {
        syncMapData();

        draw = new MapboxDraw({
            displayControlsDefault: false,
            controls: {
                polygon: true,
                line_string: true,
                point: true,
                trash: true,
            },
            defaultMode: 'draw_polygon',
        });

        map.addControl(draw, 'top-left');
        draw.set(normalizeMapData(props.mapData));

        map.on('click', 'editor-fill', (event) => {
            const feature = event.features && event.features[0] ? event.features[0] : null;
            emitSelection(feature);
        });

        draw.on('draw.selectionchange', (event) => {
            emitSelection(event.features && event.features[0] ? event.features[0] : null);
        });
        draw.on('draw.create', emitDrawData);
        draw.on('draw.update', emitDrawData);
        draw.on('draw.delete', emitDrawData);
    });
});

watch(
    () => props.mapData,
    () => {
        if (map && map.isStyleLoaded()) {
            syncMapData();
        }
    },
    { deep: true },
);

watch(
    () => props.backgroundImage,
    () => {
        if (map && map.isStyleLoaded()) {
            syncMapData();
        }
    },
);

onBeforeUnmount(() => {
    if (map) {
        map.remove();
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
