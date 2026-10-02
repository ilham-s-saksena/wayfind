<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    buildings: {
        type: Array,
        default: () => [],
    },
});

const toggleStatus = (building) => {
    router.post(route('admin.buildings.toggle', building.id));
};

const destroy = (building) => {
    if (confirm(`Delete ${building.name}?`)) {
        router.delete(route('admin.buildings.destroy', building.id));
    }
};
</script>

<template>
    <Head title="Buildings" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">Buildings</h2>
                <Link :href="route('admin.buildings.create')">
                    <PrimaryButton>Add building</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-sm font-medium uppercase tracking-wide text-gray-600">Name</th>
                                <th class="px-4 py-3 text-sm font-medium uppercase tracking-wide text-gray-600">Slug</th>
                                <th class="px-4 py-3 text-sm font-medium uppercase tracking-wide text-gray-600">Floors</th>
                                <th class="px-4 py-3 text-sm font-medium uppercase tracking-wide text-gray-600">Status</th>
                                <th class="px-4 py-3 text-right text-sm font-medium uppercase tracking-wide text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="building in buildings" :key="building.id">
                                <td class="px-4 py-4">
                                    <Link :href="route('admin.buildings.show', building.id)" class="font-medium text-gray-900 hover:text-indigo-600">
                                        {{ building.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-4 text-sm text-gray-600">{{ building.slug }}</td>
                                <td class="px-4 py-4 text-sm text-gray-600">{{ building.floors?.length ?? 0 }}</td>
                                <td class="px-4 py-4 text-sm">
                                    <span
                                        :class="building.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-700'"
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
                                    >
                                        {{ building.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <SecondaryButton type="button" @click="toggleStatus(building)">
                                            {{ building.is_active ? 'Deactivate' : 'Activate' }}
                                        </SecondaryButton>
                                        <Link :href="route('admin.buildings.edit', building.id)">
                                            <SecondaryButton type="button">Edit</SecondaryButton>
                                        </Link>
                                        <DangerButton type="button" @click="destroy(building)">Delete</DangerButton>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="buildings.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">
                                    No buildings yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
