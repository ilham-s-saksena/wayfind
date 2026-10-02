<script setup>
import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
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

const buildFallbackGeoJson = () => ({
    type: 'FeatureCollection',
    features: [
        {
            type: 'Feature',
            properties: { kind: 'floor' },
            geometry: {
                type: 'Polygon',
                coordinates: [
                    [
                        [0, 0],
                        [1200, 0],
                        [1200, 800],
                        [0, 800],
                        [0, 0],
                    ],
                ],
            },
        },
    ],
});

const applyMapData = () => {
    if (!map || !mapContainer.value) {
        return;
    }

    const data = props.geojson && props.geojson.features?.length ? props.geojson : buildFallbackGeoJson();

    if (map.getSource('wayfind-floor')) {
        map.getSource('wayfind-floor').setData(data);
    } else {
        map.addSource('wayfind-floor', {
            type: 'geojson',
            data,
        });

        map.addLayer({
            id: 'floor-fill',
            type: 'fill',
            source: 'wayfind-floor',
            paint: {
                'fill-color': '#f3f4f6',
                'fill-outline-color': '#d1d5db',
                'fill-opacity': 0.9,
            },
            filter: ['==', ['get', 'kind'], 'floor'],
        });

        map.addLayer({
            id: 'floor-outline',
            type: 'line',
            source: 'wayfind-floor',
            paint: {
                'line-color': '#cbd5e1',
                'line-width': 2,
            },
            filter: ['==', ['get', 'kind'], 'floor'],
        });

        map.addLayer({
            id: 'place-points',
            type: 'circle',
            source: 'wayfind-floor',
            paint: {
                'circle-radius': 12,
                'circle-color': '#4f46e5',
                'circle-stroke-width': 2,
                'circle-stroke-color': '#ffffff',
            },
            filter: ['==', ['geometry-type'], 'Point'],
        });

        map.on('click', 'place-points', (event) => {
            const feature = event.features?.[0];
            if (!feature || !feature.properties) {
                return;
            }

            const place = {
                id: feature.properties.id,
                name: feature.properties.name,
                slug: feature.properties.slug,
                type: feature.properties.type,
                description: feature.properties.description,
            };

            emit('place-selected', place);
        });
    }

    const bounds = new maplibregl.LngLatBounds();
    for (const feature of data.features) {
        if (feature.geometry.type === 'Point') {
            const [x, y] = feature.geometry.coordinates;
            const coords = [x / 160, y / 140];
            bounds.extend(coords);
        }
        if (feature.geometry.type === 'Polygon') {
            for (const ring of feature.geometry.coordinates) {
                for (const [x, y] of ring) {
                    bounds.extend([x / 160, y / 140]);
                }
            }
        }
    }

    if (!bounds.isEmpty()) {
        map.fitBounds(bounds, {
            padding: 40,
            maxZoom: 18,
        });
    }
};

onMounted(() => {
    map = new maplibregl.Map({
        container: mapContainer.value,
        style: 'https://demotiles.maplibre.org/style.json',
        center: [0, 0],
        zoom: 14,
        attributionControl: false,
    });

    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.on('load', applyMapData);
});

watch(
    () => props.geojson,
    () => {
        if (map && map.isStyleLoaded()) {
            applyMapData();
        }
    },
    { deep: true },
);

watch(
    () => props.selectedPlace,
    () => {
        if (!map || !map.isStyleLoaded()) {
            return;
        }

        if (props.selectedPlace) {
            map.getSource('wayfind-floor')?.setData({
                ...props.geojson,
                features: props.geojson.features.map((feature) => {
                    if (feature.properties?.id === props.selectedPlace.id) {
                        return {
                            ...feature,
                            properties: {
                                ...feature.properties,
                                isSelected: true,
                            },
                        };
                    }

                    return {
                        ...feature,
                        properties: {
                            ...feature.properties,
                            isSelected: false,
                        },
                    };
                }),
            });
        }
    },
    { deep: true },
);

onBeforeUnmount(() => {
    if (map) {
        map.remove();
    }
});
</script>

<template>
    <div class="h-[460px] w-full overflow-hidden rounded-2xl border border-slate-200 bg-slate-100">
        <div ref="mapContainer" class="h-full w-full"></div>
    </div>
</template>
