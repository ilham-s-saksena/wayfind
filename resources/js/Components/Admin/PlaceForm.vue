<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
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
});

const emit = defineEmits(['submit']);

const form = props.place
    ? {
          name: props.place.name,
          slug: props.place.slug,
          type: props.place.type ?? 'room',
          description: props.place.description ?? '',
          is_searchable: props.place.is_searchable,
          is_active: props.place.is_active,
      }
    : {
          name: '',
          slug: '',
          type: 'room',
          description: '',
          is_searchable: true,
          is_active: true,
      };
</script>

<template>
    <form class="space-y-6" @submit.prevent="emit('submit', form)">
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
