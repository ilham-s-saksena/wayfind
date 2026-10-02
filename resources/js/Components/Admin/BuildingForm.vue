<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import MapEditor from '@/Components/Map/MapEditor.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    building: {
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
});

const emit = defineEmits(['submit']);

const form = props.building
    ? {
          name: props.building.name,
          slug: props.building.slug,
          description: props.building.description ?? '',
          geojson: props.building.geojson ?? {
              type: 'FeatureCollection',
              features: [],
          },
          is_active: props.building.is_active,
      }
    : {
          name: '',
          slug: '',
          description: '',
          geojson: {
              type: 'FeatureCollection',
              features: [],
          },
          is_active: true,
      };
</script>

<template>
    <form class="space-y-6" @submit.prevent="emit('submit', form)">
        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <InputLabel for="name" value="Building name" />
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
                    placeholder="gedung-utama"
                />
            </div>
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
                    <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Building footprint</p>
                    <p class="mt-1 text-sm text-slate-600">Draw the actual building outline on the map. The polygon will be saved as geographic coordinates.</p>
                </div>
            </div>
            <MapEditor
                :map-data="form.geojson"
                mode="geo"
                :map-center="[110.367, -7.795]"
                :map-zoom="12"
                @change="form.geojson = $event"
            />
        </div>

        <div class="flex items-center gap-3">
            <input
                id="is_active"
                v-model="form.is_active"
                type="checkbox"
                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
            />
            <label for="is_active" class="text-sm text-gray-700">Active</label>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="cancelRoute">
                <SecondaryButton type="button">Cancel</SecondaryButton>
            </Link>
            <PrimaryButton type="submit">{{ submitLabel }}</PrimaryButton>
        </div>
    </form>
</template>
