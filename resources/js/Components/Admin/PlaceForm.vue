<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import MapEditor from '@/Components/Map/MapEditor.vue';
import { computed, reactive, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    building: {
        type: Object,
        default: null,
    },
    floor: {
        type: Object,
        default: null,
    },
    place: {
        type: Object,
        default: null,
    },
    submitLabel: {
        type: String,
        default: 'Save',
    },
    cancelRoute: {
        type: String,
        default: '#',
    },
    errors: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(['submit']);

const normalizeGeometry = (value) => {
    if (!value || !Array.isArray(value.features)) {
        return {
            type: 'FeatureCollection',
            features: [],
        };
    }

    return {
        type: 'FeatureCollection',
        features: value.features.filter((feature) => feature
            && feature.properties?.kind !== 'building'
            && feature.properties?.type !== 'building'),
    };
};

const mapContext = computed(() => {
    const features = [];

    if (props.building?.geojson?.features) {
        props.building.geojson.features.forEach((feature) => {
            features.push({
                ...feature,
                properties: {
                    ...(feature.properties ?? {}),
                    kind: 'building',
                    type: 'building',
                },
            });
        });
    }

    if (props.floor?.map_data?.features) {
        props.floor.map_data.features.forEach((feature) => {
            features.push({
                ...feature,
                properties: {
                    ...(feature.properties ?? {}),
                    kind: feature.properties?.kind ?? 'floor',
                    type: feature.properties?.type ?? 'floor',
                },
            });
        });
    }

    if (form.geojson?.features) {
        form.geojson.features.forEach((feature) => {
            features.push({
                ...feature,
                properties: {
                    ...(feature.properties ?? {}),
                    kind: feature.properties?.kind ?? 'place',
                    type: feature.properties?.type ?? 'place',
                },
            });
        });
    }

    return {
        type: 'FeatureCollection',
        features,
    };
});

const form = reactive(props.place
    ? {
          name: props.place.name,
          slug: props.place.slug,
          type: props.place.type ?? 'room',
          description: props.place.description ?? '',
          access_node_id: props.place.access_node_id ?? '',
          geojson: normalizeGeometry(props.place.geojson),
          is_searchable: props.place.is_searchable,
          is_active: props.place.is_active,
      }
    : {
          name: '',
          slug: '',
          type: 'room',
          description: '',
          access_node_id: '',
          geojson: {
              type: 'FeatureCollection',
              features: [],
          },
          is_searchable: true,
          is_active: true,
      });

const navigationAccessNodes = computed(() => {
    const nodes = new Map();

    for (const feature of props.floor?.map_data?.features ?? []) {
        const properties = feature.properties ?? {};
        const coordinates = feature.geometry?.coordinates ?? [];
        if ((properties.kind ?? properties.type) !== 'navigation' || feature.geometry?.type !== 'LineString' || coordinates.length < 2) {
            continue;
        }

        [
            { id: properties.start_node_id, coordinate: coordinates[0], label: `${properties.name || 'Path'} · start` },
            { id: properties.end_node_id, coordinate: coordinates.at(-1), label: `${properties.name || 'Path'} · end` },
        ].forEach((node) => {
            if (node.id && !nodes.has(node.id)) {
                nodes.set(node.id, { ...node, coordinateLabel: `${Number(node.coordinate[0]).toFixed(6)}, ${Number(node.coordinate[1]).toFixed(6)}` });
            }
        });
    }

    return [...nodes.values()];
});

const sanitizePlaceGeojson = (value) => {
    if (!value || !Array.isArray(value.features)) {
        return {
            type: 'FeatureCollection',
            features: [],
        };
    }

    return {
        type: 'FeatureCollection',
        features: value.features.filter((feature) => {
            if (!feature) {
                return false;
            }

            const kind = feature.properties?.kind ?? feature.properties?.type ?? '';
            const geometryType = feature.geometry?.type;

            return kind !== 'navigation'
                && kind !== 'building'
                && feature.properties?.type !== 'building'
                && ['Point', 'Polygon', 'MultiPolygon'].includes(geometryType);
        }),
    };
};

const mapMode = computed(() => (props.floor?.background_image ? 'indoor' : 'geo'));
const validationMessage = ref('');
const serverErrorMessage = computed(() => {
    const errorValues = Object.values(props.errors ?? {}).filter((value) => typeof value === 'string' && value.trim() !== '');

    return errorValues[0] ?? '';
});

const onMapChange = (value) => {
    const cleaned = sanitizePlaceGeojson(value);
    form.geojson = cleaned;

    if (cleaned.features.length === 0) {
        validationMessage.value = 'Please draw the place on the map.';
        return;
    }

    validationMessage.value = '';
};

const selectAccessNode = (nodeId) => {
    form.access_node_id = nodeId;
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="emit('submit', form)">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Spatial context</p>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ building?.name || 'Building' }} · {{ floor?.name || 'Floor' }}
                    </p>
                </div>
                <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-medium text-indigo-700">
                    {{ floor ? 'Floor selected' : 'No floor selected' }}
                </span>
            </div>

            <div v-if="building?.geojson?.features?.length" class="rounded-lg border border-dashed border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                Building footprint detected. The place must remain within this boundary.
            </div>
            <div v-else class="rounded-lg border border-dashed border-slate-200 bg-white px-3 py-2 text-xs text-amber-700">
                Selected building does not yet have a geometry boundary. Please complete the building footprint before saving a place.
            </div>
        </div>

        <div>
            <InputLabel for="name" value="Place name" />
            <TextInput
                id="name"
                v-model="form.name"
                type="text"
                class="mt-1 block w-full"
                required
            />
        </div>

        <div>
            <InputLabel for="slug" value="Slug" />
            <TextInput
                id="slug"
                v-model="form.slug"
                type="text"
                class="mt-1 block w-full"
                placeholder="ruang-rapat"
            />
        </div>

        <div>
            <InputLabel for="type" value="Type" />
            <select
                id="type"
                v-model="form.type"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                <option value="room">Room</option>
                <option value="office">Office</option>
                <option value="studio">Studio</option>
                <option value="lobby">Lobby</option>
                <option value="toilet">Toilet</option>
                <option value="mosque">Mosque</option>
                <option value="parking">Parking</option>
                <option value="entrance">Entrance</option>
                <option value="facility">Facility</option>
                <option value="other">Other</option>
            </select>
        </div>

        <div>
            <InputLabel for="description" value="Description" />
            <textarea
                id="description"
                v-model="form.description"
                rows="4"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="mb-3 flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Place geometry</p>
                    <p class="mt-1 text-sm text-slate-600">Choose the marker tool for a precise destination or the polygon tool for a room boundary.</p>
                </div>
            </div>

            <MapEditor
                :map-data="mapContext"
                :mode="mapMode"
                :background-image="floor?.background_image || ''"
                :background-visible="!!floor?.background_image"
                :background-opacity="floor?.background_opacity ?? 0.5"
                :map-center="[0, 0]"
                :map-zoom="1"
                :read-only-feature-kinds="['building', 'navigation']"
                :selected-access-node-id="form.access_node_id"
                draw-mode="place"
                feature-kind="place"
                :feature-type="form.type || 'room'"
                @change="onMapChange"
                @access-node-selected="selectAccessNode"
            />

            <label class="mt-4 block text-sm font-medium text-slate-700">
                Navigation access point
                <select v-model="form.access_node_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">Not connected to navigation</option>
                    <option v-for="node in navigationAccessNodes" :key="node.id" :value="node.id">
                        {{ node.label }} · {{ node.coordinateLabel }}
                    </option>
                </select>
            </label>
            <p class="mt-2 text-xs text-slate-600">Click a visible navigation endpoint or choose one from the list. For a room without a nearby endpoint, first draw a path to its entrance in the Floor Editor. Routes use this selected access point instead of the room center.</p>
            <p v-if="form.access_node_id" class="mt-2 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs text-indigo-800">
                Access point selected: {{ navigationAccessNodes.find((node) => node.id === form.access_node_id)?.label || 'Choose an available endpoint' }}.
            </p>
            <p v-else class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                This place is not reachable from the public map until an access point is selected.
            </p>

            <div v-if="validationMessage" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                {{ validationMessage }}
            </div>
            <div v-else-if="serverErrorMessage" class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ serverErrorMessage }}
            </div>
        </div>

        <div class="space-y-2">
            <label class="flex items-center gap-3 text-sm text-gray-700">
                <input
                    v-model="form.is_searchable"
                    type="checkbox"
                    class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                />
                Searchable
            </label>
            <label class="flex items-center gap-3 text-sm text-gray-700">
                <input
                    v-model="form.is_active"
                    type="checkbox"
                    class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                />
                Active
            </label>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="cancelRoute">
                <SecondaryButton type="button">Cancel</SecondaryButton>
            </Link>
            <PrimaryButton type="submit">{{ submitLabel }}</PrimaryButton>
        </div>
    </form>
</template>
