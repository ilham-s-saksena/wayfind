<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import MapEditor from '@/Components/Map/MapEditor.vue';
import { computed, ref } from 'vue';
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
        features: value.features.filter((feature) => !!feature),
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

const form = props.place
    ? {
          name: props.place.name,
          slug: props.place.slug,
          type: props.place.type ?? 'room',
          description: props.place.description ?? '',
          geojson: normalizeGeometry(props.place.geojson),
          is_searchable: props.place.is_searchable,
          is_active: props.place.is_active,
      }
    : {
          name: '',
          slug: '',
          type: 'room',
          description: '',
          geojson: {
              type: 'FeatureCollection',
              features: [],
          },
          is_searchable: true,
          is_active: true,
      };

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
            if (!feature || !feature.properties) {
                return true;
            }

            return !['building', 'floor'].includes(feature.properties.kind ?? '') && !['building', 'floor'].includes(feature.properties.type ?? '');
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
                    <p class="mt-1 text-sm text-slate-600">Draw a point or polygon inside the selected building.</p>
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
                :read-only-feature-kinds="['navigation']"
                :draw-mode="'mixed'"
                feature-kind="place"
                :feature-type="form.type || 'room'"
                @change="onMapChange"
            />

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
