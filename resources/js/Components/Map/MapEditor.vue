<script setup>
import L from 'leaflet';
import '@geoman-io/leaflet-geoman-free';
import '@geoman-io/leaflet-geoman-free/dist/leaflet-geoman.css';
import 'leaflet/dist/leaflet.css';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

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
    drawMode: {
        type: String,
        default: 'mixed',
    },
    readOnlyFeatureKinds: {
        type: Array,
        default: () => [],
    },
    selectedFeatureId: {
        type: [String, Number],
        default: null,
    },
    selectedAccessNodeId: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['change', 'feature-selected', 'access-node-selected']);
const mapContainer = ref(null);
let map = null;
let geoJsonLayer = null;
let navigationEndpointLayer = null;
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

const buildingFeatures = (data = props.mapData) => (data?.features ?? []).filter((feature) => (
    (feature.properties?.kind ?? feature.properties?.type) === 'building'
));

const hasGeographicBuildingGeometry = (data = props.mapData) => buildingFeatures(data).some((feature) => {
    const bounds = L.geoJSON(feature).getBounds();

    return bounds.isValid()
        && Math.abs(bounds.getWest()) <= 180
        && Math.abs(bounds.getEast()) <= 180
        && Math.abs(bounds.getSouth()) <= 90
        && Math.abs(bounds.getNorth()) <= 90;
});

const isIndoorMap = (data = props.mapData) => props.mode === 'indoor' && !hasGeographicBuildingGeometry(data);

const buildStyle = (feature) => {
    const kind = feature?.properties?.kind ?? feature?.properties?.type ?? 'feature';
    const isGeo = props.mode === 'geo';

    if (kind === 'navigation') {
        if (props.selectedFeatureId !== null && String(feature?.id) === String(props.selectedFeatureId)) {
            return { color: '#ea580c', weight: 6, opacity: 1, lineCap: 'round', lineJoin: 'round' };
        }

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

    const sourceFeatures = buildingFeatures();
    const buildingLayer = sourceFeatures.length ? L.geoJSON({ type: 'FeatureCollection', features: sourceFeatures }) : null;
    const bounds = hasGeographicBuildingGeometry()
        ? buildingLayer.getBounds()
        : [[0, 0], [1200, 800]];

    backgroundLayer = L.imageOverlay(props.backgroundImage, bounds, {
        opacity: props.backgroundVisible ? props.backgroundOpacity : 0,
        interactive: false,
    });
    backgroundLayer.addTo(map);
};

const renderNavigationEndpoints = (data = normalizeMapData()) => {
    if (!map) {
        return;
    }

    if (navigationEndpointLayer) {
        map.removeLayer(navigationEndpointLayer);
        navigationEndpointLayer = null;
    }

    const endpointNodes = new Map();
    data.features.forEach((feature) => {
        const properties = feature.properties ?? {};
        const coordinates = feature.geometry?.coordinates ?? [];
        if ((properties.kind ?? properties.type) !== 'navigation' || feature.geometry?.type !== 'LineString' || coordinates.length < 2) {
            return;
        }

        [[properties.start_node_id, coordinates[0]], [properties.end_node_id, coordinates.at(-1)]].forEach(([nodeId, coordinate]) => {
            if (!nodeId || !Array.isArray(coordinate)) return;
            const node = endpointNodes.get(nodeId) ?? { coordinate, degree: 0 };
            node.degree++;
            endpointNodes.set(nodeId, node);
        });
    });
    navigationEndpointLayer = L.layerGroup();
    endpointNodes.forEach(({ coordinate, degree }, nodeId) => {
        const connected = degree > 1;
        const selected = String(nodeId) === String(props.selectedAccessNodeId);
        const marker = L.circleMarker([coordinate[1], coordinate[0]], {
            radius: selected ? 9 : connected ? 6 : 7,
            color: selected ? '#4338ca' : connected ? '#0f766e' : '#b45309',
            fillColor: selected ? '#c7d2fe' : connected ? '#ccfbf1' : '#fffbeb',
            fillOpacity: 1,
            weight: selected ? 3 : 2,
            interactive: props.drawMode === 'place',
            bubblingMouseEvents: false,
            pmIgnore: true,
        });

        if (props.drawMode === 'place') {
            marker.bindTooltip(selected ? 'Selected destination access point' : connected ? 'Connected junction · click to select' : 'Path endpoint · click to select', { direction: 'top' });
            marker.on('click', () => emit('access-node-selected', String(nodeId)));
        }

        marker.addTo(navigationEndpointLayer);
    });
    navigationEndpointLayer.addTo(map);
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
            const kind = feature?.properties?.kind ?? feature?.properties?.type ?? '';
            const isReadOnly = (props.readOnlyFeatureKinds ?? []).includes(kind);

            if (isReadOnly) {
                layer.options.pmIgnore = true;
                if (typeof layer.pm?.disable === 'function') {
                    layer.pm.disable();
                }
            }

            if (feature?.properties?.name) {
                layer.bindTooltip(feature.properties.name, { direction: 'top', opacity: 0.9 });
            }

            layer.on('click', () => {
                emit('feature-selected', feature);
            });

            layer.feature = feature;
        },
    });

    applyBackground();
    geoJsonLayer.addTo(map);

    renderNavigationEndpoints(data);

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

    const addLayerFeature = (layer) => {
        if (layer instanceof L.LayerGroup) {
            layer.eachLayer(addLayerFeature);
            return;
        }

        if (layer === backgroundLayer || layer === navigationEndpointLayer || layer instanceof L.TileLayer || layer instanceof L.ImageOverlay) {
            return;
        }

        const sourceFeature = layer.feature;
        if (!sourceFeature) {
            return;
        }

        const currentFeature = typeof layer.toGeoJSON === 'function' ? layer.toGeoJSON() : sourceFeature;
        currentFeature.id = sourceFeature.id ?? sourceFeature.properties?.id;
        currentFeature.properties = { ...(sourceFeature.properties ?? {}), ...(currentFeature.properties ?? {}) };
        addFeature(currentFeature);
    };

    map.eachLayer(addLayerFeature);

    return {
        type: 'FeatureCollection',
        features,
    };
};

const getFeatureKind = () => {
    if (props.drawMode === 'navigation') {
        return 'navigation';
    }

    return props.featureKind ?? 'feature';
};

const getFeatureType = () => {
    if (props.drawMode === 'navigation') {
        return 'navigation';
    }

    return props.featureType ?? getFeatureKind();
};

const getDrawControls = () => {
    if (props.drawMode === 'navigation') {
        return {
            position: 'topleft',
            drawCircle: false,
            drawRectangle: false,
            drawCircleMarker: false,
            drawMarker: false,
            drawPolygon: false,
            drawPolyline: true,
            editMode: true,
            removalMode: true,
        };
    }

    if (props.drawMode === 'place') {
        return {
            position: 'topleft',
            drawCircle: false,
            drawRectangle: false,
            drawCircleMarker: false,
            drawMarker: true,
            drawPolygon: true,
            drawPolyline: false,
            editMode: true,
            removalMode: true,
        };
    }

    return {
        position: 'topleft',
        drawCircle: false,
        drawRectangle: false,
        drawCircleMarker: false,
        drawMarker: true,
        drawPolygon: true,
        drawPolyline: true,
        editMode: true,
        removalMode: true,
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

    map.pm.removeControls();
    map.pm.addControls(getDrawControls());

    map.on('pm:create', (event) => {
        const feature = event.layer.toGeoJSON();
        if (!feature?.type) {
            return;
        }

        const featureKind = getFeatureKind();
        const featureType = getFeatureType();

        feature.id = feature.id ?? `feature-${Date.now()}-${Math.random()}`;
        feature.properties = {
            ...(feature.properties ?? {}),
            name: feature.properties?.name ?? 'New feature',
            kind: feature.properties?.kind ?? featureKind,
            type: feature.properties?.type ?? featureType,
        };

        if (featureKind === 'navigation' && feature.geometry?.type === 'LineString') {
            feature.properties.start_node_id = crypto.randomUUID();
            feature.properties.end_node_id = crypto.randomUUID();
        }

        event.layer.feature = feature;
        emit('change', collectFeatures());
    });

    map.on('pm:remove', (event) => {
        event.layer.feature = null;
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
    () => props.drawMode,
    () => {
        if (!ready || !map) {
            return;
        }

        map.pm.removeControls();
        map.pm.addControls(getDrawControls());
        renderNavigationEndpoints();
    },
);

watch(
    () => props.selectedFeatureId,
    () => {
        if (!geoJsonLayer) return;
        geoJsonLayer.eachLayer((layer) => {
            if (typeof layer.setStyle === 'function' && layer.feature) {
                layer.setStyle(buildStyle(layer.feature));
            }
        });
    },
);

watch(
    () => props.selectedAccessNodeId,
    () => {
        if (map) {
            renderNavigationEndpoints();
        }
    },
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
    </div>
</template>
