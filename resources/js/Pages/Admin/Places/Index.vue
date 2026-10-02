<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    floor: {
        type: Object,
        required: true,
    },
    places: {
        type: Array,
        default: () => [],
    },
});

const destroy = (place) => {
    if (confirm(`Delete ${place.name}?`)) {
        router.delete(route('admin.floors.places.destroy', [props.floor.id, place.id]));
    }
};
</script>

<template>
    <Head :title="`${floor.name} places`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="text-sm font-medium uppercase tracking-wide text-indigo-600">Floor</div>
                    <h2 class="text-xl font-semibold text-gray-800">{{ floor.name }}</h2>
                </div>
                <div class="flex gap-2">
                    <Link :href="route('admin.buildings.show', floor.building_id)">
                        <SecondaryButton type="button">Back to building</SecondaryButton>
                    </Link>
                    <Link :href="route('admin.floors.places.create', floor.id)">
                        <PrimaryButton>Add place</PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-sm font-medium uppercase tracking-wide text-gray-600">Name</th>
                                <th class="px-4 py-3 text-sm font-medium uppercase tracking-wide text-gray-600">Type</th>
                                <th class="px-4 py-3 text-sm font-medium uppercase tracking-wide text-gray-600">Searchable</th>
                                <th class="px-4 py-3 text-sm font-medium uppercase tracking-wide text-gray-600">Status</th>
                                <th class="px-4 py-3 text-right text-sm font-medium uppercase tracking-wide text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="place in places" :key="place.id">
                                <td class="px-4 py-4">
                                    <div class="font-medium text-gray-900">{{ place.name }}</div>
                                    <div class="text-sm text-gray-500">{{ place.slug }}</div>
                                </td>
                                <td class="px-4 py-4 text-sm text-gray-600">{{ place.type || 'other' }}</td>
                                <td class="px-4 py-4 text-sm">
                                    <span :class="place.is_searchable ? 'text-emerald-700' : 'text-gray-500'">
                                        {{ place.is_searchable ? 'Yes' : 'No' }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-sm">
                                    <span :class="place.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-700'" class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium">
                                        {{ place.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link :href="route('admin.floors.places.edit', [floor.id, place.id])">
                                            <SecondaryButton type="button">Edit</SecondaryButton>
                                        </Link>
                                        <DangerButton type="button" @click="destroy(place)">Delete</DangerButton>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="places.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">
                                    No places defined for this floor yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
